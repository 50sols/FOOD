<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 #3863 / T353R5: a persisted specialist role is not usable authority
 * when its mandatory Enterprise module is unavailable.
 */
final class V2FinanceSpecialistModuleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_finance_and_treasury_specialist_role_assignments_are_inert_when_required_modules_are_disabled(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $registry = app(ModuleRegistry::class);
        $this->assertFalse($registry->isEnabled('finance'));
        $this->assertFalse($registry->isEnabled('treasury'));

        $wholesaleStoreId = $this->store('B2B', 'V2-3863-WHOLESALE');
        $retailStoreId = $this->store('B2C', 'V2-3863-RETAIL');

        $before = [
            'journals' => DB::table('enterprise_finance_journals')->count(),
            'entries' => DB::table('enterprise_finance_ledger_entries')->count(),
            'receipts' => DB::table('enterprise_treasury_receipts')->count(),
        ];

        foreach ([
            'AR_OFFICER' => 'finance',
            'AP_OFFICER' => 'finance',
            'TREASURY_OFFICER' => 'treasury',
        ] as $roleCode => $requiredModule) {
            $definition = $catalog->definition($roleCode);
            $this->assertIsArray($definition, $roleCode);
            $this->assertSame([$requiredModule], $definition['required_modules'], $roleCode);
            $this->assertEqualsCanonicalizing(
                ['finance.view', 'finance.manage', 'reports.view'],
                $definition['permissions'],
                $roleCode,
            );
            $this->assertFalse($catalog->isAvailable($roleCode), $roleCode);

            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $this->assertEqualsCanonicalizing(
                $definition['permissions'],
                $role->permissions()->pluck('code')->all(),
                $roleCode,
            );

            $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
            $actor->roles()->attach($role);

            foreach (['ar', 'en'] as $locale) {
                app()->setLocale($locale);
                $actor->forceFill(['locale' => $locale])->save();
                $principal = $actor->fresh();

                $this->assertFalse($principal->hasPermission('finance.view'), $roleCode);
                $this->assertFalse($principal->hasPermission('finance.manage'), $roleCode);
                $this->assertFalse($principal->hasPermission('reports.view'), $roleCode);
                foreach (['finance.post', 'finance.approve', 'security.approve'] as $forbidden) {
                    $this->assertFalse($principal->hasPermission($forbidden), $roleCode);
                }

                foreach ([$wholesaleStoreId, $retailStoreId] as $storeId) {
                    $this->assertFalse($principal->hasPermission('finance.view', $storeId));
                }

                app()->forgetInstance(AdminNavigation::class);
                $groups = collect(app(AdminNavigation::class)->groupsFor($principal));
                $keys = $groups->flatMap(static fn (array $group): array => $group['children'])
                    ->pluck('key');

                foreach ([
                    'erp_finance',
                    'erp_treasury',
                    'erp_receivables',
                    'erp_payables',
                    'treasury_master',
                    'reports_center',
                ] as $forbiddenKey) {
                    $this->assertFalse(
                        $keys->contains($forbiddenKey),
                        "{$roleCode} / {$locale} exposed {$forbiddenKey} with its required module disabled.",
                    );
                }

                foreach ([
                    ['admin.erp.finance.index', ['store_id' => $wholesaleStoreId, 'tab' => 'accounts']],
                    ['admin.erp.finance.index', ['store_id' => $wholesaleStoreId, 'tab' => 'receivables']],
                    ['admin.erp.finance.index', ['store_id' => $wholesaleStoreId, 'tab' => 'payables']],
                    ['admin.erp.treasury.index', ['store_id' => $wholesaleStoreId]],
                    ['admin.treasury.master.index', ['store_id' => $wholesaleStoreId]],
                    ['admin.reports.index', []],
                ] as [$routeName, $parameters]) {
                    $this->assertTrue(Route::has($routeName), "Missing canonical route {$routeName}");
                    $response = $this->actingAs($principal)
                        ->get(route($routeName, $parameters, false));
                    $this->assertContains(
                        $response->getStatusCode(),
                        [403, 404],
                        "{$roleCode} / {$locale} / {$routeName} leaked HTTP {$response->getStatusCode()}",
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
        $this->assertSame($before['receipts'], DB::table('enterprise_treasury_receipts')->count());
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
