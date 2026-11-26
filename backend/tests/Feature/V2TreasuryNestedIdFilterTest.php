<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2TreasuryNestedIdFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only signed workflow fixture; production certification remains fail-closed.
        SyntheticErpWorkflowEvidence::install();
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_treasury_id_filters_reject_nested_get_after_authorization_without_financial_writes(): void
    {
        [$actor, $store] = $this->financeOperator('V2-4078-A', 'v2-4078-a@example.test');
        [, $otherStore] = $this->financeOperator('V2-4078-B', 'v2-4078-b@example.test');
        $this->enableTreasury($actor, $store);

        $before = [
            DB::table('enterprise_treasury_receipts')->count(),
            DB::table('enterprise_treasury_payments')->count(),
            DB::table('enterprise_treasury_cheques')->count(),
            DB::table('enterprise_treasury_reconciliations')->count(),
            DB::table('audit_logs')->count(),
        ];

        $panels = [
            'financing' => 'facility_id',
            'cheques' => 'bank_account_id',
            'reconciliation' => 'bank_account_id',
            'cash_closing' => 'cashbox_id',
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ($panels as $tab => $filter) {
                $url = route('admin.erp.treasury.index', [
                    'store_id' => $store->id,
                    'tab' => $tab,
                ]);

                $this->actingAs($actor->fresh())
                    ->get($url)
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
                $this->actingAs($actor->fresh())
                    ->get($url.'&'.$filter.'=')
                    ->assertOk();
                $this->actingAs($actor->fresh())
                    ->get($url.'&'.$filter.'=999999')
                    ->assertOk();
                // Unused selectors must not interfere with an unrelated panel.
                $ignoredFilter = $filter === 'facility_id' ? 'cashbox_id' : 'facility_id';
                $this->actingAs($actor->fresh())
                    ->get($url.'&'.$ignoredFilter.'%5B%5D=1')
                    ->assertOk();

                foreach ([
                    '&'.$filter.'%5B%5D=999999',
                    '&'.$filter.'%5Bnested%5D=999999',
                    '&'.$filter.'%5Bnested%5D%5B0%5D=999999',
                ] as $malformed) {
                    $this->actingAs($actor->fresh())
                        ->get($url.$malformed)
                        ->assertNotFound();
                }
            }
        }

        $url = route('admin.erp.treasury.index', [
            'store_id' => $store->id,
            'tab' => 'financing',
        ]);
        $roleless = User::factory()->create([
            'email' => 'v2-4078-roleless@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)->get($url.'&facility_id=1')->assertForbidden();
        $this->actingAs($roleless)->get($url.'&facility_id%5B%5D=1')->assertForbidden();

        $foreignUrl = route('admin.erp.treasury.index', [
            'store_id' => $otherStore->id,
            'tab' => 'financing',
        ]);
        $this->actingAs($actor)->get($foreignUrl.'&facility_id=1')->assertForbidden();
        $this->actingAs($actor)->get($foreignUrl.'&facility_id%5B%5D=1')->assertForbidden();

        $this->assertSame($before, [
            DB::table('enterprise_treasury_receipts')->count(),
            DB::table('enterprise_treasury_payments')->count(),
            DB::table('enterprise_treasury_cheques')->count(),
            DB::table('enterprise_treasury_reconciliations')->count(),
            DB::table('audit_logs')->count(),
        ]);
    }

    /** @return array{0:User,1:Store} */
    private function financeOperator(string $code, string $email): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $actor = User::factory()->create([
            'email' => $email,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $store];
    }

    private function enableTreasury(User $actor, Store $store): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $actor);
        }

        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryReceiptService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
                'treasury.reconciliation.read',
                'treasury.reconciliation.manage',
                'treasury.reconciliation.approve',
                'treasury.cash_closing.read',
                'treasury.cash_closing.manage',
                'treasury.cash_closing.approve',
                'treasury.cheques.read',
                'treasury.cheques.manage',
                'treasury.cheques.approve',
            ],
            $actor,
        );
    }
}
