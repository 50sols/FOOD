<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseModuleEntitlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class ErpCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        // Existing available-card fixtures are explicitly version-certified.
        config()->set('enterprise_module_readiness.certified_versions', [
            'procurement' => 2,
            'inventory_wms' => 1,
            'finance' => 1,
            'treasury' => 1,
            'enterprise_bi' => 1,
        ]);
        // Disposable signed evidence only for positive ERP Center test scenarios.
        // Production certification and trusted public keys stay empty by default.
        SyntheticErpWorkflowEvidence::install();
    }

    public function test_disabled_module_is_explicit_and_never_exposes_an_operational_cta(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-owner@example.test');
        $this->app['env'] = 'production';

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('ERP Center')
            ->assertSee('data-erp-key="procurement" data-erp-status="setup_required"', false)
            ->assertSee('Setup required')
            ->assertSee('dir="ltr"', false);
    }

    public function test_local_super_admin_bootstraps_all_erp_modules_and_platform_entitlements(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-modules@example.test');
        // Even local bootstrap needs a real store/legal-entity for Finance.
        $this->wholesaleStore('P135-LOCAL-FINANCE-STORE');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response->assertOk();

        foreach ([
            'procurement' => 'procurement',
            'wms' => 'inventory_wms',
            'finance' => 'finance',
            'treasury' => 'treasury',
            'bi' => 'enterprise_bi',
        ] as $cardKey => $moduleCode) {
            $response->assertSee(
                'data-erp-key="'.$cardKey.'" data-erp-status="available"',
                false,
            );

            $this->assertDatabaseHas('enterprise_modules', [
                'code' => $moduleCode,
                'state' => 'ENABLED',
            ]);

            $this->assertDatabaseHas('enterprise_module_entitlements', [
                'module_code' => $moduleCode,
                'scope_key' => 'platform:0',
                'is_enabled' => true,
            ]);
        }
    }

    public function test_local_super_admin_bootstraps_missing_enabled_erp_entitlement(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-bootstrap@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);

        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
        ]);

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);
    }

    public function test_local_super_admin_does_not_override_explicit_entitlement_deny(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            false,
            null,
            $actor,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="not_entitled"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => false,
        ]);
    }

    public function test_local_super_admin_bootstraps_platform_entitlement_when_only_narrower_scope_is_configured(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-local-narrower-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        EnterpriseModuleEntitlement::query()->create([
            'module_code' => 'procurement',
            'scope_type' => EntitlementScope::STORE,
            'scope_id' => 999,
            'scope_key' => 'store:999',
            'is_enabled' => false,
            'capabilities' => null,
        ]);

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false);

        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'store:999',
            'is_enabled' => false,
        ]);
    }

    public function test_production_super_admin_remains_deny_by_default_without_entitlement(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-production-deny@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        $this->app['env'] = 'production';

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="not_entitled"', false);

        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
        ]);
    }

    public function test_enabled_entitled_but_uncertified_erp_module_has_no_operational_link(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-uncertified@example.test');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));
        $response->assertOk();

        $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertNull($card['url']);
        $this->assertDatabaseHas('enterprise_modules', [
            'code' => 'procurement',
            'state' => 'ENABLED',
        ]);
    }

    public function test_enabled_and_entitled_module_exposes_only_its_canonical_destination(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-enabled@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['module' => 'procurement']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertSee(route('admin.erp.procurement.index'), false);

        // P238: ERP Center retains the canonical workbench journey; its
        // operational shortcut now lives in the Procurement business group.
        $procurementGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'procurement');
        $this->assertNotNull($procurementGroup);

        $procurement = collect($procurementGroup['children'])->firstWhere('key', 'erp_procurement');
        $this->assertNotNull($procurement);
        $this->assertSame('admin.erp.procurement.index', $procurement['route']);
        $this->assertSame([], $procurement['params']);
    }

    public function test_erp_navigation_source_uses_canonical_workbench_routes(): void
    {
        $navigation = file_get_contents(app_path('Support/AdminNavigation.php'));
        $center = file_get_contents(app_path('Http/Controllers/Admin/ErpCenterController.php'));

        $this->assertIsString($navigation);
        $this->assertIsString($center);

        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.wms.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
            'admin.erp.customer-service',
            'admin.erp.bi',
        ] as $route) {
            $this->assertStringContainsString("'".$route."'", $navigation);
            $this->assertStringContainsString("'".$route."'", $center);
        }
    }

    public function test_finance_actor_does_not_receive_procurement_or_wms_actions(): void
    {
        $actor = $this->userWithGlobalRole('FINANCE', 'ar', 'erp-finance@example.test');

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('مركز ERP')
            ->assertSee('dir="rtl"', false)
            ->assertDontSee('data-erp-key="procurement"', false)
            ->assertDontSee('data-erp-key="wms"', false);
    }

    public function test_erp_center_renders_authoritative_snapshot_drilldowns_and_accessible_analytics(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-snapshot@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));

        $response
            ->assertOk()
            ->assertSee('data-erp-kpis', false)
            ->assertSee('data-erp-kpi="pending_approvals"', false)
            ->assertSee('data-erp-kpi="overdue_work"', false)
            ->assertSee('data-erp-kpi="open_exceptions"', false)
            ->assertSee('data-erp-kpi="failed_reports"', false)
            ->assertSee('data-erp-kpi="module_health"', false)
            ->assertSee('data-erp-chart="workload"', false)
            ->assertSee('data-erp-chart="states"', false)
            ->assertSee('data-erp-my-work', false)
            ->assertSee('data-erp-activity', false)
            ->assertSee('data-erp-work-module="procurement"', false)
            ->assertSee(route('admin.action-center.index'), false)
            ->assertSee(route('admin.erp.procurement.index'), false);
    }

    public function test_failed_report_kpi_is_unavailable_without_bi_and_scope_aware_when_bi_is_operational(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-bi-admin@example.test');
        $this->app['env'] = 'production';

        $unavailable = $this->actingAs($admin)->get(route('admin.erp.index'));
        $unavailableKpi = collect($unavailable->viewData('kpis'))->firstWhere('key', 'failed_reports');

        $this->assertIsArray($unavailableKpi);
        $this->assertFalse($unavailableKpi['available']);
        $this->assertNull($unavailableKpi['value']);
        $this->assertNull($unavailableKpi['url']);

        app(EnterpriseModuleAdministration::class)->perform('enterprise_bi', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
            null,
            $admin,
        );

        $actor = $this->userWithGlobalRole('FINANCE', 'en', 'erp-bi-finance@example.test');
        $other = $this->userWithGlobalRole('FINANCE', 'en', 'erp-bi-other@example.test');

        foreach ([
            [$actor->id, 'erp-center-own-failure'],
            [$other->id, 'erp-center-other-failure'],
        ] as [$requestedBy, $key]) {
            DB::table('enterprise_report_exports')->insert([
                'report_code' => 'orders.summary',
                'scope_type' => 'platform',
                'scope_id' => null,
                'requested_by' => $requestedBy,
                'scheduled_report_id' => null,
                'query_payload' => '{}',
                'row_count' => 0,
                'execution_mode' => 'queued_chunked',
                'chunk_size' => 500,
                'next_offset' => 0,
                'processed_rows' => 0,
                'chunks_completed' => 0,
                'status' => 'failed',
                'failure_code' => 'source_unavailable',
                'artifact_metadata' => null,
                'idempotency_key' => hash('sha256', $key),
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($actor)->get(route('admin.erp.index'));
        $kpi = collect($response->viewData('kpis'))->firstWhere('key', 'failed_reports');

        $this->assertIsArray($kpi);
        $this->assertTrue($kpi['available']);
        $this->assertSame(1, $kpi['value']);
        $this->assertSame(route('admin.erp.bi'), $kpi['url']);
    }

    public function test_erp_center_state_filter_hides_other_module_cards_without_changing_navigation_authority(): void
    {
        $actor = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-state-filter@example.test');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $response = $this->actingAs($actor)->get(route('admin.erp.index', ['status' => 'available']));

        $response
            ->assertOk()
            ->assertSee('data-erp-key="procurement" data-erp-status="available"', false)
            ->assertDontSee('data-erp-key="wms" data-erp-status="disabled"', false);

        $erpGroup = collect(app(AdminNavigation::class)->groupsFor($actor))->firstWhere('key', 'erp');
        $this->assertNotNull($erpGroup);
        $this->assertNotNull(collect($erpGroup['children'])->firstWhere('key', 'erp_center'));
    }

    public function test_wholesale_procurement_store_entitlement_projects_existing_read_route_without_platform_grant(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-wholesale-setup@example.test');
        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'erp-wholesale-buyer@example.test');
        $store = $this->wholesaleStore('P135-READ-OWN');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );
        $this->assertDatabaseMissing('enterprise_module_entitlements', [
            'module_code' => 'procurement',
            'scope_key' => 'platform:0',
        ]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');

            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(route('admin.erp.procurement.index'), $card['url']);

            $group = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->firstWhere('key', 'procurement');
            $this->assertIsArray($group);
            $item = collect($group['children'])->firstWhere('key', 'erp_procurement');
            $this->assertIsArray($item);
            $this->assertSame('admin.erp.procurement.index', $item['route']);

            $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
                'store_id' => $store->id,
                'tab' => 'suppliers',
            ]))->assertOk();
        }
    }

    public function test_wholesale_procurement_read_projection_does_not_invent_an_entitlement_or_readiness(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'erp-wholesale-guard-admin@example.test');
        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'erp-wholesale-guard-buyer@example.test');
        $store = $this->wholesaleStore('P135-READ-DENIED');

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $buyer->forceFill(['locale' => $locale])->save();
            $response = $this->actingAs($buyer->fresh())->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);

            $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
            $this->assertIsArray($card);
            $this->assertSame('not_entitled', $card['status']);
            $this->assertSame('entitlement_scope', $card['denial_reason']);
            $this->assertNull($card['url']);
            $group = collect(app(AdminNavigation::class)->groupsFor($buyer->fresh()))
                ->firstWhere('key', 'procurement');
            $this->assertNull($group);
        }

        // Even a real scoped entitlement cannot override missing approval of
        // the exact module version: both Center and direct HTTP stay blocked.
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);

        // New actor avoids reusing an earlier request's navigation memo.
        $anotherBuyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'ar', 'erp-wholesale-no-cert@example.test');
        $response = $this->actingAs($anotherBuyer)->get(route('admin.erp.index'));
        $card = collect($response->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertSame('uncertified', $card['denial_reason']);
        $this->assertNull($card['url']);

        $this->actingAs($anotherBuyer)->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();
    }

    public function test_b2b_procurement_persona_http_enforces_channel_scope_and_explicit_store_denies(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-persona-setup@example.test');
        $owned = $this->wholesaleStore('P135-PERSONA-A');
        $other = $this->wholesaleStore('P135-PERSONA-B');
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-PERSONA-RETAIL',
            'name' => 'P135 Retail isolation',
            'is_active' => true,
        ]);

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);

        foreach (['PROCUREMENT_BUYER', 'PROCUREMENT_MANAGER', 'PROCUREMENT_APPROVER'] as $roleCode) {
            $actor = $this->userWithGlobalRole($roleCode, 'en', strtolower($roleCode).'@p135.test');
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $this->assertSame('global', $role->scope);
            $this->assertTrue($role->is_active);
            $this->assertNull($role->archived_at);
            $this->assertTrue($actor->hasRole($roleCode, $owned->id));
            $this->assertFalse($actor->hasRole($roleCode, $retail->id));

            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $owned->id,
                    'tab' => 'suppliers',
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

                $this->actingAs($actor->fresh())->get(route('admin.erp.procurement.index', [
                    'store_id' => $retail->id,
                    'tab' => 'suppliers',
                ]))->assertForbidden();
            }

            $this->assertSame($roleCode !== 'PROCUREMENT_APPROVER', $actor->fresh()->hasPermission(
                'procurement.suppliers.manage',
                $owned->id,
            ));
            $this->assertSame($roleCode !== 'PROCUREMENT_BUYER', $actor->fresh()->hasPermission(
                'procurement.purchase_requests.approve',
                $owned->id,
            ));
        }

        $buyer = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'p135-store-deny@example.test');
        foreach ([
            [$owned, 'P135-SUPPLIER-OWNED'],
            [$other, 'P135-SUPPLIER-OTHER'],
        ] as [$store, $code]) {
            $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
                'store_id' => $store->id,
                'scope_type' => 'store',
                'code' => $code,
                'name_en' => $code,
                'name_ar' => 'مورد اختبار النطاق',
                'payment_terms_days' => 30,
            ])->assertRedirect();
        }

        foreach (['procurement.suppliers.view', 'procurement.suppliers.manage'] as $permission) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $buyer->id,
                'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->id,
                'scope_key' => 'store:'.$owned->id,
                'store_id' => $owned->id,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $buyer->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($buyer->fresh()->hasPermission('procurement.suppliers.view', $owned->id));
        $this->assertTrue($buyer->fresh()->hasPermission('procurement.suppliers.view', $other->id));
        $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $owned->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();

        $this->actingAs($buyer->fresh())->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $owned->id,
            'scope_type' => 'store',
            'code' => 'P135-FORGED-DENIED',
            'name_en' => 'Denied Supplier',
            'name_ar' => 'مورد محجوب',
        ])->assertForbidden();
        $this->assertDatabaseMissing('enterprise_suppliers', ['code' => 'P135-FORGED-DENIED']);

        $page = $this->actingAs($buyer->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $other->id,
            'tab' => 'suppliers',
        ]))->assertOk();
        $codes = $page->viewData('rows')->getCollection()->pluck('code')->all();
        $this->assertContains('P135-SUPPLIER-OTHER', $codes);
        $this->assertNotContains('P135-SUPPLIER-OWNED', $codes);
    }

    public function test_archived_global_procurement_role_and_inactive_user_cannot_use_http_despite_saved_assignments(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-archived-setup@example.test');
        $store = $this->wholesaleStore('P135-ARCHIVE-STORE');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);

        $inactive = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'ar', 'p135-inactive-buyer@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $this->assertFalse($inactive->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->actingAs($inactive->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();

        $active = $this->userWithGlobalRole('PROCUREMENT_BUYER', 'en', 'p135-archived-buyer@example.test');
        $role = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $this->assertTrue($active->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->actingAs($active->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertOk();

        $role->forceFill(['is_active' => false, 'archived_at' => now()])->save();
        $this->assertFalse($active->fresh()->hasPermission('procurement.suppliers.view', $store->id));
        $this->actingAs($active->fresh())->get(route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => 'suppliers',
        ]))->assertForbidden();
        $this->assertDatabaseHas('role_user', [
            'user_id' => $active->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_ar_ap_and_treasury_officer_global_roles_do_not_bypass_wholesale_http_channel(): void
    {
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-officers-setup@example.test');
        $store = $this->wholesaleStore('P135-OFFICER-STORE');

        foreach (['finance', 'treasury'] as $module) {
            app(EnterpriseModuleAdministration::class)->perform($module, 'enable', $admin);
            app(ModuleEntitlementManager::class)->set($module, EntitlementScope::platform(), true);
        }

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'receipts' => DB::table('enterprise_treasury_receipts')->count(),
        ];

        foreach (['AR_OFFICER', 'AP_OFFICER', 'TREASURY_OFFICER'] as $roleCode) {
            $this->assertNotContains($roleCode, config('admin.channels.b2b.global_roles'));
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $this->assertSame('global', $role->scope);
            $this->assertTrue($role->is_active);
            $this->assertNull($role->archived_at);

            $actor = $this->userWithGlobalRole($roleCode, 'en', strtolower($roleCode).'@p135.test');
            $this->assertTrue($actor->hasRole($roleCode));
            $this->assertFalse($actor->hasRole($roleCode, $store->id));
            $this->assertFalse($actor->hasPermission('finance.view', $store->id));
            $this->assertFalse($actor->hasPermission('finance.post', $store->id));
            $this->assertFalse($actor->hasPermission('finance.approve', $store->id));

            foreach (['ar', 'en'] as $locale) {
                $actor->forceFill(['locale' => $locale])->save();
                foreach ([
                    ['admin.erp.finance.index', 'accounts'],
                    ['admin.erp.finance.index', 'payables'],
                    ['admin.erp.finance.index', 'receivables'],
                    ['admin.erp.treasury.index', 'receipts'],
                ] as [$name, $tab]) {
                    $response = $this->actingAs($actor->fresh())->get(route($name, [
                        'store_id' => $store->id,
                        'tab' => $tab,
                    ]));
                    $this->assertContains($response->status(), [403, 404]);
                }
            }
        }

        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['receipts'], DB::table('enterprise_treasury_receipts')->count());
    }

    public function test_finance_center_link_matches_real_accountant_legal_entity_and_store_http_in_both_locales(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-admin@example.test');
        $actor = $this->userWithGlobalRole('ACCOUNTANT', 'en', 'p135-finance-accountant@example.test');
        $store = $this->wholesaleStore('P135-FINANCE-ACCOUNTANT');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        $this->assertTrue($actor->fresh()->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve', $store->id));

        $card = collect($this->actingAs($actor)->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'accounts',
        ]))->assertForbidden();

        // Store-only module capability is NOT legal-entity ledger authority.
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::STORE, $store->id),
            true, ['finance.foundation.read'],
        );
        $card = collect($this->actingAs($actor)->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'accounts',
        ]))->assertForbidden();

        app(ModuleEntitlementManager::class)->set(
            'finance',
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.gl.read'],
        );

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();
            $response = $this->actingAs($user)->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');

            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.finance.index', ['store_id' => $store->id]),
                $card['url'],
            );
            $this->actingAs($user)->get($card['url'])
                ->assertOk()
                ->assertSee('data-finance-workbench="true"', false)
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $store->id, 'tab' => 'accounts',
            ]))->assertOk();
        }

        config()->set('enterprise_module_readiness.certified_versions.finance', null);
        $card = collect($this->actingAs($actor->fresh())->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertSame('uncertified', $card['denial_reason']);
        $this->assertNull($card['url']);
        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
        ]))->assertForbidden();
        config()->set('enterprise_module_readiness.certified_versions.finance', 1);

        // Child STORE deny overrides legal entity entitlement and permission.
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::STORE, $store->id),
            false,
        );
        $card = collect($this->actingAs($actor->fresh())->get(route('admin.erp.index'))
            ->assertOk()->viewData('cards'))->firstWhere('key', 'finance');
        $this->assertIsArray($card);
        $this->assertSame('not_entitled', $card['status']);
        $this->assertNull($card['url']);
        $this->actingAs($actor->fresh())->get(route('admin.erp.finance.index', [
            'store_id' => $store->id,
        ]))->assertForbidden();
        $this->assertDatabaseCount('enterprise_finance_journals', 0);
        $this->assertDatabaseCount('enterprise_finance_ledger_entries', 0);
    }

    public function test_finance_center_links_only_the_wholesale_store_the_real_workbench_can_read(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-b-admin@example.test');
        $actor = $this->userWithGlobalRole('ACCOUNTANT', 'en', 'p135-finance-b-accountant@example.test');
        $denied = $this->wholesaleStore('P135-FINANCE-A-DENY');
        $allowed = $this->wholesaleStore('P135-FINANCE-B-ALLOW');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($allowed->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);

        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read'],
        );
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::STORE, $denied->id),
            false,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $user = $actor->fresh();
            $response = $this->actingAs($user)->get(route('admin.erp.index'));
            $response->assertOk()->assertSee('dir="'.$direction.'"', false);
            $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');

            $this->assertIsArray($card);
            $this->assertSame('available', $card['status']);
            $this->assertSame(
                route('admin.erp.finance.index', ['store_id' => $allowed->id]),
                $card['url'],
            );
            $this->actingAs($user)->get($card['url'])->assertOk();
            // An unselected B2B store uses the canonical HTTP 404 boundary.
            $this->actingAs($user)->get(route('admin.erp.finance.index', [
                'store_id' => $denied->id,
            ]))->assertNotFound();
        }
    }

    public function test_global_catalog_ap_ar_officers_without_channel_admission_have_no_finance_shortcut(): void
    {
        config()->set('security.mfa.enforce_privileged', false);
        $admin = $this->userWithGlobalRole('SUPER_ADMIN', 'en', 'p135-finance-officers-setup@example.test');
        $store = $this->wholesaleStore('P135-FINANCE-AP-AR-STORE');
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(EnterpriseModuleAdministration::class)->perform('finance', 'enable', $admin);
        app(ModuleEntitlementManager::class)->set(
            'finance', new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read', 'finance.ap.read'],
        );

        foreach (['AR_OFFICER' => 'receivables', 'AP_OFFICER' => 'payables'] as $code => $tab) {
            $this->assertNotContains($code, config('admin.channels.b2b.global_roles'));
            $officer = $this->userWithGlobalRole($code, 'en', strtolower($code).'@p135-finance.test');
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                $officer->forceFill(['locale' => $locale])->save();
                $user = $officer->fresh();
                $response = $this->actingAs($user)->get(route('admin.erp.index'));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $card = collect($response->viewData('cards'))->firstWhere('key', 'finance');
                if (is_array($card)) {
                    $this->assertNotSame('available', $card['status']);
                    $this->assertNull($card['url']);
                }
                $this->actingAs($user)->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id, 'tab' => $tab,
                ]))->assertForbidden();
            }
        }
    }

    private function wholesaleStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertGreaterThan(0, $typeId);

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userWithGlobalRole(string $roleCode, string $locale, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}
