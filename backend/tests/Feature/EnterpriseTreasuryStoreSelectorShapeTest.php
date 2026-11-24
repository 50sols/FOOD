<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseCashbox;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class EnterpriseTreasuryStoreSelectorShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // P135 signed certification is required even for these positive
        // authorized Treasury HTTP fixture requests. Keep synthetic signing
        // confined to PHPUnit; production stays fail-closed.
        SyntheticErpWorkflowEvidence::install();
        $this->seed(CoreReferenceSeeder::class);
        // Only the test authorizes this precise module release; no runtime override.
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    public function test_nested_root_store_selectors_never_fall_back_to_another_authorized_treasury_store(): void
    {
        $foreign = $this->makeStore('TREASURY-ROOT-OTHER');
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach (['ar', 'en'] as $locale) {
            [$operator, $store] = $this->financeOperator('TREASURY-ROOT-'.strtoupper($locale), $locale);
            $this->enableTreasury($operator, (int) $store->id);
            $cashbox = $this->makeCashbox($store);

            $index = route('admin.erp.treasury.index');
            $createReceipt = route('admin.erp.treasury.receipts.store');
            $this->actingAs($operator)
                ->get(route('admin.erp.treasury.index', ['tab' => 'receipts', 'store_id' => $store->id]))
                ->assertOk()
                ->assertSee($locale === 'ar' ? 'مركز عمل الخزينة' : 'Treasury Workbench');

            // Omitted and blank selectors retain the existing authorized
            // default behavior; a scalar owned selector is also valid.
            $this->actingAs($operator)
                ->get($index.'?tab=receipts')
                ->assertOk();
            $this->actingAs($operator)
                ->get($index.'?tab=receipts&store_id=')
                ->assertOk();

            $validReference = 'V2-ROOT-'.$locale;
            $receiptFields = [
                '_operation_key' => 'ui-v2-treasury-root-'.$locale,
                'target_key' => 'cashbox:'.$cashbox->id,
                'amount' => '15.250',
                'value_date' => '2026-11-22',
                'reference' => $validReference,
            ];
            $this->actingAs($operator)
                ->post($createReceipt, ['store_id' => $store->id, ...$receiptFields])
                ->assertRedirect(route('admin.erp.treasury.index', [
                    'tab' => 'receipts',
                    'store_id' => $store->id,
                ]));
            $this->assertDatabaseHas('enterprise_treasury_receipts', [
                'store_id' => $store->id,
                'reference' => $validReference,
                'status' => 'draft',
            ]);

            $before = [
                DB::table('enterprise_treasury_receipts')->count(),
                DB::table('enterprise_treasury_payments')->count(),
                DB::table('audit_logs')->count(),
            ];

            foreach ([
                [$store->id],
                ['deep' => $store->id],
                ['deep' => ['id' => $store->id]],
            ] as $nested) {
                $this->actingAs($operator)
                    ->get($index.'?tab=receipts&'.http_build_query(['store_id' => $nested]))
                    ->assertNotFound();

                $this->actingAs($operator)
                    ->post($createReceipt, [
                        'store_id' => $nested,
                        ...$receiptFields,
                        '_operation_key' => 'ui-tampered-form-'.$locale,
                        'reference' => 'V2-ROOT-TAMPER-FORM',
                    ])->assertNotFound();

                $this->actingAs($operator)
                    ->postJson($createReceipt, [
                        'store_id' => $nested,
                        ...$receiptFields,
                        '_operation_key' => 'ui-tampered-json-'.$locale,
                        'reference' => 'V2-ROOT-TAMPER-JSON',
                    ])->assertNotFound();
            }

            // An explicit other-store scalar is still denied rather than
            // being reinterpreted as a local Treasury selection.
            $this->actingAs($operator)
                ->get(route('admin.erp.treasury.index', [
                    'tab' => 'receipts', 'store_id' => $foreign->id,
                ]))
                ->assertForbidden();
            $this->actingAs($operator)
                ->post($createReceipt, [
                    'store_id' => $foreign->id,
                    ...$receiptFields,
                    '_operation_key' => 'ui-tampered-foreign-'.$locale,
                    'reference' => 'V2-ROOT-TAMPER-FOREIGN',
                ])->assertForbidden();

            $this->assertSame($before, [
                DB::table('enterprise_treasury_receipts')->count(),
                DB::table('enterprise_treasury_payments')->count(),
                DB::table('audit_logs')->count(),
            ]);
        }

        // A roleless identity has no authorized store options: permission
        // denial must take precedence over malformed selector validation.
        $this->actingAs($roleless)
            ->get(route('admin.erp.treasury.index').'?tab=receipts&store_id%5B%5D=1')
            ->assertForbidden();
        $this->actingAs($roleless)
            ->post(route('admin.erp.treasury.receipts.store'), [
                'store_id' => ['deep' => 1],
                '_operation_key' => 'ui-roleless-denied',
                'target_key' => 'cashbox:1',
                'amount' => '10.000',
                'value_date' => '2026-11-22',
                'reference' => 'ROLELESS-DENIED',
            ])->assertForbidden();
        $this->assertDatabaseMissing('enterprise_treasury_receipts', ['reference' => 'V2-ROOT-TAMPER-FORM']);
        $this->assertDatabaseMissing('enterprise_treasury_receipts', ['reference' => 'V2-ROOT-TAMPER-JSON']);
        $this->assertDatabaseMissing('enterprise_treasury_receipts', ['reference' => 'V2-ROOT-TAMPER-FOREIGN']);
        $this->assertDatabaseMissing('enterprise_treasury_receipts', ['reference' => 'ROLELESS-DENIED']);
    }

    private function makeStore(string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /** @return array{User,Store} */
    private function financeOperator(string $storeCode, string $locale): array
    {
        $store = $this->makeStore($storeCode);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        $user = User::factory()->create([
            'email' => strtolower($storeCode).'@example.test',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function enableTreasury(User $operator, int $storeId): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryReceiptService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLING, $operator);
            $lifecycle->transition(TreasuryReceiptService::MODULE, ModuleState::ENABLED, $operator);
        }
        $context = app(TenantContextResolver::class)->retail($operator, $storeId);
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
            ],
            $operator,
        );
    }

    private function makeCashbox(Store $store): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'V2-ROOT-CASH-'.$store->id,
            'name' => 'Treasury root selector test cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
