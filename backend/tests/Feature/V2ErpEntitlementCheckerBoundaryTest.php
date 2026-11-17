<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseInvoiceHandoffManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Catalog;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\EnterpriseModuleAdministration;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 P111: a global procurement role does not override an explicit store
 * entitlement denial, and restoring the entitlement does not bypass SoD.
 */
final class V2ErpEntitlementCheckerBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        // P135's signed test helper is unmerged on this independent P111 branch.
        // Install it only when present in the synthesized test checkout; never
        // allow test fixtures to become a runtime certification or a dependency.
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
    }

    public function test_store_entitlement_revocation_blocks_existing_maker_and_checker_http_workflows_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);
        $storeScope = new EntitlementScope(EntitlementScope::STORE, $store->id);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 entitlement and independent checker',
            'item_code' => 'P111-PROC-SKU',
            'description' => 'Store entitlement revocation acceptance',
            'quantity' => 2,
            'unit' => 'case',
            'estimated_unit_cost' => '5.000',
        ])->assertRedirect();

        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'P111 entitlement and independent checker')
            ->value('id');
        $this->assertGreaterThan(0, $requestId);

        $this->actingAs($buyer)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'submitted_by' => $buyer->id,
            'decided_by' => null,
        ]);

        // A store-specific DENY takes precedence over the platform grant,
        // including for a global checker and a platform Super Admin.
        $entitlements->set('procurement', $storeScope, false, null, $admin);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => $storeScope->key(),
            'is_enabled' => false,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $checker->forceFill(['locale' => $locale])->save();
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($checker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ], false))->assertForbidden();

            $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
                'store_id' => $store->id,
                'decision' => 'approve',
            ])->assertForbidden();

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ], false))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.submit', $requestId), [
                'store_id' => $store->id,
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $requestId)
            ->count());

        // Restoring entitlement only restores route eligibility. Even a
        // maker who later receives the checker role cannot approve their own
        // previously submitted request.
        $entitlements->set('procurement', $storeScope, true, null, $admin);
        $buyer->roles()->attach(Role::query()->where('code', 'PROCUREMENT_APPROVER')->firstOrFail());
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.purchase_requests.approve', $store->id));

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
        ])->assertSessionHasErrors('decision');

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'submitted',
            'decided_by' => null,
        ]);

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id,
            'decision' => 'approve',
            'note' => 'Independent P111 checker',
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'decided_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $requestId)
            ->count());
    }

    public function test_explicit_store_deny_preserves_independent_sibling_b2b_entitlement(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-B2B-SIBLING',
            'name' => 'P111 B2B sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);

        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $primary->id),
            false,
            null,
            $admin,
        );

        foreach (['ar', 'en'] as $locale) {
            $buyer->forceFill(['locale' => $locale])->save();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
            ]))->assertOk();
        }

        $request = [
            'currency_code' => 'KWD',
            'reason' => 'P111 independent B2B store scope',
            'item_code' => 'P111-SCOPE-SKU',
            'description' => 'Cross-store entitlement enforcement',
            'quantity' => 1,
            'unit' => 'case',
            'estimated_unit_cost' => '1.000',
        ];

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$request,
            'store_id' => $primary->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => $request['reason'],
        ]);

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
            ...$request,
            'store_id' => $sibling->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'store_id' => $sibling->id,
            'reason' => $request['reason'],
        ]);
    }

    public function test_store_only_entitlement_revocation_denies_direct_requests_read_and_mutation_without_platform_fallback(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $scope = new EntitlementScope(EntitlementScope::STORE, $store->id);
        $entitlements->set('procurement', $scope, true, null, $admin);
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())
                ->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        // Revoking the only STORE entitlement must reject direct HTTP even
        // though the same actor retains their global Procurement Buyer role.
        $entitlements->set('procurement', $scope, false, null, $admin);
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertForbidden();

            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => 'P111 revoked store only is forbidden',
                'item_code' => 'P111-REVOKED',
                'description' => 'Never persist after revocation',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => 'P111 revoked store only is forbidden',
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_global_supplier_deny_and_unrelated_retail_assignment_reject_direct_http_before_setup_conflict(): void
    {
        $wholesale = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $wholesale->store_type_id,
            'code' => 'P111-GLOBAL-DENY-B2B',
            'name' => 'P111 GLOBAL DENY B2B',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P111-FOREIGN-RETAIL',
            'name' => 'P111 unrelated Retail',
            'is_active' => true,
        ]);
        // No ERP hierarchy is created for this Retail store: an unrelated
        // Retail Finance assignment must receive 403, not a misleading 409.
        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $buyer->storeRoleAssignments()->create([
            'store_id' => $retail->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
        ]);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $admin,
        );

        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $code) {
            $permission = Permission::query()->where('code', $code)->firstOrFail();
            foreach ([
                ['scope_key' => 'global', 'store_id' => null, 'effect' => 'deny'],
                ['scope_key' => 'store:'.$sibling->id, 'store_id' => $sibling->id, 'effect' => 'allow'],
            ] as $override) {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $buyer->id,
                    'permission_id' => $permission->id,
                    ...$override,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $sibling->id));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.purchase_requests.view', $sibling->id));
        $this->assertFalse($buyer->fresh()->hasRole('PROCUREMENT_BUYER', $retail->id));
        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $retail->id));
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $actor = $buyer->fresh();
            // Supplier-specific DENY does not revoke independent Requests.
            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id, 'tab' => 'requests',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id, 'tab' => 'suppliers',
            ]))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $retail->id, 'tab' => 'suppliers',
            ]))->assertForbidden();
            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $retail->id,
                'scope_type' => 'store',
                'code' => 'P111-RETAIL-FORGED',
                'name_en' => 'Forbidden Retail Supplier',
                'name_ar' => 'مورد غير مصرح',
            ])->assertForbidden();
            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $retail->id,
                'scope_type' => 'store',
                'code' => 'P111-RETAIL-FORGED-TEST',
                'name_en' => 'Forbidden Retail Supplier Test',
                'setup_action' => 'test',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('enterprise_suppliers', [
            'code' => 'P111-RETAIL-FORGED',
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_historical_unmatched_invoice_get_keeps_pending_row_without_creating_or_approving_review_in_ar_en(): void
    {
        // An imported/legacy invoice can have no match-review row. A GET must
        // never materialize a decision, even if it could be auto-approved.
        // The write-only sync remains in the explicit invoice/review lifecycle.
        $store = $this->wholesaleStore();
        $maker = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $admin = $this->actor('SUPER_ADMIN');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );
        if (class_exists(SyntheticErpWorkflowEvidence::class)) {
            SyntheticErpWorkflowEvidence::install();
        }

        $adminContext = app(TenantContextResolver::class)->wholesale($admin, $store->id);
        $makerContext = app(TenantContextResolver::class)->wholesale($maker, $store->id);
        $checkerContext = app(TenantContextResolver::class)->wholesale($checker, $store->id);

        $sku = 'P111-NO-GET-WRITE';
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2b',
            'code' => 'p111-no-get-write',
            'name' => 'P111 source stock',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'P111-EACH-GET',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => 'P111 source stock',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => 'P111-GET-WH',
            'name' => 'P111 match source',
            'is_active' => true,
        ]);

        $supplier = app(SupplierDirectory::class)->create($admin, $adminContext, [
            'scope_type' => 'store',
            'code' => 'P111-GET-SUP',
            'name_en' => 'P111 supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($maker, $makerContext, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 unmatched invoice direct GET',
            'lines' => [[
                'item_code' => $sku,
                'description' => 'P111 source stock',
                'quantity' => 2,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($maker, $makerContext, $request->id);
        $requests->approve($checker, $checkerContext, $request->id);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($admin, $adminContext, $request->id);
        $order = $orders->issue($admin, $adminContext, $order->id);
        $receipts = app(GoodsReceiptManager::class);
        $receipt = $receipts->createFromIssuedOrder($admin, $adminContext, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 2,
            ]],
        ]);
        $receipt = $receipts->post($admin, $adminContext, $receipt->id);
        $invoice = app(PurchaseInvoiceHandoffManager::class)->createFromPostedReceipt(
            $admin,
            $adminContext,
            $receipt->id,
            [
                'supplier_invoice_number' => 'P111-GET-NOWRITE',
                'invoice_date' => '2026-11-17',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 2,
                ]],
            ],
        );

        // Simulate a genuine historical invoice imported before matching was
        // materialized, after the normal authorized write workflow has ended.
        DB::table('enterprise_procurement_match_reviews')
            ->where('purchase_invoice_id', $invoice->id)
            ->delete();
        $this->assertDatabaseMissing('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
        ]);
        $auditCount = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'matches',
            ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($invoice->invoice_number);

            $this->assertDatabaseMissing('enterprise_procurement_match_reviews', [
                'purchase_invoice_id' => $invoice->id,
            ]);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
        }
        $this->assertDatabaseHas('enterprise_purchase_invoices', [
            'id' => $invoice->id,
            'status' => 'draft',
        ]);
    }

    public function test_supplier_setup_candidate_uses_existing_authority_and_validator_without_writes_in_both_locales(): void
    {
        $store = $this->wholesaleStore();
        $admin = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $admin,
        );

        $candidate = [
            'store_id' => $store->id,
            'scope_type' => 'store',
            'code' => 'P111-SUPPLIER-PREFLIGHT',
            'name_en' => 'Fictional Supplier Example',
            'name_ar' => 'مثال مورد خيالي',
            'payment_terms_days' => 30,
        ];
        $before = DB::table('enterprise_suppliers')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee(__('supplier_setup_help.test', [], $locale));

            $this->actingAs($actor)->post(route('admin.erp.procurement.suppliers.store'), [
                ...$candidate,
                'setup_action' => 'test',
            ])->assertRedirect()->assertSessionHas('status');
            $this->assertSame($before, DB::table('enterprise_suppliers')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }

        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'test',
            'payment_terms_days' => 3651,
        ])->assertSessionHasErrors(['payment_terms_days']);
        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'unexpected',
        ])->assertSessionHasErrors(['setup_action']);
        $this->assertSame($before, DB::table('enterprise_suppliers')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'save',
        ])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('enterprise_suppliers', [
            'code' => 'P111-SUPPLIER-PREFLIGHT',
            'scope_type' => 'store',
            'store_id' => $store->id,
        ]);
        $this->assertSame($before + 1, DB::table('enterprise_suppliers')->count());
        $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());

        $this->actingAs($admin)->post(route('admin.erp.procurement.suppliers.store'), [
            ...$candidate,
            'setup_action' => 'test',
        ])->assertSessionHasErrors(['code']);
        $this->assertSame($before + 1, DB::table('enterprise_suppliers')->count());
    }

    public function test_procurement_reports_and_csv_export_respect_store_entitlement_and_do_not_leak_sibling_orders(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-REPORT-SIBLING',
            'name' => 'P111 Reports Sibling',
            'is_active' => true,
        ]);
        $hierarchy = app(OrganizationHierarchyService::class);
        $primaryScope = $hierarchy->scopeForStore($primary->id);
        $this->assertIsArray($primaryScope);
        // Distinct company (not just a second store in the default company).
        // Verify both the company and selected-store disclosure boundary.
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $primaryScope['organization_id'],
            'code' => 'P111_REPORT_OTHER_COMPANY',
            'name' => 'P111 Reporting Other Company',
            'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'P111_REPORT_OTHER_ENTITY',
            'name' => 'P111 Reporting Other Entity',
            'is_active' => true,
        ]);
        $foreignUnit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $foreignEntity->id,
            'code' => 'P111_REPORT_OTHER_UNIT',
            'name' => 'P111 Reporting Other Unit',
            'is_active' => true,
        ]);
        $hierarchy->assignStore($sibling, $foreignUnit);
        $siblingScope = $hierarchy->scopeForStore($sibling->id);
        $this->assertIsArray($siblingScope);
        $this->assertNotSame($primaryScope['company_id'], $siblingScope['company_id']);

        $admin = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $admin);

        $adminContext = app(TenantContextResolver::class)->wholesale($admin, $primary->id);
        $buyerContext = app(TenantContextResolver::class)->wholesale($buyer, $primary->id);
        $checkerContext = app(TenantContextResolver::class)->wholesale($checker, $primary->id);

        $supplier = app(SupplierDirectory::class)->create($admin, $adminContext, [
            'scope_type' => 'store',
            'code' => 'P111-REPORT-SUP',
            'name_en' => 'P111 Reports Supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($buyer, $buyerContext, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 scoped reports origin',
            'lines' => [[
                'item_code' => 'P111-REPORT-SKU',
                'description' => 'Scoped source order',
                'quantity' => 2,
                'unit' => 'each',
                'estimated_unit_cost' => '1.250',
            ]],
        ]);
        $requests->submit($buyer, $buyerContext, $request->id);
        $requests->approve($checker, $checkerContext, $request->id);
        $order = app(PurchaseOrderManager::class)
            ->createFromApprovedRequest($admin, $adminContext, $request->id);

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $request->id,
            'created_by' => $buyer->id,
            'decided_by' => $checker->id,
            'status' => 'approved',
        ]);
        $auditBefore = DB::table('audit_logs')->count();
        $ordersBefore = DB::table('enterprise_purchase_orders')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();
            $actor = $admin->fresh();

            $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'reports',
                'report' => 'open_orders',
            ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($order->order_number);

            $authorizedCsv = $this->actingAs($actor)->get(
                route('admin.erp.procurement.reports.export', [
                    'store_id' => $primary->id,
                    'report' => 'open_orders',
                ]),
            );
            $authorizedCsv->assertOk();
            $this->assertStringContainsString($order->order_number, $authorizedCsv->streamedContent());

            // Global B2B role and platform entitlement do not override an
            // explicit STORE denial on either report or download endpoints.
            $entitlements->set(
                'procurement',
                new EntitlementScope(EntitlementScope::STORE, $primary->id),
                false,
                null,
                $admin,
            );

            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'reports',
                'report' => 'open_orders',
            ]))->assertForbidden();
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.reports.export', [
                'store_id' => $primary->id,
                'report' => 'open_orders',
            ]))->assertForbidden();

            // A separate entitled B2B store must not inherit the first
            // company's purchase order through dashboard or CSV export.
            $this->actingAs($admin->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'reports',
                'report' => 'open_orders',
            ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee($order->order_number);

            $siblingCsv = $this->actingAs($admin->fresh())->get(
                route('admin.erp.procurement.reports.export', [
                    'store_id' => $sibling->id,
                    'report' => 'open_orders',
                ]),
            );
            $siblingCsv->assertOk();
            $this->assertStringNotContainsString($order->order_number, $siblingCsv->streamedContent());

            $entitlements->set(
                'procurement',
                new EntitlementScope(EntitlementScope::STORE, $primary->id),
                true,
                null,
                $admin,
            );
        }

        $this->assertSame($ordersBefore, DB::table('enterprise_purchase_orders')->count());
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id,
            'store_id' => $primary->id,
        ]);
        // Entitlement setup is audited separately; read/export requests must
        // never create a purchase-order or finance-posting audit event.
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());
        $this->assertGreaterThanOrEqual($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_procurement_erp_center_sidebar_http_readiness_parity_for_four_roles(): void
    {
        // A production-like isolated DB must never let the local Super Admin
        // bootstrap create operational approvals or entitlements on GET.
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $actors = [
            'PROCUREMENT_BUYER' => $this->actor('PROCUREMENT_BUYER'),
            'PROCUREMENT_MANAGER' => $this->actor('PROCUREMENT_MANAGER'),
            'PROCUREMENT_APPROVER' => $this->actor('PROCUREMENT_APPROVER'),
            'SUPER_ADMIN' => $owner,
        ];

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ($actors as $role => $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();
                $page = $this->actingAs($user)->get(route('admin.erp.index'));
                $page->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card, $role);
                $this->assertSame('degraded', $card['status'], $role);
                $this->assertSame('uncertified', $card['denial_reason'], $role);
                $this->assertNull($card['url'], $role);
                $this->assertSame('healthy', $card['health'], 'Health alone must not certify '.$role);
                $page->assertSee(
                    __('admin.erp_center.denial_reasons.uncertified', [], $locale),
                );

                $item = collect(app(AdminNavigation::class)->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->firstWhere('key', 'erp_procurement');
                if ($role === 'SUPER_ADMIN') {
                    $this->assertIsArray($item);
                    $this->assertSame('admin.enterprise-modules.show', $item['route']);
                    $this->assertSame(['module' => 'procurement'], $item['params']);
                    $this->actingAs($user)
                        ->get(route('admin.enterprise-modules.show', ['module' => 'procurement']))
                        ->assertOk();
                } else {
                    $this->assertNull($item, 'Uncertified business route visible to '.$role);
                    $this->actingAs($user)
                        ->get(route('admin.enterprise-modules.show', ['module' => 'procurement']))
                        ->assertForbidden();
                }

                // HTTP readiness must forbid direct URL entry even for an
                // authenticated role and without a valid operational tab.
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertForbidden();
            }
        }

        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ($actors as $role => $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();
                $page = $this->actingAs($user)->get(route('admin.erp.index'));
                $page->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card, $role);
                $this->assertSame('available', $card['status'], $role);
                $this->assertSame(route('admin.erp.procurement.index'), $card['url'], $role);
                $item = collect(app(AdminNavigation::class)->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->firstWhere('key', 'erp_procurement');
                $this->assertIsArray($item, $role);
                $this->assertSame('admin.erp.procurement.index', $item['route'], $role);
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'invalid-tab-forged',
                ]))->assertNotFound();
            }
        }
    }

    public function test_revoked_procurement_store_entitlement_cannot_leave_business_card_or_sidebar_action(): void
    {
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $actors = [
            'PROCUREMENT_BUYER' => $this->actor('PROCUREMENT_BUYER'),
            'PROCUREMENT_MANAGER' => $this->actor('PROCUREMENT_MANAGER'),
            'PROCUREMENT_APPROVER' => $this->actor('PROCUREMENT_APPROVER'),
            'SUPER_ADMIN' => $owner,
        ];

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
            null,
            $owner,
        );
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'store:'.$store->id,
            'is_enabled' => false,
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ($actors as $role => $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $user = $actor->fresh();
                $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertForbidden();

                $page = $this->actingAs($user)->get(route('admin.erp.index'));
                $page->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card, $role);
                // Regression contract: a platform ALLOW must never conceal
                // a concrete selected Wholesale store DENY. This currently
                // exposes a source parity gap in ERP Center/Navigation.
                $this->assertSame('not_entitled', $card['status'], $role);
                $this->assertNull($card['url'], $role);

                $item = collect(app(AdminNavigation::class)->groupsFor($user))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->firstWhere('key', 'erp_procurement');
                if ($role === 'SUPER_ADMIN') {
                    $this->assertIsArray($item);
                    $this->assertSame('admin.enterprise-modules.show', $item['route']);
                } else {
                    $this->assertNull($item, 'Revoked store advertised to '.$role);
                }
            }
        }
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user->fresh();
    }
}
