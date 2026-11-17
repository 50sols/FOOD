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
                    // AdminNavigation reserves an uncertified module's
                    // diagnostic Sidebar entry for the registered Platform
                    // Owner, not every Super Admin. The existing platform
                    // management HTTP action is independently authorized;
                    // never invent a business or diagnostic menu grant.
                    $this->assertNull($item, 'No unready Procurement Sidebar grant for Super Admin');
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
                // Every advertised operational tab is a real role-authorized
                // HTTP destination, not merely a visible link on ERP Center.
                foreach (['suppliers', 'orders'] as $tab) {
                    $tabResponse = $this->actingAs($user)->get(route('admin.erp.procurement.index', [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]));
                    $tabResponse->assertOk()->assertSee('dir="'.$direction.'"', false);
                    $this->assertSame($tab, $tabResponse->viewData('activeTab'));
                    $this->assertContains($tab, collect($tabResponse->viewData('tabs'))->pluck('key')->all());
                }

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

    public function test_procurement_tab_permissions_deny_suppliers_and_orders_without_hiding_authorized_requests(): void
    {
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        foreach (['procurement.suppliers.view', 'procurement.purchase_orders.view'] as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $actor = $buyer->fresh();

            $this->assertTrue($actor->hasPermission('procurement.purchase_requests.view', $store->id));
            $this->assertFalse($actor->hasPermission('procurement.suppliers.view', $store->id));
            $this->assertFalse($actor->hasPermission('procurement.purchase_orders.view', $store->id));

            $requests = $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]));
            $requests->assertOk()->assertSee('dir="'.$direction.'"', false);
            $visible = collect($requests->viewData('tabs'))->pluck('key')->all();
            $this->assertContains('requests', $visible);
            $this->assertNotContains('suppliers', $visible);
            $this->assertNotContains('orders', $visible);

            foreach (['suppliers', 'orders'] as $forbiddenTab) {
                $this->actingAs($actor)->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $forbiddenTab,
                ]))->assertForbidden();
            }
        }
    }

    public function test_procurement_lifecycle_and_stale_manifest_fail_closed_for_card_and_direct_http(): void
    {
        // Only disposable signed evidence is used by this suite; these are
        // HTTP gate tests, never an operational G0-G13 certification.
        $this->app['env'] = 'production';
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $lifecycle = app(EnterpriseModuleAdministration::class);
        $lifecycle->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $manifestVersion = app(ModuleRegistry::class)->manifest('procurement')->version;

        foreach (['suspend', 'disable'] as $action) {
            $lifecycle->perform('procurement', $action, $owner);
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                app()->setLocale($locale);
                $owner->forceFill(['locale' => $locale])->save();
                $buyer->forceFill(['locale' => $locale])->save();

                $center = $this->actingAs($owner->fresh())->get(route('admin.erp.index'));
                $center->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
                $this->assertIsArray($card);
                $this->assertNotSame('available', $card['status']);
                $this->assertNull($card['url']);

                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertForbidden();
            }
            $lifecycle->perform('procurement', 'enable', $owner);
        }

        config()->set('enterprise_module_readiness.certified_versions.procurement', $manifestVersion + 1);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $buyer->forceFill(['locale' => $locale])->save();
            $center = $this->actingAs($owner->fresh())->get(route('admin.erp.index'));
            $center->assertOk();
            $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('uncertified', $card['denial_reason']);
            $this->assertNull($card['url']);
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertForbidden();
        }
    }

    public function test_broken_procurement_health_probe_degrades_card_and_denies_http_without_writes_in_both_locales(): void
    {
        // Probe configuration is a server-side operational requirement, not a
        // UI preference or an entitlement that an enabled module can bypass.
        $this->app['env'] = 'production';
        // Model a valid production CSRF submission: a 403 must originate in
        // Procurement authorization/readiness, never in the unrelated 419 gate.
        $csrfToken = 'p111-csrf-token-in-request';
        $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken);
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        // Invalid probe configuration triggers an actual ModuleHealthService
        // ERROR, not a manually forged health/status tag.
        config()->set('enterprise_module_health.probes.procurement', ['nonexistent-procurement-health-probe']);

        $reason = 'P111 unhealthy probe must never create request';
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $owner->forceFill(['locale' => $locale])->save();
            $buyer->forceFill(['locale' => $locale])->save();

            $page = $this->actingAs($owner->fresh())->get(route('admin.erp.index'));
            $page->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($page->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('degraded', $card['status']);
            $this->assertSame('unhealthy', $card['denial_reason']);
            $this->assertSame('error', $card['health']);
            $this->assertNull($card['url'], 'Failed health must suppress business CTA');

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'requests',
            ]))->assertForbidden();
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $store->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-HEALTH-DENY',
                'description' => 'Must be blocked before mutation',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '2.000',
            ])->assertForbidden();
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertDatabaseMissing('enterprise_purchase_requests', ['reason' => $reason]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_missing_store_entitlement_and_missing_required_certification_cannot_be_bypassed_by_reenable(): void
    {
        // Once Procurement is enrolled in the entitlement policy, absent
        // effective STORE grants fail closed even if another B2B store is allowed.
        $this->app['env'] = 'production';
        // Model a valid production CSRF submission: a 403 must originate in
        // Procurement authorization/readiness, never in the unrelated 419 gate.
        $csrfToken = 'p111-csrf-token-in-request';
        $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken);
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-MISSING-ENTITLEMENT-SIBLING',
            'name' => 'P111 entitlement sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $sibling->id),
            true,
            null,
            $owner,
        );
        $reason = 'P111 absent entitlement must never create request';
        $countBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $primary->id,
                'currency_code' => 'KWD',
                'reason' => $reason,
                'item_code' => 'P111-ENTITLEMENT-DENY',
                'description' => 'Cannot write through an absent scope grant',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertForbidden();
            // The other entitled B2B tenant can still perform its own read.
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
            ]))->assertOk();
        }

        $this->assertSame($countBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // A newly granted store scope does not override an absent exact-version
        // operational certification. The manifest/version requirement stays
        // independent of entitlement and channel/role admission.
        $entitlements->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $primary->id),
            true,
            null,
            $owner,
        );
        // Entitlement configuration is itself an authorized, audited write.
        // Only forbidden HTTP and subsequent GET must be free of extra audit
        // side effects; do not compare across the owner's legitimate grant.
        $auditAfterGrant = DB::table('audit_logs')->count();
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();
        }

        // A test-only exact-manifest readiness fixture restores authorized GET
        // after every independent guard passes. This is NOT real G0-G13 proof.
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $primary->id,
            'tab' => 'requests',
        ]))->assertOk();
        $this->assertSame($countBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditAfterGrant, DB::table('audit_logs')->count());
    }

    public function test_forged_nonpositive_or_malformed_store_id_never_falls_back_to_an_authorized_store(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-forged-store-selector-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $this->actingAs($buyer)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'requests',
        ]))->assertOk();
        $auditBefore = DB::table('audit_logs')->count();
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();

            foreach ([0, -1, 'not-a-store'] as $invalidStoreId) {
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $invalidStoreId,
                    'tab' => 'requests',
                ]))->assertForbidden();
                $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                    'store_id' => $invalidStoreId,
                    'currency_code' => 'KWD',
                    'reason' => 'P111 forged store selector must never persist',
                    'item_code' => 'P111-FORGED-STORE',
                    'description' => 'Unauthorized selected-store fallback',
                    'quantity' => '1.000',
                    'unit' => 'case',
                    'estimated_unit_cost' => '1.000',
                ])->assertForbidden();
            }
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_default_procurement_store_picker_excludes_store_deny_and_uses_entitled_sibling(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-DEFAULT-ENTITLED-B2B',
            'name' => 'P111 entitled B2B sibling',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);

        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $entitlements->set(
            'procurement', new EntitlementScope(EntitlementScope::STORE, $primary->id),
            false, null, $owner,
        );
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $page = $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'requests',
            ]));
            $page->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->assertSame((int) $sibling->id, (int) $page->viewData('selectedStoreId'));
            $visibleStoreIds = collect($page->viewData('storeOptions'))->pluck('id')->all();
            $this->assertContains((int) $sibling->id, $visibleStoreIds);
            $this->assertNotContains((int) $primary->id, $visibleStoreIds);

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
            ]))->assertForbidden();
        }

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_deactivated_super_admin_cannot_read_or_mutate_procurement_even_with_historical_role(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $owner->forceFill(['is_active' => false])->save();

        $this->actingAs($owner->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($owner->fresh())->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 inactive administrator cannot mutate',
            'item_code' => 'P111-INACTIVE',
            'description' => 'No inactive business mutation',
            'quantity' => '1.000',
            'unit' => 'case',
            'estimated_unit_cost' => '1.000',
        ])->assertForbidden();
        $this->assertDatabaseMissing('enterprise_purchase_requests', [
            'reason' => 'P111 inactive administrator cannot mutate',
        ]);
    }

    public function test_authorized_b2b_store_without_enterprise_hierarchy_returns_409_after_authority_checks(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-authorized-missing-hierarchy-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

        $primary = $this->wholesaleStore();
        $unconfigured = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-B2B-MISSING-ENTERPRISE-SCOPE',
            'name' => 'P111 authorized but unconfigured B2B',
            'is_active' => true,
        ]);
        // Deliberately DO NOT call ensureDefaultScopeForStore(): this is an
        // authorized-but-unconfigured hierarchy, unlike a forged Retail scope.
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $this->actingAs($buyer)->get(route('admin.erp.procurement.index', [
            'store_id' => $primary->id,
            'tab' => 'requests',
        ]))->assertOk();

        $auditBefore = DB::table('audit_logs')->count();
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $buyer->forceFill(['locale' => $locale])->save();
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $unconfigured->id,
                'tab' => 'requests',
            ]))->assertStatus(409);
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                'store_id' => $unconfigured->id,
                'currency_code' => 'KWD',
                'reason' => 'P111 legitimate but unconfigured hierarchy',
                'item_code' => 'P111-SETUP-409',
                'description' => 'Reject before writes on missing hierarchy',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ])->assertStatus(409);
        }

        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_canonical_procurement_global_roles_require_live_account_pivot_and_actual_job_role_permissions(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $matrix = [
            'PROCUREMENT_BUYER' => [true, false, false],
            'PROCUREMENT_APPROVER' => [false, true, true],
            'PROCUREMENT_MANAGER' => [true, true, true],
        ];
        $permissions = [
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.issue',
        ];
        $this->assertContains('PROCUREMENT_BUYER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('PROCUREMENT_BUYER', config('admin.channels.b2c.global_roles'));

        foreach ($matrix as $code => $expected) {
            $definition = config('job_roles.roles.'.$code);
            $this->assertIsArray($definition, $code);
            $this->assertSame('global', $definition['scope'], $code);
            $this->assertSame('procurement', $definition['domain'], $code);
            $this->assertContains('procurement', $definition['required_modules'], $code);
            $role = Role::query()->where('code', $code)->firstOrFail();
            $this->assertSame('global', $role->scope, $code);
            $this->assertTrue($role->is_active, $code);
            $this->assertNull($role->archived_at, $code);
            $this->assertEqualsCanonicalizing(
                $definition['permissions'],
                $role->permissions->pluck('code')->all(),
                $code.' must use the seeded canonical job_roles grants',
            );

            $actor = $this->actor($code);
            $this->assertDatabaseHas('role_user', [
                'user_id' => $actor->id,
                'role_id' => $role->id,
            ]);
            $this->assertTrue($actor->hasRole($code, $store->id), $code);
            foreach ($permissions as $index => $permission) {
                $this->assertSame($expected[$index], $actor->hasPermission($permission, $store->id), $code.':'.$permission);
            }
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => 'requests',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            }
        }

        // A seeded role/catalog entry with no actual role_user assignment does
        // not grant an otherwise active and authenticated account any access.
        $unassigned = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->assertFalse($unassigned->hasRole('PROCUREMENT_BUYER', $store->id));
        $this->assertFalse($unassigned->hasPermission('procurement.purchase_requests.view', $store->id));
        $before = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        $this->actingAs($unassigned)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'requests',
        ]))->assertForbidden();
        $this->actingAs($unassigned)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 no linked global role',
            'description' => 'Forbidden without actual assignment',
            'quantity' => '1.000',
            'unit' => 'case',
        ])->assertForbidden();
        $this->assertSame($before, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_wholesale_global_role_and_retail_store_role_cannot_cross_channel_or_forge_assignment(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-role-channel-boundary-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
        $wholesaleA = $this->wholesaleStore();
        $wholesaleB = Store::query()->create([
            'store_type_id' => $wholesaleA->store_type_id,
            'code' => 'P111-GLOBAL-B2B-B',
            'name' => 'P111 B2B other store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($wholesaleB);
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailA = Store::query()->create([
            'store_type_id' => $retailType,
            'code' => 'P111-ROLE-B2C-A',
            'name' => 'P111 assigned Retail A',
            'is_active' => true,
        ]);
        $retailB = Store::query()->create([
            'store_type_id' => $retailType,
            'code' => 'P111-ROLE-B2C-B',
            'name' => 'P111 unassigned Retail B',
            'is_active' => true,
        ]);
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        $retailOnly = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $wrongScope = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $buyer->storeRoleAssignments()->create([
            'store_id' => $retailA->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
        ]);
        $retailOnly->storeRoleAssignments()->create([
            'store_id' => $retailA->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
        ]);
        // Even a forged store-role pivot pointing to a GLOBAL Procurement
        // role must not be interpreted as a valid STORE-scoped assignment.
        $wrongScope->storeRoleAssignments()->create([
            'store_id' => $retailB->id,
            'role_id' => Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail()->id,
        ]);
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        $entitlements = app(ModuleEntitlementManager::class);
        $entitlements->set('procurement', EntitlementScope::platform(), true, null, $owner);
        $entitlements->set(
            'procurement', new EntitlementScope(EntitlementScope::STORE, $wholesaleB->id),
            false, null, $owner,
        );
        $this->assertTrue($buyer->hasRole('PROCUREMENT_BUYER', $wholesaleA->id));
        $this->assertFalse($buyer->hasRole('PROCUREMENT_BUYER', $retailA->id));
        $this->assertFalse($wrongScope->hasRole('PROCUREMENT_BUYER', $retailB->id));
        $this->assertFalse($wrongScope->hasPermission('procurement.purchase_requests.view', $retailB->id));
        $this->assertFalse($retailOnly->hasPermission('procurement.purchase_requests.view', $retailA->id));

        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach ([$buyer, $retailOnly, $wrongScope] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
            }
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $wholesaleA->id, 'tab' => 'requests',
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

            foreach ([$wholesaleB->id, $retailA->id, $retailB->id] as $forbiddenStoreId) {
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $forbiddenStoreId, 'tab' => 'requests',
                ]))->assertForbidden();
                $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.requests.store'), [
                    'store_id' => $forbiddenStoreId,
                    'currency_code' => 'KWD',
                    'reason' => 'P111 denied wholesale/retail crossover',
                    'description' => 'Never create outside authorized store',
                    'quantity' => '1.000',
                    'unit' => 'case',
                ])->assertForbidden();
            }
            foreach ([
                [$retailOnly, $retailA->id],
                [$retailOnly, $retailB->id],
                [$retailOnly, $wholesaleA->id],
                [$wrongScope, $retailB->id],
            ] as [$actor, $forbiddenStoreId]) {
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $forbiddenStoreId, 'tab' => 'requests',
                ]))->assertForbidden();
            }
        }
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_inactive_archived_procurement_identity_and_archived_global_role_are_denied_with_audit_unchanged(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $archivedIdentity = $this->actor('PROCUREMENT_MANAGER');
        $archivedIdentity->forceFill([
            'archived_at' => now(),
            'is_active' => true,
        ])->save();
        $this->assertTrue($archivedIdentity->hasRole('PROCUREMENT_MANAGER', $store->id));
        // Historical role remains linked, but archive must deny actual HTTP.
        $role = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $archivedRoleUser = $this->actor('PROCUREMENT_BUYER');
        $role->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $this->assertDatabaseHas('role_user', [
            'user_id' => $archivedRoleUser->id, 'role_id' => $role->id,
        ]);
        $this->assertFalse($archivedRoleUser->fresh()->hasRole('PROCUREMENT_BUYER', $store->id));
        $this->assertFalse($archivedRoleUser->fresh()->hasPermission('procurement.purchase_requests.view', $store->id));
        $inactive = $this->actor('PROCUREMENT_APPROVER');
        $inactive->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
        $this->assertFalse($inactive->fresh()->hasPermission('procurement.purchase_requests.approve', $store->id));
        $requestsBefore = DB::table('enterprise_purchase_requests')->count();
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar', 'en'] as $locale) {
            foreach ([$archivedIdentity, $archivedRoleUser, $inactive] as $user) {
                $user->forceFill(['locale' => $locale])->save();
                $this->actingAs($user->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id, 'tab' => 'requests',
                ]))->assertForbidden();
                $this->actingAs($user->fresh())->post(route('admin.erp.procurement.requests.store'), [
                    'store_id' => $store->id,
                    'currency_code' => 'KWD',
                    'reason' => 'P111 inactive or archived role denies writes',
                    'description' => 'Historical assignments cannot authorize writes',
                    'quantity' => '1.000',
                    'unit' => 'case',
                ])->assertForbidden();
            }
        }
        $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_multi_role_maker_checker_cannot_self_approve_or_issue_po_with_explicit_global_deny(): void
    {
        $this->app['env'] = 'production';
        $csrfToken = 'p111-multi-role-maker-checker-csrf';
        $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_MANAGER');
        $checker = $this->actor('PROCUREMENT_APPROVER');
        $buyerOnly = $this->actor('PROCUREMENT_BUYER');
        foreach (['PROCUREMENT_BUYER', 'PROCUREMENT_APPROVER'] as $code) {
            $maker->roles()->syncWithoutDetaching([
                Role::query()->where('code', $code)->firstOrFail()->id,
            ]);
        }
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $maker = $maker->fresh();
        $this->assertTrue($maker->hasPermission('procurement.purchase_requests.approve', $store->id));
        $this->assertTrue($maker->hasPermission('procurement.purchase_orders.issue', $store->id));
        $this->assertFalse($buyerOnly->hasPermission('procurement.purchase_orders.issue', $store->id));

        $context = app(TenantContextResolver::class)->wholesale($maker, $store->id);
        $supplier = app(SupplierDirectory::class)->create($maker, $context, [
            'scope_type' => 'store',
            'code' => 'P111-SOD-GLOBAL-MULTIROLE',
            'name_en' => 'P111 separated duties supplier',
        ]);
        $requests = app(PurchaseRequestManager::class);
        $purchaseRequest = $requests->create($maker, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'P111 manager dual role independent maker checker',
            'lines' => [[
                'item_code' => 'P111-SOD-SKU',
                'description' => 'P111 maker checker witness',
                'quantity' => 1,
                'unit' => 'case',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($maker, $context, $purchaseRequest->id);
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $purchaseRequest->id,
            'status' => 'submitted',
            'created_by' => $maker->id,
            'submitted_by' => $maker->id,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.requests.decision', $purchaseRequest->id), [
                'store_id' => $store->id,
                'decision' => 'approve',
            ])->assertSessionHasErrors('decision');
        }
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $purchaseRequest->id, 'status' => 'submitted', 'decided_by' => null,
        ]);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $purchaseRequest->id)
            ->count());

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.requests.decision', $purchaseRequest->id), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $purchaseRequest->id, 'status' => 'approved', 'decided_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_request.approved')
            ->where('auditable_id', $purchaseRequest->id)
            ->count());

        $this->actingAs($maker->fresh())->post(route('admin.erp.procurement.orders.create', $purchaseRequest->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $order = DB::table('enterprise_purchase_orders')->where('purchase_request_id', $purchaseRequest->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('draft', $order->status);
        $permission = Permission::query()->where('code', 'procurement.purchase_orders.issue')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $maker->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $maker->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($maker->fresh()->hasPermission('procurement.purchase_orders.issue', $store->id));

        $auditBeforeForbiddenIssue = DB::table('audit_logs')->count();
        foreach ([$buyerOnly, $maker] as $actor) {
            $this->actingAs($actor->fresh())->post(route('admin.erp.procurement.orders.issue', $order->id), [
                'store_id' => $store->id,
            ])->assertForbidden();
        }
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'draft', 'issued_by' => null,
        ]);
        $this->assertSame($auditBeforeForbiddenIssue, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());

        $this->actingAs($checker->fresh())->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued', 'issued_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_all_twelve_canonical_procurement_tabs_have_one_localized_horizontal_http_rail(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $canonical = [
            'overview', 'suppliers', 'requests', 'rfqs', 'orders', 'contracts',
            'receipts', 'invoices', 'matches', 'returns', 'statements', 'reports',
        ];
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            foreach ($canonical as $tab) {
                $response = $this->actingAs($manager->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]));
                $response->assertOk()
                    ->assertViewHas('activeTab', $tab)
                    ->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($store->id, $response->viewData('selectedStoreId'));
                $this->assertSame($canonical, collect($response->viewData('tabs'))->pluck('key')->all());

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(1, preg_match(
                    '/<nav class="foodex-tabs erp-procurement-tabs" data-procurement-tabs[^>]*>(.*?)<\\/nav>/s',
                    $html,
                    $nav,
                ), $locale.'/'.$tab.' must reuse one horizontal nav');
                $this->assertSame(12, substr_count($nav[1], 'data-procurement-tab="'));
                $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
                $this->assertStringContainsString(
                    'data-procurement-tab="'.$tab.'"',
                    $nav[1],
                );
                $this->assertStringContainsString(
                    __('enterprise_procurement.tabs.'.$tab, [], $locale),
                    $nav[1],
                );
                $this->assertSame(1, preg_match(
                    '/<a data-procurement-tab="'.preg_quote($tab, '/').'" href="([^"]+)"/',
                    $nav[1],
                    $link,
                ));
                parse_str((string) parse_url($link[1], PHP_URL_QUERY), $query);
                $this->assertSame($tab, $query['tab'] ?? null);
                $this->assertSame((string) $store->id, (string) ($query['store_id'] ?? ''));
            }
        }
        // GET for a list, dashboard or report may never issue a business
        // transition or append a successful write audit.
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_procurement_search_filters_and_single_pagination_preserve_tab_store_and_deep_link_query(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $manager = $this->actor('PROCUREMENT_MANAGER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $statusByTab = [
            'suppliers' => 'active',
            'requests' => 'draft',
            'rfqs' => 'draft',
            'orders' => 'draft',
            'contracts' => 'draft',
            'receipts' => 'draft',
            'invoices' => 'draft',
            'matches' => 'pending',
            'returns' => 'draft',
        ];
        $embedded = ['rfqs', 'contracts', 'matches'];
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            foreach ($statusByTab as $tab => $status) {
                $response = $this->actingAs($manager->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                    'q' => 'SAFE-PREFIX',
                    'status' => $status,
                    'page' => 1,
                ]));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $response->assertSee('name="q" value="SAFE-PREFIX"', false);
                $response->assertSee('name="tab" value="'.$tab.'"', false);
                $response->assertSee('name="store_id" value="'.$store->id.'"', false);
                $rows = $response->viewData('rows');
                $this->assertInstanceOf(\Illuminate\Pagination\LengthAwarePaginator::class, $rows);
                parse_str((string) parse_url($rows->url(2), PHP_URL_QUERY), $query);
                $this->assertSame($tab, $query['tab'] ?? null);
                $this->assertSame((string) $store->id, (string) ($query['store_id'] ?? ''));
                $this->assertSame('SAFE-PREFIX', $query['q'] ?? null);
                $this->assertSame($status, $query['status'] ?? null);
                $this->assertSame('2', (string) ($query['page'] ?? ''));

                $html = $response->getContent();
                if (in_array($tab, $embedded, true)) {
                    $this->assertSame(1, substr_count($html, 'class="erp-pagination"'));
                    $this->assertStringNotContainsString('data-procurement-footer-pagination', $html);
                } else {
                    $this->assertSame(1, substr_count($html, 'data-procurement-footer-pagination'));
                    $this->assertStringNotContainsString('class="erp-pagination"', $html);
                }
            }
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_procurement_denied_tabs_are_hidden_and_direct_get_denied_while_unknown_tab_is_404(): void
    {
        $store = $this->wholesaleStore();
        $owner = $this->actor('SUPER_ADMIN');
        $buyer = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );

        $deniedPermissions = [
            'procurement.suppliers.view' => ['overview', 'suppliers'],
            'procurement.purchase_orders.view' => ['orders', 'contracts'],
            'procurement.purchase_invoices.view' => ['invoices', 'matches'],
        ];
        foreach (array_keys($deniedPermissions) as $code) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'requests',
                'store_id' => $store->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false);
            $visible = collect($response->viewData('tabs'))->pluck('key')->all();
            foreach ($deniedPermissions as $permission => $tabs) {
                $this->assertFalse($buyer->fresh()->hasPermission($permission, $store->id));
                foreach ($tabs as $tab) {
                    $this->assertNotContains($tab, $visible);
                    $response->assertDontSee('data-procurement-tab="'.$tab.'"', false);
                    $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                        'tab' => $tab,
                        'store_id' => $store->id,
                    ]))->assertForbidden();
                }
            }
            foreach (['requests', 'rfqs', 'receipts', 'returns', 'statements', 'reports'] as $tab) {
                $this->assertContains($tab, $visible);
                $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                    'tab' => $tab,
                    'store_id' => $store->id,
                ]))->assertOk();
            }
            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'tab' => 'forged_unregistered_tab',
                'store_id' => $store->id,
            ]))->assertNotFound();
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_authorized_purchase_request_deep_link_cannot_open_a_foreign_store_record(): void
    {
        $primary = $this->wholesaleStore();
        $sibling = Store::query()->create([
            'store_type_id' => $primary->store_type_id,
            'code' => 'P111-TAB-FOREIGN-DEEP-LINK',
            'name' => 'P111 separate B2B deep-link store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($sibling);
        $owner = $this->actor('SUPER_ADMIN');
        $maker = $this->actor('PROCUREMENT_BUYER');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement', EntitlementScope::platform(), true, null, $owner,
        );
        $context = app(TenantContextResolver::class)->wholesale($maker, $primary->id);
        $record = app(PurchaseRequestManager::class)->create($maker, $context, [
            'currency_code' => 'KWD',
            'reason' => 'P111 authorized deep link never crosses Store A to B',
            'lines' => [[
                'description' => 'P111 scoped deep link',
                'quantity' => '1.000',
                'unit' => 'case',
                'estimated_unit_cost' => '1.000',
            ]],
        ]);
        $auditBefore = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $primary->id,
                'tab' => 'requests',
                'request' => $record->id,
            ]))->assertOk()->assertSee('dir="'.$direction.'"', false)
                ->assertSee($record->request_number);

            $this->actingAs($maker->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $sibling->id,
                'tab' => 'requests',
                'request' => $record->id,
            ]))->assertNotFound();
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
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
