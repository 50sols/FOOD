<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: eleven tabs in ONE canonical Procurement HTTP route.
 * Readiness simulation is test-only; production has no certified version.
 */
final class V2ErpProcurementTabRouteMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const TAB_READ_PERMISSIONS = [
        'suppliers' => 'procurement.suppliers.view',
        'requests' => 'procurement.purchase_requests.view',
        'rfqs' => 'procurement.purchase_requests.view',
        'orders' => 'procurement.purchase_orders.view',
        'contracts' => 'procurement.purchase_orders.view',
        'receipts' => 'procurement.goods_receipts.view',
        'invoices' => 'procurement.purchase_invoices.view',
        'matches' => 'procurement.purchase_invoices.view',
        'returns' => 'procurement.purchase_returns.view',
        'statements' => 'procurement.supplier_statements.view',
        'reports' => 'procurement.supplier_statements.view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Synthetic Ed25519 evidence and exact version live inside PHPUnit.
        // Neither production keys nor any live certification is generated.
        SyntheticErpWorkflowEvidence::install();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            EntitlementScope::platform(),
            true,
        );
    }

    public function test_all_eleven_existing_tabs_allow_direct_authorized_ready_http_in_ar_and_en(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();

        $this->assertSame('ready', $this->readiness());
        $this->assertCount(11, self::TAB_READ_PERMISSIONS);
        $this->assertSame('global', Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()->scope);

        // There is exactly one canonical Procurement navigation destination,
        // not one new Sidebar item per tab. HTTP remains the authority.
        $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
        $procurement = collect($groups)->firstWhere('key', 'procurement');
        $this->assertIsArray($procurement);
        $links = collect($procurement['children'])
            ->filter(static fn (array $item): bool => ($item['route'] ?? null) === 'admin.erp.procurement.index');
        $this->assertCount(1, $links);
        $this->assertSame('erp_procurement', $links->first()['key']);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertTrue(
                    $actor->fresh()->hasPermission($permission, $store->id),
                    "{$locale}/{$tab}: missing explicit HTTP read permission",
                );

                $response = $this->actingAs($actor->fresh())->get($this->tabUrl($tab, $store));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($tab, $response->viewData('activeTab'), "{$locale}/{$tab}: wrong active tab");
                $this->assertSame($store->id, $response->viewData('selectedStoreId'));
                $this->assertContains(
                    $tab,
                    collect($response->viewData('tabs'))->pluck('key')->all(),
                    "{$locale}/{$tab}: tab not projected for permitted store",
                );
            }
        }
    }

    public function test_all_eleven_direct_tabs_return_403_if_version_is_not_certified(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();

        // Keep role, actual store permissions, lifecycle, entitlement, health
        // and synthetic signed evidence valid. Only certification is missing.
        config()->set('enterprise_module_readiness.certified_versions.procurement', null);
        $this->assertSame('uncertified', $this->readiness());

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertTrue($actor->fresh()->hasPermission($permission, $store->id), "{$locale}/{$tab}");
                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl($tab, $store))
                    ->assertForbidden();
            }
        }
    }

    public function test_all_eleven_direct_tabs_return_403_when_permission_is_explicitly_denied(): void
    {
        $actor = $this->manager();
        $store = $this->wholesaleStore();
        $this->denyPermissions($actor, array_values(self::TAB_READ_PERMISSIONS), null);
        $this->assertSame('ready', $this->readiness());

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertFalse($actor->fresh()->hasPermission($permission, $store->id), "{$locale}/{$tab}");
                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl($tab, $store))
                    ->assertForbidden();
            }
        }
    }

    public function test_all_eleven_direct_tabs_deny_an_excluded_b2b_store_without_denying_another(): void
    {
        $actor = $this->manager();
        $owned = $this->wholesaleStore();
        $foreign = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-FOREIGN-B2B-TABS',
            'name' => 'P135 foreign Wholesale store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($foreign);

        // Wholesale global roles otherwise span Wholesale stores. Explicit
        // per-store DENY is the genuine authorization boundary here; never
        // claim all B2B stores are foreign to a globally assigned manager.
        $this->denyPermissions($actor, array_values(self::TAB_READ_PERMISSIONS), $foreign->id);
        $this->assertSame('ready', $this->readiness());

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            foreach (self::TAB_READ_PERMISSIONS as $tab => $permission) {
                $this->assertTrue($actor->fresh()->hasPermission($permission, $owned->id), "{$locale}/{$tab}/owned");
                $this->assertFalse($actor->fresh()->hasPermission($permission, $foreign->id), "{$locale}/{$tab}/foreign");

                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl($tab, $foreign))
                    ->assertForbidden();

                $response = $this->actingAs($actor->fresh())->get($this->tabUrl($tab, $owned));
                $response->assertOk()->assertSee('dir="'.$direction.'"', false);
                $this->assertSame($tab, $response->viewData('activeTab'));
                $this->assertSame($owned->id, $response->viewData('selectedStoreId'));
            }
        }
    }

    public function test_direct_procurement_http_revokes_inactive_and_archived_roles_even_with_signed_test_readiness(): void
    {
        $manager = $this->manager();
        $store = $this->wholesaleStore();
        $buyer = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $buyerRole = Role::query()->where('code', 'PROCUREMENT_BUYER')->firstOrFail();
        $buyer->roles()->attach($buyerRole);

        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())
            ->get($this->tabUrl('suppliers', $store))
            ->assertOk();
        $this->actingAs($buyer->fresh())
            ->get($this->tabUrl('suppliers', $store))
            ->assertOk();

        // A disabled role keeps its stored assignment for audit history,
        // but may no longer supply any operational permission or link.
        Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()
            ->forceFill(['is_active' => false])->save();
        $buyerRole->forceFill([
            'is_active' => false,
            'archived_at' => now(),
        ])->save();

        $before = DB::table('audit_logs')->count();
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            foreach ([$manager, $buyer] as $actor) {
                $actor->forceFill(['locale' => $locale])->save();
                $this->assertFalse(
                    $actor->fresh()->hasPermission('procurement.suppliers.view', $store->id),
                    $locale.' must not inherit inactive/archived role rights',
                );
                $this->actingAs($actor->fresh())
                    ->get($this->tabUrl('suppliers', $store))
                    ->assertForbidden();
            }
        }

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
        $this->assertDatabaseHas('role_user', [
            'user_id' => $buyer->id,
            'role_id' => $buyerRole->id,
        ]);
    }

    public function test_forged_procurement_store_ids_and_retail_to_wholesale_direct_urls_never_cross_channel(): void
    {
        $manager = $this->manager();
        $wholesale = $this->wholesaleStore();
        $retail = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P135-RETAIL-CHANNEL-DENY',
            'name' => 'P135 Retail channel scope denial',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        $retailActor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retailActor->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'store_id' => $retail->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleless = User::factory()->create(['locale' => 'en', 'is_active' => true]);

        $this->assertSame('ready', $this->readiness());
        $this->actingAs($manager->fresh())->get($this->tabUrl('suppliers', $wholesale))->assertOk();
        $before = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['suppliers', 'orders', 'receipts'] as $tab) {
                // A Wholesale global Procurement role is not Retail scope.
                $this->actingAs($manager->fresh())
                    ->get($this->tabUrl($tab, $retail))
                    ->assertForbidden();
                $this->actingAs($manager->fresh())
                    ->get(route('admin.erp.procurement.index', [
                        'store_id' => 999999999,
                        'tab' => $tab,
                    ]))
                    ->assertForbidden();

                // A Retail assignment does not authorize forged B2B store_id,
                // even with globally enabled and test-only signed Procurement.
                $this->actingAs($retailActor->fresh())
                    ->get($this->tabUrl($tab, $wholesale))
                    ->assertForbidden();
                $this->actingAs($roleless->fresh())
                    ->get($this->tabUrl($tab, $wholesale))
                    ->assertForbidden();
            }
        }

        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
        $this->assertDatabaseCount('enterprise_goods_receipts', 0);
    }

    private function readiness(): string
    {
        $registry = app(ModuleRegistry::class);

        return app(ModuleOperationalReadiness::class)->status(
            'procurement',
            $registry->manifest('procurement')->version,
            $registry->state('procurement'),
        );
    }

    private function manager(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail());

        return $actor->fresh();
    }

    private function wholesaleStore(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function tabUrl(string $tab, Store $store): string
    {
        return route('admin.erp.procurement.index', [
            'store_id' => $store->id,
            'tab' => $tab,
        ]);
    }

    /** @param list<string> $permissions */
    private function denyPermissions(User $actor, array $permissions, ?int $storeId): void
    {
        $rows = [];
        foreach (array_unique($permissions) as $code) {
            $rows[] = [
                'user_id' => $actor->id,
                'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
                'scope_key' => $storeId === null ? 'global' : 'store:'.$storeId,
                'store_id' => $storeId,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('user_permission_overrides')->insert($rows);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}
