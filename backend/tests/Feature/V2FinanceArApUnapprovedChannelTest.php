<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #3872 / T353R5: enabled Finance is NOT an implicit channel assignment
 * or legal-entity/store Finance ERP entitlement for AR/AP operator roles.
 */
final class V2FinanceArApUnapprovedChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_enabled_ar_ap_builtins_do_not_bypass_wholesale_channel_or_enterprise_finance_entitlements(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('finance') === null) {
            $registry->syncManifest('finance');
        }
        app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLED);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            $registry->manifest('finance')->version,
        );
        $this->assertTrue($registry->isEnabled('finance'));

        $wholesale = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($wholesale);
        $retail = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-3872-RETAIL',
            'name' => 'V2-3872 unapproved role Retail',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($retail);

        // The module is actually ENABLED and readiness-certified. No
        // independent legal-entity/store entitlements have been provisioned.
        $resolver = app(ModuleEntitlementResolver::class);
        $this->assertFalse($resolver->allows('finance', new EntitlementScope(
            EntitlementScope::STORE,
            $wholesale->id,
        )));
        $this->assertFalse($resolver->allows('finance', new EntitlementScope(
            EntitlementScope::STORE,
            $retail->id,
        )));

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'entries' => DB::table('enterprise_finance_ledger_entries')->count(),
        ];

        foreach (['AR_OFFICER', 'AP_OFFICER'] as $roleCode) {
            $catalog = app(BuiltInJobRoleCatalog::class);
            $definition = $catalog->definition($roleCode);
            $this->assertIsArray($definition);
            $this->assertSame(['finance'], $definition['required_modules']);
            $this->assertTrue($catalog->isAvailable($roleCode));
            $this->assertNotContains($roleCode, config('admin.channels.b2b.global_roles'));
            $this->assertNotContains($roleCode, config('admin.channels.b2c.global_roles'));

            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
            $actor->roles()->attach($role);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();
                $principal = $actor->fresh();
                $this->actingAs($principal);

                // Role grants are effective; separate channel and entitlement
                // gates still prohibit a real operational Finance workspace.
                $this->assertTrue($principal->hasPermission('finance.view'), $roleCode);
                $this->assertTrue($principal->hasPermission('finance.manage'), $roleCode);
                $this->assertTrue($principal->hasPermission('reports.view'), $roleCode);
                foreach (['finance.post', 'finance.approve', 'security.approve'] as $forbidden) {
                    $this->assertFalse($principal->hasPermission($forbidden), $roleCode);
                }
                $this->assertFalse($principal->hasPermission('finance.view', $retail->id));

                app()->forgetInstance(AdminNavigation::class);
                $navigation = app(AdminNavigation::class);
                $this->assertFalse($navigation->canAccess($principal, 'b2b'));
                $this->assertFalse($navigation->canAccess($principal, 'b2c'));

                $keys = collect($navigation->groupsFor($principal))
                    ->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key');
                foreach (['erp_finance', 'erp_receivables', 'erp_payables'] as $forbiddenKey) {
                    $this->assertFalse(
                        $keys->contains($forbiddenKey),
                        "{$roleCode} / {$locale} leaked {$forbiddenKey} without Finance entitlement.",
                    );
                }

                foreach ([
                    ['admin.erp.finance.index', ['store_id' => $wholesale->id, 'tab' => 'accounts']],
                    ['admin.erp.finance.index', ['store_id' => $wholesale->id, 'tab' => 'receivables']],
                    ['admin.erp.finance.index', ['store_id' => $wholesale->id, 'tab' => 'payables']],
                    ['admin.erp.finance.index', ['store_id' => $retail->id, 'tab' => 'accounts']],
                ] as [$routeName, $parameters]) {
                    $this->assertTrue(Route::has($routeName));
                    $response = $this->get(route($routeName, $parameters, false));
                    $this->assertContains(
                        $response->getStatusCode(),
                        [403, 404],
                        "{$roleCode} / {$locale} leaked {$routeName} HTTP {$response->getStatusCode()}",
                    );
                }

                $this->assertDatabaseHas('role_user', [
                    'user_id' => $actor->id,
                    'role_id' => $role->id,
                ]);
            }
        }

        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['entries'], DB::table('enterprise_finance_ledger_entries')->count());
    }
}
