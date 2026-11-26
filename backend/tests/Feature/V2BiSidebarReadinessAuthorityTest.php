<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * V2 #2642 / P312: BI navigation and the real HTTP page must enforce the
 * same global-role, module readiness and effective tenant entitlement.
 */
final class V2BiSidebarReadinessAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only simulation; never a production ERP certification.
        SyntheticErpWorkflowEvidence::install();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
    }

    public function test_entitled_store_analyst_can_find_and_open_the_canonical_bi_page_in_both_locales(): void
    {
        $this->enableBi();
        $store = $this->store('V2-P312-ALLOWED');
        $actor = $this->analyst($store);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $item = $this->biNavItem($actor->fresh());
            $this->assertNotNull($item);
            $this->assertSame('admin.erp.bi', $item['route']);

            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi'))
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi')
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_foreign_store_entitlement_cannot_override_explicit_store_deny_or_become_a_sidebar_grant(): void
    {
        $this->enableBi();
        $assigned = $this->store('V2-P312-MY-STORE');
        $foreign = $this->store('V2-P312-FOREIGN');
        $actor = $this->analyst($assigned);

        // The global Wholesale reporting role can reach B2B stores, but an
        // explicit user-level foreign-store DENY still takes precedence over
        // that role and the other store's module entitlement.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'reports.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$foreign->id,
            'store_id' => $foreign->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $foreign->id),
            true,
        );

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $this->assertNull($this->biNavItem($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', ['scope' => 'store:'.$foreign->id, 'run' => 1]))
                ->assertForbidden();
        }

        // An independently entitled and permitted Wholesale store restores
        // access without revoking or weakening the foreign-store DENY.
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $assigned->id),
            true,
        );
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNotNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertOk();
        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.bi', ['scope' => 'store:'.$foreign->id, 'run' => 1]))
            ->assertForbidden();
    }

    public function test_retail_bi_http_and_sidebar_revoke_access_when_assigned_store_permission_is_denied(): void
    {
        $this->enableBi();

        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-P312-RETAIL-DENY',
            'name' => 'V2 P312 Retail BI',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        // Keep the global reporting role and the exact Retail module grant
        // alive. Only the store-specific reporting permission is withdrawn.
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'EXECUTIVE_VIEWER')->firstOrFail());
        $retailRole = Role::query()
            ->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')
            ->firstOrFail();
        $reportsPermission = Permission::query()->where('code', 'reports.view')->firstOrFail();
        $retailRole->permissions()->syncWithoutDetaching([$reportsPermission->id]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'role_id' => $retailRole->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );

        app()->forgetInstance(AdminNavigation::class);
        $this->assertNotNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertOk();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => $reportsPermission->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            // Neither navigation nor a forged direct GET can use the
            // outstanding entitlement after the user-level DENY.
            $this->assertNull($this->biNavItem($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi', [
                    'bi_tab' => 'reports',
                    'scope' => 'store:'.$store->id,
                    'run' => 1,
                ]))
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.bi'))
                ->assertForbidden();
        }
    }

    public function test_ready_module_without_entitlement_is_denied_even_for_a_privileged_role(): void
    {
        $this->enableBi();
        $actor = $this->superAdmin();

        $this->assertNull($this->biNavItem($actor));
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );
        app()->forgetInstance(AdminNavigation::class);

        $this->assertNotNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertOk();
    }

    public function test_uncertified_or_disabled_module_and_explicit_permission_deny_block_direct_http(): void
    {
        $this->enableBi();
        $actor = $this->superAdmin();
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );

        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', 999999);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biNavItem($actor));
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertForbidden();

        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
        $this->actingAs($actor)->get(route('admin.erp.bi'))->assertOk();

        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::DISABLED);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertForbidden();
    }

    public function test_multi_role_reporting_deny_and_roleless_entitled_actor_have_no_bi_access(): void
    {
        $this->enableBi();
        $store = $this->store('V2-P312-MULTI');
        $actor = $this->analyst($store);
        $actor->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            EntitlementScope::platform(),
            true,
        );

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'reports.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        app()->forgetInstance(AdminNavigation::class);

        $this->assertNull($this->biNavItem($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.erp.bi'))->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        app()->forgetInstance(AdminNavigation::class);
        $this->assertNull($this->biNavItem($roleless));
        $this->actingAs($roleless)->get(route('admin.erp.bi'))->assertForbidden();
    }

    public function test_scheduled_bi_page_rechecks_persisted_permission_revoke_after_sql_read_in_ar_and_en(): void
    {
        $this->enableBi();
        $store = $this->store('V2-P135-BI-MIDREAD-DENY');
        $actor = $this->analyst($store);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
        );
        $permission = Permission::query()->where('code', 'reports.view')->firstOrFail();
        $armed = false;
        $revoked = false;

        DB::listen(static function ($query) use ($actor, $permission, &$armed, &$revoked): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $revoked || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_scheduled_reports')) {
                return;
            }

            // The scheduled-report SQL has already fetched its rows. A fresh
            // persisted DENY must invalidate this very HTTP response.
            $armed = false;
            $revoked = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permission->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        $url = route('admin.erp.bi', ['bi_tab' => 'schedules']);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);

            $this->actingAs($actor)->get($url)
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi');

            $auditCount = DB::table('audit_logs')->count();
            $scheduleCount = DB::table('enterprise_scheduled_reports')->count();
            $exportCount = DB::table('enterprise_report_exports')->count();
            $revoked = false;
            $armed = true;
            try {
                $this->actingAs($actor)->get($url)->assertForbidden();
            } finally {
                $armed = false;
            }

            $this->assertTrue($revoked, 'The actual scheduled report SELECT must trigger revocation.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            $this->actingAs($actor)->get($url)->assertForbidden();

            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertSame($scheduleCount, DB::table('enterprise_scheduled_reports')->count());
            $this->assertSame($exportCount, DB::table('enterprise_report_exports')->count());

            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)
                ->where('permission_id', $permission->id)
                ->delete();
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => false]);
            app()->forgetInstance(AdminNavigation::class);
            $this->actingAs($actor)->get($url)
                ->assertOk()
                ->assertViewIs('admin.enterprise-bi');
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
        }
    }

    private function enableBi(): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('enterprise_bi', ModuleState::ENABLED);
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor->fresh();
    }

    private function store(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function analyst(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'EXECUTIVE_VIEWER')->firstOrFail());

        return $actor->fresh();
    }

    /** @return array<string, mixed>|null */
    private function biNavItem(User $actor): ?array
    {
        foreach (app(AdminNavigation::class)->groupsFor($actor) as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === 'erp_bi') {
                    return $item;
                }
            }
        }

        return null;
    }
}
