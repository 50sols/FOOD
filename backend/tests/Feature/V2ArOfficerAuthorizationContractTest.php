<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #2696 / T122R16: accounts receivable authority is Finance-module
 * gated, cannot approve or post journals, and cannot cross Retail stores.
 */
final class V2ArOfficerAuthorizationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_ar_officer_uses_the_canonical_bilingual_least_privilege_role(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('AR_OFFICER');

        $this->assertIsArray($definition);
        $this->assertSame('finance', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['finance'], $definition['required_modules']);
        $this->assertEqualsCanonicalizing(
            ['finance.view', 'finance.manage', 'reports.view'],
            $definition['permissions'],
        );

        $role = Role::query()->where('code', 'AR_OFFICER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing($definition['permissions'], $role->permissions()->pluck('code')->all());

        foreach (['ar' => 'مسؤول حسابات العملاء', 'en' => 'Accounts Receivable Officer'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('AR_OFFICER');

            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }

        foreach ([
            'finance.post',
            'finance.approve',
            'reports.export',
            'security.view',
            'roles.manage',
            'system.update',
            'orders.dispatch',
            'inventory.adjust',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $definition['permissions']);
        }
    }

    public function test_finance_lifecycle_revokes_ar_access_without_deleting_role_assignment(): void
    {
        app(ModuleRegistry::class)->syncManifest('finance');
        $actor = $this->officer();
        $catalog = app(BuiltInJobRoleCatalog::class);
        $role = Role::query()->where('code', 'AR_OFFICER')->firstOrFail();

        $this->assertFalse($catalog->isAvailable('AR_OFFICER'));
        $this->assertFalse($actor->hasPermission('finance.view'));
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [], false))->assertForbidden();

        $this->enableFinance();
        $this->assertTrue($catalog->isAvailable('AR_OFFICER'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.manage'));
        $this->assertTrue($actor->fresh()->hasPermission('reports.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
        $this->assertFalse($actor->fresh()->hasPermission('reports.export'));

        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'roles'], false))
            ->assertForbidden();

        app(ModuleLifecycleManager::class)->transition('finance', ModuleState::DISABLED);

        $this->assertFalse($catalog->isAvailable('AR_OFFICER'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', [], false))
            ->assertForbidden();
    }

    public function test_explicit_manage_deny_does_not_revoke_receivables_read_access(): void
    {
        $this->enableFinance();
        $actor = $this->officer();

        $this->assertTrue($actor->hasPermission('finance.manage'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'finance.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.approve'));
    }

    public function test_global_ar_officer_cannot_use_its_finance_role_on_an_unrelated_retail_store(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-AR-OFFICER-RETAIL',
            'name' => 'Unrelated retail tenant for AR regression',
            'is_active' => true,
        ]);

        $retailOperations = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $retailOperations->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.view', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage', $store->id));
        $this->assertFalse($actor->fresh()->hasPermission('finance.post', $store->id));

        $this->actingAs($actor->fresh())
            ->get(route('admin.erp.finance.index', ['store_id' => $store->id], false))
            ->assertForbidden();
    }

    public function test_deactivated_officer_role_cannot_keep_finance_privileges(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $role = Role::query()->where('code', 'AR_OFFICER')->firstOrFail();

        $this->assertTrue($actor->hasPermission('finance.view'));
        $role->forceFill(['is_active' => false])->save();

        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));
        $this->assertDatabaseHas('role_user', [
            'user_id' => $actor->id,
            'role_id' => $role->id,
        ]);
    }

    /**
     * A global AR operator can prepare receivables only through an authorized
     * AR workflow. It cannot forge generic GL posting or maker/checker
     * decisions through direct HTTP requests, regardless of locale.
     */
    public function test_ar_officer_cannot_post_or_approve_general_ledger_operations_by_direct_http(): void
    {
        $this->enableFinance();
        $actor = $this->officer();
        $wholesaleId = (int) DB::table('stores as store')
            ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
            ->where('type.code', 'B2B')
            ->where('store.is_active', true)
            ->value('store.id');
        $this->assertGreaterThan(0, $wholesaleId);

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'ledger' => DB::table('enterprise_finance_ledger_entries')->count(),
        ];

        $attempts = [
            ['admin.erp.finance.journals.store', []],
            ['admin.erp.finance.journals.approval', ['journal' => 999999]],
            ['admin.erp.finance.journals.post', ['journal' => 999999]],
            ['admin.erp.finance.opening-balances.approval', ['batch' => 999999]],
            ['admin.erp.finance.opening-balances.post', ['batch' => 999999]],
        ];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $principal = $actor->fresh();

            $this->assertTrue($principal->hasPermission('finance.manage'));
            $this->assertFalse($principal->hasPermission('finance.post'));
            $this->assertFalse($principal->hasPermission('finance.approve'));

            foreach ($attempts as [$routeName, $routeParameters]) {
                $this->assertTrue(Route::has($routeName), $routeName);
                $response = $this->actingAs($principal)->post(
                    route($routeName, $routeParameters, false),
                    ['store_id' => $wholesaleId, 'idempotency_key' => '2696-gl-denied'],
                );
                $this->assertContains(
                    $response->getStatusCode(),
                    [403, 404],
                    "{$routeName}: {$locale}: direct GL operation must remain denied.",
                );
            }
        }

        $this->assertSame($before['journals'], DB::table('enterprise_finance_journals')->count());
        $this->assertSame($before['ledger'], DB::table('enterprise_finance_ledger_entries')->count());
    }

    private function officer(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'AR_OFFICER')->firstOrFail());

        return $actor->fresh();
    }

    private function enableFinance(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->registration('finance') === null) {
            $registry->syncManifest('finance');
        }

        if (! $registry->isEnabled('finance')) {
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLED);
        }
    }
}
