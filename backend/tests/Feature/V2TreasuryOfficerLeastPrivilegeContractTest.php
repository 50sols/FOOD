<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T122R19: Treasury Officer is a bounded operator, not a finance approver
 * or a way to bypass a disabled ERP module through a stale role assignment.
 */
final class V2TreasuryOfficerLeastPrivilegeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_treasury_officer_role_definition_has_no_post_approve_export_or_dispatch_authority(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definitions()->get('TREASURY_OFFICER');
        $this->assertNotNull($definition);
        $this->assertSame('operator', $definition['level']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['treasury'], $definition['required_modules']);

        $expected = ['finance.view', 'finance.manage', 'reports.view'];
        $this->assertEqualsCanonicalizing($expected, $definition['permissions']);

        $role = Role::query()->where('code', 'TREASURY_OFFICER')->firstOrFail();
        $this->assertEqualsCanonicalizing($expected, $role->permissions()->pluck('code')->all());
        foreach ([
            'finance.post', 'finance.approve', 'reports.export',
            'orders.dispatch', 'procurement.purchase_orders.manage',
            'settings.manage', 'security.roles.manage',
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $definition['permissions']);
            $this->assertFalse($role->permissions()->where('code', $forbidden)->exists(), $forbidden);
        }
    }

    public function test_disabled_treasury_module_denies_stale_officer_role_and_direct_workbench_http_in_both_locales(): void
    {
        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('treasury');
        $lifecycle = app(ModuleLifecycleManager::class);
        $state = $registry->state('treasury');
        if ($state === ModuleState::ENABLED) {
            $lifecycle->transition('treasury', ModuleState::DISABLED);
        } elseif ($state === ModuleState::INSTALLED) {
            $lifecycle->transition('treasury', ModuleState::DISABLED);
        }

        $catalog = app(BuiltInJobRoleCatalog::class);
        $this->assertFalse($catalog->isAvailable('TREASURY_OFFICER'));

        $role = Role::query()->where('code', 'TREASURY_OFFICER')->firstOrFail();
        $officer = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        // A historical role_user link survives a disabled module, but grants
        // no effective privilege until module/entitlement readiness is approved.
        $officer->roles()->attach($role);
        $this->assertDatabaseHas('role_user', [
            'role_id' => $role->id,
            'user_id' => $officer->id,
        ]);
        $this->assertFalse($officer->fresh()->hasPermission('finance.view'));
        $this->assertFalse($officer->fresh()->hasPermission('finance.manage'));
        $this->assertFalse($officer->fresh()->hasPermission('finance.post'));

        $before = [];
        foreach (['orders', 'payments', 'invoices', 'driver_assignments'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach (['ar', 'en'] as $locale) {
            $officer->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($officer->fresh())
                ->get(route('admin.erp.treasury.index', [
                    'store_id' => '999999',
                    'channel' => 'b2c',
                    'tab' => 'receipts',
                ]))
                ->assertForbidden();
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' mutated during denied Treasury GET');
        }
    }
}
