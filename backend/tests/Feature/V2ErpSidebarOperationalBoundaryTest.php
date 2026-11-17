<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Http\Middleware\EnsureErpOperationalReadiness;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseModuleAdministration;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class V2ErpSidebarOperationalBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('enterprise_module_readiness.certified_versions', []);
        $this->wholesaleStore();
    }

    public function test_enabled_entitled_sidebar_module_is_not_a_direct_operational_grant(): void
    {
        $actor = $this->superAdmin('v2-erp-uncertified@example.test');
        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $actor);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();

        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('degraded', $card['status']);
        $this->assertNull($card['url']);

        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
        $this->post(route('admin.erp.procurement.requests.store'), [])->assertForbidden();

        $version = app(ModuleRegistry::class)->manifest('procurement')->version;
        config()->set('enterprise_module_readiness.certified_versions.procurement', $version + 1);
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_platform_owner_without_an_active_wholesale_store_keeps_read_only_setup_diagnostics(): void
    {
        $this->app['env'] = 'production';
        Store::query()->where('code', 'V2-ERP-BOUNDARY-WHOLESALE')->update(['is_active' => false]);
        $actor = $this->superAdmin('v2-erp-no-b2b-diagnostics@example.test');

        // A visibility-only exception must never create a B2B store, scope,
        // certified module or operational authorization.
        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor, 'procurement.suppliers.view', 'B2B'));

        $center = $this->actingAs($actor)->get(route('admin.erp.index'));
        $center->assertOk();

        $card = collect($center->viewData('cards'))->firstWhere('key', 'procurement');
        $this->assertIsArray($card);
        $this->assertSame('setup_required', $card['status']);
        $this->assertNull($card['url']);
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_platform_entitlement_without_procurement_role_does_not_reveal_the_module(): void
    {
        $this->app['env'] = 'production';
        $owner = $this->superAdmin('v2-erp-module-owner@example.test');
        $finance = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $finance->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());

        app(EnterpriseModuleAdministration::class)->perform('procurement', 'enable', $owner);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );

        $center = $this->actingAs($finance)->get(route('admin.erp.index'));
        $center->assertOk()
            ->assertDontSee('data-erp-key="procurement"', false);
        $this->assertNull(collect($center->viewData('cards'))->firstWhere('key', 'procurement'));
        $this->get(route('admin.erp.procurement.index'))->assertForbidden();
    }

    public function test_uncertified_module_routes_fail_closed_for_all_erp_domains(): void
    {
        $this->actingAs($this->superAdmin('v2-erp-cross-module@example.test'));

        foreach ([
            'admin.erp.wms.index',
            'admin.erp.finance.index',
            'admin.erp.treasury.index',
            'admin.erp.bi',
        ] as $routeName) {
            $this->get(route($routeName))->assertForbidden();
        }
    }

    public function test_every_erp_business_route_is_covered_by_the_server_guard(): void
    {
        foreach ([
            'admin.erp.procurement.index',
            'admin.erp.procurement.requests.store',
            'admin.erp.wms.index',
            'admin.erp.wms.transfers.store',
            'admin.erp.finance.index',
            'admin.erp.finance.journals.post',
            'admin.erp.treasury.index',
            'admin.erp.treasury.payments.post',
            'admin.erp.bi',
            'admin.erp.bi.schedules.store',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains(
                EnsureErpOperationalReadiness::class,
                $route->gatherMiddleware(),
                $routeName,
            );
        }

        $this->assertNotNull(Route::getRoutes()->getByName('admin.erp.index'));
    }

    private function superAdmin(string $email): User
    {
        $user = User::query()->create([
            'name' => 'V2 ERP Boundary',
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'V2-ERP-BOUNDARY-WHOLESALE',
            'name' => 'V2 ERP Boundary Wholesale',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
