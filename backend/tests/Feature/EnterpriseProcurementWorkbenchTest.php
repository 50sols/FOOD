<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProcurementSecondApprover;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class EnterpriseProcurementWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticErpWorkflowEvidence::install();
        // Explicit test-only approval of the exact ERP module version; never a runtime default.
        config()->set('enterprise_module_readiness.certified_versions.procurement', app(ModuleRegistry::class)->manifest('procurement')->version);
    }

    public function test_workbench_fails_closed_while_procurement_module_is_disabled(): void
    {
        [$user, $store] = $this->operator('ERP-UI-DISABLED');

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id]))
            ->assertForbidden();
    }

    public function test_authorized_operator_can_run_request_to_issued_order_from_workbench(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-FLOW');

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'suppliers']))
            ->assertOk()
            ->assertSee('مركز عمل المشتريات')
            ->assertSee('طلبات الشراء')
            ->assertSee('أوامر الشراء');

        $this->actingAs($user)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'UI-SUP-1',
            'name_en' => 'UI Supplier',
            'name_ar' => 'مورد الواجهة',
            'payment_terms_days' => 30,
        ])->assertRedirect();

        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', 'UI-SUP-1')->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($user)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'Workbench lifecycle proof',
            'item_code' => 'UI-SKU-1',
            'description' => 'Workbench line',
            'quantity' => 3,
            'unit' => 'case',
            'estimated_unit_cost' => 5.250,
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')->where('reason', 'Workbench lifecycle proof')->value('id');
        $this->assertGreaterThan(0, $requestId);

        $this->actingAs($user)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', ['id' => $requestId, 'status' => 'submitted']);

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'requests']))
            ->assertOk()
            ->assertDontSee('name="decision" value="approve"', false);

        $this->actingAs($user)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Self approval is forbidden',
        ])->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('enterprise_purchase_requests', ['id' => $requestId, 'status' => 'submitted']);

        [$checker] = ProcurementSecondApprover::forSameStore(
            $user,
            app(TenantContextResolver::class)->retail($user, $store->id),
        );
        $this->grantPlatformErpAccess($checker);
        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Independent UI approval',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);

        $this->actingAs($user)->post(route('admin.erp.procurement.orders.create', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $orderId = (int) DB::table('enterprise_purchase_orders')->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $orderId);

        $this->actingAs($user)->post(route('admin.erp.procurement.orders.issue', $orderId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', ['id' => $orderId, 'status' => 'issued']);

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', ['store_id' => $store->id, 'tab' => 'orders']))
            ->assertOk()
            ->assertSee('UI-SUP-1')
            ->assertSee('مُصدر');
    }

    public function test_same_store_manager_cannot_submit_another_makers_purchase_request_in_both_locales(): void
    {
        $this->enableProcurement();
        [$maker, $store] = $this->operator('ERP-UI-MAKER-SUBMIT');
        $other = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $this->grantPlatformErpAccess($other);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;
        DB::table('user_store_roles')->insert([
            'user_id' => $other->id, 'store_id' => $store->id,
            'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $other->forceFill(['locale' => $locale])->save();
            $reason = 'Origin maker ownership '.$locale;
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'MAKER-SKU',
                'description' => 'Maker-owned item',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => 2.500,
            ])->assertRedirect();

            $requestId = (int) DB::table('enterprise_purchase_requests')->where('reason', $reason)->value('id');
            $this->assertGreaterThan(0, $requestId);
            $auditBefore = DB::table('audit_logs')->count();
            $this->actingAs($other)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertForbidden();
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $requestId, 'status' => 'draft', 'created_by' => $maker->id,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertRedirect();
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $requestId, 'status' => 'submitted', 'submitted_by' => $maker->id,
            ]);
            $auditAfterSubmit = DB::table('audit_logs')->count();
            $this->actingAs($other)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertForbidden();
            $this->assertSame($auditAfterSubmit, DB::table('audit_logs')->count());
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertRedirect();
            $this->assertSame($auditAfterSubmit, DB::table('audit_logs')->count());
        }
    }

    public function test_supplier_hold_between_preflight_and_write_denies_request_create_and_draft_update(): void
    {
        $this->enableProcurement();
        [$maker, $store] = $this->operator('ERP-UI-SUPPLIER-RECHECK');
        $armed = false;
        $supplierId = 0;
        $revocations = 0;

        // Simulate a supplier hold just after the optimistic active-supplier
        // SELECT returns, before create/update enters the write transaction.
        DB::listen(function (QueryExecuted $query) use (&$armed, &$supplierId, &$revocations): void {
            if (! $armed || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'enterprise_suppliers')) {
                return;
            }
            $armed = false;
            $revocations++;
            DB::table('enterprise_suppliers')->where('id', $supplierId)
                ->update(['status' => 'suspended']);
        });

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $code = 'ERP-SUP-RACE-'.strtoupper($locale);
            $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => $code,
                'name_en' => 'Race Supplier '.$locale,
                'name_ar' => 'مورد اختبار '.$locale,
            ])->assertRedirect();
            $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');
            $this->assertGreaterThan(0, $supplierId);
            $draftReason = 'Supplier race draft '.$locale;
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => $draftReason,
                'item_code' => 'RACE-SKU',
                'description' => 'Original draft line',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => 3.000,
            ])->assertRedirect();
            $draftId = (int) DB::table('enterprise_purchase_requests')
                ->where('reason', $draftReason)->value('id');
            $this->assertGreaterThan(0, $draftId);
            $payload = [
                'store_id' => $store->id,
                'supplier_id' => $supplierId,
                'currency_code' => 'KWD',
                'item_code' => 'RACE-SKU',
                'description' => 'Supplier-bound line',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => 3.000,
            ];
            $beforeAudit = DB::table('audit_logs')->count();
            $armed = true;
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'),
                $payload + ['purchase_request_id' => $draftId, 'reason' => $draftReason]
            )->assertNotFound();
            $this->assertFalse($armed, 'Supplier preflight must be exercised before locked rejection.');
            $this->assertDatabaseHas('enterprise_suppliers', ['id' => $supplierId, 'status' => 'suspended']);
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $draftId, 'status' => 'draft', 'supplier_id' => null,
                'reason' => $draftReason,
            ]);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

            DB::table('enterprise_suppliers')->where('id', $supplierId)->update(['status' => 'active']);
            $newReason = 'Supplier race create '.$locale;
            $armed = true;
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'),
                $payload + ['reason' => $newReason]
            )->assertNotFound();
            $this->assertFalse($armed);
            $this->assertDatabaseMissing('enterprise_purchase_requests', ['reason' => $newReason]);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

            // A genuinely reactivated supplier may be selected normally.
            DB::table('enterprise_suppliers')->where('id', $supplierId)->update(['status' => 'active']);
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'),
                $payload + ['purchase_request_id' => $draftId, 'reason' => $draftReason]
            )->assertRedirect();
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $draftId, 'supplier_id' => $supplierId,
            ]);
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'),
                $payload + ['reason' => $newReason]
            )->assertRedirect();
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'reason' => $newReason, 'supplier_id' => $supplierId,
            ]);
        }
        $this->assertSame(4, $revocations);
    }

    public function test_suspended_supplier_cannot_advance_maker_draft_to_submitted_in_both_locales(): void
    {
        $this->enableProcurement();
        [$maker, $store] = $this->operator('ERP-UI-SUPPLIER-SUBMIT');

        foreach (['ar', 'en'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $code = 'ERP-SUP-SUB-'.strtoupper($locale);
            $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => $code,
                'name_en' => 'Submit Supplier '.$locale,
                'name_ar' => 'مورد تسليم '.$locale,
            ])->assertRedirect();
            $supplierId = (int) DB::table('enterprise_suppliers')
                ->where('code', $code)->value('id');
            $this->assertGreaterThan(0, $supplierId);
            $reason = 'Supplier submit revocation '.$locale;
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'supplier_id' => $supplierId,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'SUP-SUB-SKU',
                'description' => 'Supplier lifecycle line',
                'quantity' => 2,
                'unit' => 'case',
                'estimated_unit_cost' => 1.500,
            ])->assertRedirect();
            $requestId = (int) DB::table('enterprise_purchase_requests')
                ->where('reason', $reason)->value('id');
            $this->assertGreaterThan(0, $requestId);
            DB::table('enterprise_suppliers')->where('id', $supplierId)
                ->update(['status' => 'suspended']);
            $auditBefore = DB::table('audit_logs')->count();

            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertNotFound();
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $requestId, 'status' => 'draft',
                'supplier_id' => $supplierId, 'submitted_by' => null,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('enterprise_suppliers')->where('id', $supplierId)
                ->update(['status' => 'active']);
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertRedirect();
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $requestId, 'status' => 'submitted',
                'supplier_id' => $supplierId, 'submitted_by' => $maker->id,
            ]);
            $auditAfter = DB::table('audit_logs')->count();
            $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertRedirect();
            $this->assertSame($auditAfter, DB::table('audit_logs')->count());
        }
    }

    public function test_supplier_tab_filters_literal_prefix_and_rejects_foreign_store_access(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-LITERAL');
        [, $foreignStore] = $this->operator('ERP-UI-LITERAL-FOREIGN');

        $this->actingAs($user)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'LITERAL-ONE',
            'name_en' => 'Deal%_! Supplier',
            'name_ar' => 'مورد%_! عربي',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'LITERAL-TWO',
            'name_en' => 'DealXYZ Supplier',
            'name_ar' => 'موردXYZ عربي',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $matching = (int) DB::table('enterprise_suppliers')->where('code', 'LITERAL-ONE')->value('id');
        $this->assertGreaterThan(0, $matching);
        $auditBefore = DB::table('audit_logs')->count();

        foreach ([
            ['q' => 'Deal%_!', 'locale' => 'en'],
            ['q' => 'مورد%_!', 'locale' => 'ar'],
        ] as $case) {
            $user->forceFill(['locale' => $case['locale']])->save();
            $page = $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
                'q' => $case['q'],
                'status' => 'active',
            ]))->assertOk()->viewData('rows');

            $this->assertSame([$matching], $page->getCollection()->pluck('id')->all());
            parse_str((string) parse_url((string) $page->url(2), PHP_URL_QUERY), $query);
            $this->assertSame($case['q'], $query['q'] ?? null);
        }

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $foreignStore->id,
                'tab' => 'suppliers',
                'q' => 'Deal%_!',
            ]))->assertForbidden();

        $unprivileged = User::factory()->create(['is_active' => true]);
        $this->actingAs($unprivileged)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
                'q' => 'Deal%_!',
            ]))->assertForbidden();
    }

    public function test_completed_procurement_tabs_render_inside_one_canonical_workbench(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-COMPLETE');

        foreach ([
            'overview' => 'نظرة عامة على المشتريات',
            'rfqs' => 'طلبات العروض والمناقصات',
            'contracts' => 'العقود',
            'matches' => 'المطابقة الثلاثية',
            'reports' => 'تقارير المشتريات',
        ] as $tab => $expected) {
            $this->actingAs($user)
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]))
                ->assertOk()
                ->assertSee($expected);
        }
    }

    public function test_store_selector_rejects_cross_store_scope(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('ERP-UI-A');
        [, $otherStore] = $this->operator('ERP-UI-B');

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $otherStore->id,
                'tab' => 'suppliers',
            ]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))
            ->assertOk();
    }

    public function test_p244_purchase_invoice_tab_is_bilingual_authorized_and_foreign_store_denied(): void
    {
        $this->enableProcurement();
        [$user, $store] = $this->operator('P244-UI-OWN');
        [, $foreign] = $this->operator('P244-UI-FOREIGN');
        $before = (int) DB::table('enterprise_purchase_invoices')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $this->actingAs($user->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'invoices',
                'store_id' => $store->id,
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('enterprise_procurement.tabs.invoices'));
        }

        $this->actingAs($user->fresh())->get(route('admin.erp.procurement.index', [
            'tab' => 'invoices',
            'store_id' => $foreign->id,
        ]))->assertForbidden();

        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->get(route('admin.erp.procurement.index', [
            'tab' => 'invoices',
            'store_id' => $store->id,
        ]))->assertForbidden();

        $this->assertSame($before, (int) DB::table('enterprise_purchase_invoices')->count());
    }

    public function test_full_procurement_workbench_route_surface_is_registered(): void
    {
        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.suppliers.store',
            'admin.erp.procurement.requests.store',
            'admin.erp.procurement.requests.submit',
            'admin.erp.procurement.requests.decision',
            'admin.erp.procurement.rfqs.store',
            'admin.erp.procurement.rfqs.publish',
            'admin.erp.procurement.rfqs.cancel',
            'admin.erp.procurement.rfqs.award',
            'admin.erp.procurement.rfqs.documents.download',
            'admin.erp.procurement.orders.create',
            'admin.erp.procurement.orders.issue',
            'admin.erp.procurement.orders.contract',
            'admin.erp.procurement.contracts.store',
            'admin.erp.procurement.contracts.activate',
            'admin.erp.procurement.contracts.terminate',
            'admin.erp.procurement.contracts.documents.store',
            'admin.erp.procurement.contracts.documents.download',
            'admin.erp.procurement.receipts.store',
            'admin.erp.procurement.receipts.post',
            'admin.erp.procurement.invoices.store',
            'admin.erp.procurement.invoices.handoff',
            'admin.erp.procurement.matches.review',
            'admin.erp.procurement.reports.export',
            'supplier.rfq.show',
            'supplier.rfq.submit',
            'admin.erp.procurement.returns.store',
            'admin.erp.procurement.returns.post',
        ] as $name) {
            $this->assertTrue(Route::has($name), 'Missing procurement UI route: '.$name);
        }
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    /** @return array{User,Store} */
    private function operator(string $storeCode): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = (int) DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $storeCode,
            'name' => $storeCode,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->firstOrCreate(
            ['code' => 'B2C_STORE_ADMIN'],
            [
                'name' => 'B2C Store Admin',
                'scope' => 'store',
                'is_active' => true,
                'is_system' => true,
            ],
        );

        foreach ($this->permissions() as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create([
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    /** @return list<string> */
    private function permissions(): array
    {
        return [
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.manage',
            'procurement.purchase_orders.issue',
            'procurement.goods_receipts.view',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
            'procurement.supplier_statements.view',
            'reports.view',
            'reports.export',
        ];
    }
}
