<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryChequeService;
use App\Enterprise\Treasury\TreasuryReceiptService;
use App\Models\EnterpriseBankAccount;
use App\Models\EnterpriseTreasuryCheque;
use App\Models\EnterpriseTreasuryReceipt;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class V2TreasuryChequeTransitionScopePrevalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only signed workflow fixture; production certification remains fail-closed.
        SyntheticErpWorkflowEvidence::install();

        Carbon::setTestNow('2026-10-16 12:00:00');
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.treasury',
            app(ModuleRegistry::class)->manifest('treasury')->version,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unknown_and_foreign_cheques_are_not_validated_before_authoritative_scope_in_both_locales(): void
    {
        [$storeA, $makerA, $checkerA, $chequeA] = $this->chequeFixture('CHQ-PRE-A');
        [$storeB, $makerB, $checkerB, $chequeB] = $this->chequeFixture('CHQ-PRE-B');
        $this->assertNotSame($storeA->id, $storeB->id);
        $this->assertNotSame($makerA->id, $checkerA->id);
        $this->assertNotSame($makerB->id, $checkerB->id);

        $beforeTransitions = DB::table('enterprise_treasury_cheque_transitions')->count();
        $beforeAudits = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            $checkerA->forceFill(['locale' => $locale])->save();

            foreach ([$chequeB->id, $chequeB->id + 1000000] as $unavailableId) {
                $this->actingAs($checkerA->fresh())
                    ->postJson(route('admin.erp.treasury.cheques.transition', ['cheque' => $unavailableId]), [
                        'store_id' => $storeA->id,
                        'to_status' => ['not-a-status'],
                    ])
                    ->assertNotFound();

                $this->actingAs($checkerA->fresh())
                    ->postJson(route('admin.erp.treasury.cheques.transition', ['cheque' => $unavailableId]), [
                        'store_id' => $storeA->id,
                    ])
                    ->assertNotFound();
            }

            // The foreign Retail store selector itself must remain unauthorized.
            $this->actingAs($checkerA->fresh())
                ->postJson(route('admin.erp.treasury.cheques.transition', ['cheque' => $chequeB->id]), [
                    'store_id' => $storeB->id,
                    '_operation_key' => 'foreign-selector-denied',
                    'to_status' => EnterpriseTreasuryCheque::STATUS_DEPOSITED,
                ])
                ->assertForbidden();

            $this->actingAs($checkerA->fresh())
                ->postJson(route('admin.erp.treasury.cheques.transition', ['cheque' => $chequeA->id]), [
                    'store_id' => $storeA->id,
                    'to_status' => ['not-a-status'],
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['_operation_key', 'to_status']);
        }

        $this->assertSame($beforeTransitions, DB::table('enterprise_treasury_cheque_transitions')->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('enterprise_treasury_cheques', ['id' => $chequeB->id, 'status' => 'received']);

        $this->actingAs($checkerA->fresh())
            ->post(route('admin.erp.treasury.cheques.transition', ['cheque' => $chequeA->id]), [
                'store_id' => $storeA->id,
                '_operation_key' => 'chq-prevalid-deposit',
                'to_status' => EnterpriseTreasuryCheque::STATUS_DEPOSITED,
            ])
            ->assertRedirect(route('admin.erp.treasury.index', [
                'tab' => 'cheques',
                'store_id' => $storeA->id,
            ]));

        $this->assertDatabaseHas('enterprise_treasury_cheques', [
            'id' => $chequeA->id,
            'status' => EnterpriseTreasuryCheque::STATUS_DEPOSITED,
        ]);
        $this->assertDatabaseHas('enterprise_treasury_cheques', [
            'id' => $chequeB->id,
            'status' => EnterpriseTreasuryCheque::STATUS_RECEIVED,
        ]);
        $this->assertSame($beforeTransitions + 1, DB::table('enterprise_treasury_cheque_transitions')->count());
    }

    /** @return array{Store, User, User, EnterpriseTreasuryCheque} */
    private function chequeFixture(string $code): array
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $maker = $this->actor($store);
        $checker = $this->actor($store);
        $makerContext = app(TenantContextResolver::class)->retail($maker, $store->id);
        $checkerContext = app(TenantContextResolver::class)->retail($checker, $store->id);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($checker, $checkerContext);

        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $bank = EnterpriseBankAccount::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'CHQ-PRE-BANK-'.$store->id,
            'name' => 'Bank '.$store->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Operating',
            'account_reference' => 'KW00PRE'.$store->id,
            'account_reference_last4' => '1234',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseBankAccount::VISIBILITY_STORE,
            'is_active' => true,
        ]);

        $receiptService = app(TreasuryReceiptService::class);
        $draft = $receiptService->createDraft($maker, $makerContext, [
            'idempotency_key' => $code.'-receipt',
            'target_type' => EnterpriseTreasuryReceipt::TARGET_BANK_ACCOUNT,
            'target_id' => $bank->id,
            'amount' => '12.000',
            'value_date' => '2026-10-16',
            'reference' => $code.'-receipt',
        ]);
        $receipt = $receiptService->post($checker, $checkerContext, $draft->id);

        $cheque = app(TreasuryChequeService::class)->createInstrument($maker, $makerContext, [
            'idempotency_key' => $code.'-instrument',
            'direction' => EnterpriseTreasuryCheque::DIRECTION_RECEIVED,
            'treasury_reference_type' => EnterpriseTreasuryCheque::REFERENCE_RECEIPT,
            'treasury_reference_id' => $receipt->id,
            'bank_account_id' => $bank->id,
            'instrument_number' => $code.'-001',
            'issue_date' => '2026-10-16',
            'due_date' => '2026-10-16',
        ]);

        return [$store, $maker, $checker, $cheque];
    }

    private function actor(Store $store): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryChequeService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryChequeService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryChequeService::MODULE, ModuleState::ENABLED, $actor);
        }

        $this->entitleTreasury($actor, $context);
    }

    private function entitleTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryChequeService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.receipts.read',
                'treasury.receipts.manage',
                'treasury.receipts.approve',
                'treasury.cheques.read',
                'treasury.cheques.manage',
                'treasury.cheques.approve',
            ],
            $actor,
        );
    }
}
