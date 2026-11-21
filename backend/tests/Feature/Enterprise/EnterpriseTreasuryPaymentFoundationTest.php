<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Treasury\TreasuryIntegrationEventPublisher;
use App\Enterprise\Treasury\TreasuryPaymentService;
use App\Models\EnterpriseCashbox;
use App\Models\EnterpriseTreasuryPayment;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnterpriseTreasuryPaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_payment_lifecycle_is_idempotent_and_enforces_maker_approver_separation(): void
    {
        [$store, $maker, $makerContext] = $this->fixture('PAY-LIFE', 'maker@example.test');
        $approver = $this->financeActor('approver@example.test', $store);
        $approverContext = $this->context($approver, $store);
        $this->enableTreasury($maker, $makerContext);
        $this->entitleTreasury($approver, $approverContext);

        $cashbox = $this->cashbox($store);
        $payload = [
            'idempotency_key' => 'payment-001',
            'target_type' => EnterpriseTreasuryPayment::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '25.750',
            'value_date' => '2026-10-16',
            'reference' => 'PAY-REF-001',
            'beneficiary' => 'Supplier A',
        ];

        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($maker, $makerContext, $payload);
        $retry = $service->createDraft($maker, $makerContext, $payload);

        $this->assertSame($draft->id, $retry->id);
        $this->assertSame('25.750', $draft->amount);
        $this->assertDatabaseCount('enterprise_treasury_payments', 1);

        try {
            $service->post($maker, $makerContext, $draft->id);
            $this->fail('Maker must not approve the same payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approver', $exception->errors());
        }

        $posted = $service->post($approver, $approverContext, $draft->id);
        $postedRetry = $service->post($approver, $approverContext, $draft->id);

        $this->assertSame(EnterpriseTreasuryPayment::STATUS_POSTED, $posted->status);
        $this->assertSame($posted->id, $postedRetry->id);
        $this->assertNotNull($posted->workflow_request_id);
        $this->assertDatabaseCount('enterprise_workflow_requests', 1);
        $this->assertDatabaseHas('enterprise_workflow_steps', [
            'workflow_request_id' => $posted->workflow_request_id,
            'step_code' => 'approver',
            'required_permission' => 'finance.manage',
            'status' => 'APPROVED',
            'actor_id' => $approver->id,
        ]);

        $workflow = EnterpriseWorkflowRequest::query()->findOrFail((int) $posted->workflow_request_id);
        $this->assertSame('treasury_payment_posting', $workflow->workflow_key);
        $this->assertSame('25.750', $workflow->context['amount']);
        $this->assertSame('KWD', $workflow->context['currency']);
        $this->assertSame('store', $workflow->scope_type);
        $this->assertSame($store->id, (int) $workflow->scope_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.treasury.payment_posted',
            'auditable_id' => $draft->id,
        ]);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_type', TreasuryIntegrationEventPublisher::PAYMENT_POSTED)
                ->where('event_version', 1)
                ->count(),
        );
        $this->assertDatabaseHas('enterprise_treasury_integration_events', [
            'source_type' => 'payment',
            'source_id' => $draft->id,
            'event_type' => TreasuryIntegrationEventPublisher::PAYMENT_POSTED,
            'event_version' => 1,
            'currency_code' => 'KWD',
        ]);

        try {
            $service->createDraft($maker, $makerContext, [...$payload, 'reference' => 'DIFFERENT']);
            $this->fail('Idempotency key reuse with a different request must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
    }

    public function test_payment_number_and_reference_prefix_search_are_literal_scoped_and_read_only(): void
    {
        [$store, $actor, $context] = $this->fixture('PAY-LIT-A', 'pay-lit-a@example.test');
        [$foreignStore, $foreignActor, $foreignContext] = $this->fixture(
            'PAY-LIT-B', 'pay-lit-b@example.test',
        );
        $this->enableTreasury($actor, $context);
        $this->enableTreasury($foreignActor, $foreignContext);
        $cashbox = $this->cashbox($store);
        $foreignCashbox = $this->cashbox($foreignStore);
        $service = app(TreasuryPaymentService::class);
        $sequence = 0;

        $newPayment = function (
            User $user,
            ResolvedTenantContext $tenant,
            EnterpriseCashbox $target,
            string $number,
            string $reference,
        ) use ($service, &$sequence): int {
            $sequence++;
            $payment = $service->createDraft($user, $tenant, [
                'idempotency_key' => 'pay-literal-'.$sequence,
                'target_type' => EnterpriseTreasuryPayment::TARGET_CASHBOX,
                'target_id' => $target->id,
                'amount' => '1.000',
                'value_date' => '2026-10-16',
                'reference' => $reference,
            ]);
            DB::table('enterprise_treasury_payments')
                ->where('id', $payment->id)
                ->update(['payment_number' => $number]);

            return (int) $payment->id;
        };

        $target = $newPayment($actor, $context, $cashbox, 'PAY_%!\\-A', 'REF_%!\\-A');
        $decoy = $newPayment($actor, $context, $cashbox, 'PAYX-OTHER', 'OTHER-REF');
        $referenceOnly = $newPayment(
            $actor, $context, $cashbox, 'OTHER-PAYMENT', 'REF_%!\\-ONLY',
        );
        $leading = [];
        foreach ([
            '%' => '%P-001',
            '_' => '_U-001',
            '!' => '!B-001',
            '\\' => '\\S-001',
        ] as $symbol => $number) {
            $leading[$symbol] = $newPayment(
                $actor, $context, $cashbox, $number, 'OTHER-REF-'.$number,
            );
        }
        $foreign = $newPayment(
            $foreignActor, $foreignContext, $foreignCashbox,
            'PAY_%!\\-FOREIGN', 'REF_%!\\-FOREIGN',
        );

        $count = DB::table('enterprise_treasury_payments')->count();
        $auditCount = DB::table('audit_logs')->count();
        $outboxCount = DB::table('enterprise_outbox_messages')->count();
        $search = fn (string $q): array => $service->paginatePayments(
            $actor, $context, ['q' => $q],
        )->getCollection()->pluck('id')->all();

        foreach (['PAY_', 'PAY_%', 'PAY_%!', 'PAY_%!\\-'] as $prefix) {
            $this->assertSame([$target], $search($prefix), 'Payment prefix: '.$prefix);
        }
        foreach ($leading as $symbol => $id) {
            $this->assertSame([$id], $search($symbol), 'Literal payment symbol: '.$symbol);
        }
        $this->assertSame([$decoy, $target], $search('PAY'));
        $this->assertSame([], $search('PAY%'));
        $this->assertSame([$referenceOnly], $search('REF_%!\\-O'));
        $this->assertSame([$referenceOnly, $target], $search('REF_%!\\-'));
        $this->assertSame(1, $service->paginatePayments(
            $foreignActor, $foreignContext, ['q' => 'PAY_%!'],
        )->total());
        $this->assertNotContains($foreign, $search('PAY'));
        $this->assertSame(0, $service->paginatePayments(
            $actor, $context, ['q' => 'PAY', 'status' => EnterpriseTreasuryPayment::STATUS_POSTED],
        )->total());
        $this->assertSame(0, $service->paginatePayments(
            $actor, $context, ['q' => 'PAY', 'target_type' => EnterpriseTreasuryPayment::TARGET_BANK_ACCOUNT],
        )->total());
        $this->assertSame(0, $service->paginatePayments(
            $actor, $context, ['q' => 'PAY', 'value_date_from' => '2026-10-17'],
        )->total());
        $this->assertSame(10, $service->paginatePayments($actor, $context, [], 1)->perPage());

        $roleless = User::factory()->create(['is_active' => true]);
        try {
            $service->paginatePayments($roleless, $context, ['q' => 'PAY_%!']);
            $this->fail('Roleless actors must not read Treasury payments.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($count, DB::table('enterprise_treasury_payments')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($outboxCount, DB::table('enterprise_outbox_messages')->count());
    }

    public function test_disabled_treasury_performs_no_payment_table_query_work(): void
    {
        [$store, $actor, $context] = $this->fixture('PAY-OFF', 'off@example.test');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower((string) $event->sql);
        });

        try {
            app(TreasuryPaymentService::class)->paginatePayments($actor, $context);
            $this->fail('Disabled Treasury must deny payment reads.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'enterprise_treasury_payments'),
        ));
        $this->assertTrue($store->exists);
    }

    public function test_payment_cancel_and_schema_rollback_are_safe(): void
    {
        [$store, $actor, $context] = $this->fixture('PAY-CANCEL', 'cancel@example.test');
        $this->enableTreasury($actor, $context);
        $cashbox = $this->cashbox($store);

        $service = app(TreasuryPaymentService::class);
        $draft = $service->createDraft($actor, $context, [
            'idempotency_key' => 'cancel-001',
            'target_type' => EnterpriseTreasuryPayment::TARGET_CASHBOX,
            'target_id' => $cashbox->id,
            'amount' => '3.000',
            'value_date' => '2026-10-16',
            'reference' => 'CANCEL-001',
        ]);

        $cancelled = $service->cancel($actor, $context, $draft->id);
        $again = $service->cancel($actor, $context, $draft->id);
        $this->assertSame(EnterpriseTreasuryPayment::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame($cancelled->id, $again->id);

        $migration = require database_path(
            'migrations/2026_10_16_074000_create_enterprise_treasury_payments.php',
        );
        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_treasury_payments'));
        $this->assertTrue(Schema::hasTable('enterprise_treasury_receipts'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('enterprise_treasury_payments'));
    }

    /** @return array{Store,User,ResolvedTenantContext} */
    private function fixture(string $code, string $email): array
    {
        $store = $this->createRetailStore($code);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $actor = $this->financeActor($email, $store);

        return [$store, $actor, $this->context($actor, $store)];
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function financeActor(string $email, Store $store): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function context(User $actor, Store $store): ResolvedTenantContext
    {
        return app(TenantContextResolver::class)->retail($actor, $store->id);
    }

    private function enableTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(TreasuryPaymentService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(TreasuryPaymentService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(TreasuryPaymentService::MODULE, ModuleState::ENABLED, $actor);
        }
        $this->entitleTreasury($actor, $context);
    }

    private function entitleTreasury(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            TreasuryPaymentService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $scope->storeId),
            true,
            [
                'treasury.payments.read',
                'treasury.payments.manage',
                'treasury.payments.approve',
            ],
            $actor,
        );
    }

    private function cashbox(Store $store): EnterpriseCashbox
    {
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);

        return EnterpriseCashbox::query()->create([
            'company_id' => $scope['company_id'],
            'store_id' => $store->id,
            'code' => 'PAY-CASH-'.$store->id,
            'name' => 'Payment Cashbox',
            'currency_code' => 'KWD',
            'operator_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'approval_visibility' => EnterpriseCashbox::VISIBILITY_STORE,
            'is_active' => true,
        ]);
    }
}
