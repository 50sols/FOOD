<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T122R17: AP Officer baseline must not accidentally inherit approval,
 * posting, security administration, or unrelated channel privileges.
 *
 * This is a bounded role-catalog regression, not full AP workflow acceptance.
 */
final class V2AccountsPayableOfficerPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_ap_officer_catalog_is_tenant_scoped_and_does_not_grant_post_or_approval(): void
    {
        $definition = config('job_roles.roles.AP_OFFICER');

        $this->assertSame('finance', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('operator', $definition['level']);
        $this->assertContains('finance', $definition['required_modules']);
        $this->assertContains('finance.view', $definition['permissions']);
        $this->assertContains('finance.manage', $definition['permissions']);

        foreach ([
            '*',
            'finance.post',
            'finance.approve',
            'reports.export',
            'roles.manage',
            'users.roles.manage',
            'security.approve',
            'procurement.purchase_orders.issue',
            'procurement.goods_receipts.post',
            'orders.dispatch',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $definition['permissions'], $forbidden);
        }
    }

    public function test_ap_officer_effective_permissions_do_not_include_finance_post_or_security_management(): void
    {
        $role = Role::query()->where('code', 'AP_OFFICER')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach($role);

        // AP_OFFICER remains deliberately excluded from channel global roles.
        // A role catalog declaration alone is not an effective authorization grant.
        $this->assertFalse($actor->fresh()->hasPermission('finance.view'));
        $this->assertFalse($actor->fresh()->hasPermission('finance.manage'));

        foreach ([
            'finance.post',
            'finance.approve',
            'roles.manage',
            'users.roles.manage',
            'security.approve',
            'procurement.goods_receipts.post',
            'orders.dispatch',
        ] as $forbidden) {
            $this->assertFalse($actor->fresh()->hasPermission($forbidden), $forbidden);
        }
    }

    public function test_ap_officer_is_denied_direct_general_ledger_post_and_approval_routes_in_both_locales(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'AP_OFFICER')->firstOrFail());

        $registry = app(ModuleRegistry::class);
        if ($registry->registration('finance') === null) {
            $registry->syncManifest('finance');
        }
        if (! $registry->isEnabled('finance')) {
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLING);
            app(ModuleLifecycleManager::class)->transition('finance', ModuleState::ENABLED);
        }

        $b2bTypeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $this->assertGreaterThan(0, $b2bTypeId);
        $store = Store::query()->create([
            'store_type_id' => $b2bTypeId,
            'code' => 'V2-AP-OFFICER-DENY',
            'name' => 'AP Officer isolated wholesale security boundary',
            'is_active' => true,
        ]);
        $beforeJournals = DB::table('enterprise_finance_journals')->count();
        $beforeLedger = DB::table('enterprise_finance_ledger_entries')->count();

        $attempts = [
            ['admin.erp.finance.journals.store', []],
            ['admin.erp.finance.journals.approval', ['journal' => 999999]],
            ['admin.erp.finance.journals.post', ['journal' => 999999]],
            ['admin.erp.finance.opening-balances.approval', ['batch' => 999999]],
            ['admin.erp.finance.opening-balances.post', ['batch' => 999999]],
        ];

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach ($attempts as [$routeName, $parameters]) {
                $this->assertTrue(Route::has($routeName), $routeName);
                $response = $this->actingAs($actor->fresh())->post(
                    route($routeName, $parameters, false),
                    ['store_id' => $store->id, 'idempotency_key' => '2697-ap-denied'],
                );
                $this->assertContains(
                    $response->getStatusCode(),
                    [403, 404],
                    "{$routeName}: {$locale}: AP officer must not approve or post GL.",
                );
            }
        }
        $this->assertSame($beforeJournals, DB::table('enterprise_finance_journals')->count());
        $this->assertSame($beforeLedger, DB::table('enterprise_finance_ledger_entries')->count());
    }

    public function test_ap_officer_cannot_open_security_role_management_in_arabic_or_english(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'AP_OFFICER')->firstOrFail());

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.sidebar-access.roles', [], false))
                ->assertForbidden();
        }
    }
}
