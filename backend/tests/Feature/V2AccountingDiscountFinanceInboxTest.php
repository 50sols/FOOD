<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2AccountingDiscountFinanceInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest(FinanceFoundationService::MODULE)->version,
        );
    }

    public function test_wholesale_finance_inbox_is_scoped_bilingual_and_approves_once(): void
    {
        $finance = $this->roleUser('FINANCE');
        $requester = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $other = $this->store('B2B', '4397-OTHER-WHOLESALE');
        $this->enableFinance($finance, $store);

        $orderId = $this->order($store, 'b2b', 'INBOX-LOCAL-4397');
        $foreignId = $this->order($other, 'b2b', 'INBOX-FOREIGN-4397');
        $requestId = $this->pendingRequest($orderId, $requester);
        $this->pendingRequest($foreignId, $requester);
        $inbox = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'discount_approvals']);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $finance->forceFill(['locale' => $locale])->save();
            $this->actingAs($finance->fresh())->get($inbox)
                ->assertOk()
                ->assertSee('data-finance-discount-inbox="true"', false)
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('INBOX-LOCAL-4397')
                ->assertDontSee('INBOX-FOREIGN-4397')
                ->assertSee('name="decision" value="approve"', false)
                ->assertSee('name="decision" value="reject"', false);
        }

        $review = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);
        $this->actingAs($finance->fresh())->post($review, ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'approved', 'reviewed_by_user_id' => $finance->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.approved', 'user_id' => $finance->id,
        ]);
        $this->actingAs($finance->fresh())->post($review, ['decision' => 'approve'])->assertStatus(409);
        $this->actingAs($finance->fresh())->get($inbox)->assertDontSee('INBOX-LOCAL-4397');
        $this->assertDatabaseHas('orders', ['id' => $foreignId, 'grand_total' => 9]);
    }

    public function test_customer_service_and_self_review_cannot_take_finance_decisions(): void
    {
        $finance = $this->roleUser('FINANCE');
        $manager = $this->roleUser('CUSTOMER_SERVICE_MANAGER');
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $this->enableFinance($finance, $store);
        $orderId = $this->order($store, 'b2b', 'INBOX-SELF-4397');
        $requestId = $this->pendingRequest($orderId, $finance);
        $inbox = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'discount_approvals']);

        $this->actingAs($manager)->get($inbox)->assertForbidden();
        $this->actingAs($finance)->get($inbox)
            ->assertOk()
            ->assertSee('INBOX-SELF-4397')
            ->assertDontSee('name="decision" value="approve"', false);
        $this->actingAs($finance)->post(
            route('admin.operations.orders.customer-service-discount.review', [
                'order' => $orderId, 'discountRequest' => $requestId,
            ]),
            ['decision' => 'approve'],
        )->assertForbidden();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
    }

    public function test_independent_finance_rejection_preserves_pricing_and_cannot_be_replayed_as_approval(): void
    {
        $finance = $this->roleUser('FINANCE');
        $requester = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $this->enableFinance($finance, $store);

        $orderId = $this->order($store, 'b2b', 'INBOX-REJECT-4397');
        $requestId = $this->pendingRequest($orderId, $requester);
        $review = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);
        $inbox = route('admin.erp.finance.index', ['store_id' => $store->id, 'tab' => 'discount_approvals']);

        $this->actingAs($finance)->post($review, [
            'decision' => 'reject',
            'review_reason' => 'Independent Accounting rejection',
        ])->assertRedirect();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId,
            'status' => 'rejected',
            'reviewed_by_user_id' => $finance->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.rejected',
            'user_id' => $finance->id,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);

        $this->actingAs($finance)->post($review, ['decision' => 'approve'])->assertStatus(409);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId, 'discount_total' => 1, 'grand_total' => 9,
        ]);
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'rejected',
        ]);
        $this->actingAs($finance)->get($inbox)->assertOk()->assertDontSee('INBOX-REJECT-4397');
    }

    public function test_ar_only_finance_entitlement_cannot_expose_unactionable_discount_inbox_in_ar_and_en(): void
    {
        $finance = $this->roleUser('FINANCE');
        $requester = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $this->enableFinance($finance, $store);
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        $entitlements = app(ModuleEntitlementManager::class);

        // AR-only can still access Finance. However this reviewer lacks
        // finance.foundation.read, which the protected decision POST requires.
        $entitlements->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.ar.read'], $finance,
        );
        $this->assertTrue(app(FinanceWorkbenchAccess::class)->canRead($finance, $store->id));

        $orderId = $this->order($store, 'b2b', 'INBOX-AR-ONLY-4397');
        $requestId = $this->pendingRequest($orderId, $requester);
        $inbox = route('admin.erp.finance.index', [
            'store_id' => $store->id, 'tab' => 'discount_approvals',
        ]);
        $review = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $finance->forceFill(['locale' => $locale])->save();
            $this->actingAs($finance->fresh())->get($inbox)->assertForbidden();
            $this->actingAs($finance->fresh())->post($review, ['decision' => 'approve'])->assertForbidden();
            $this->assertDatabaseHas('order_customer_service_discount_requests', [
                'id' => $requestId, 'status' => 'pending',
            ]);
            $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        }
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());

        // Restoring the precise entitlement makes the existing positive
        // Finance inbox actionable without widening the protected POST.
        $entitlements->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read'], $finance,
        );
        $this->actingAs($finance->fresh())->get($inbox)->assertOk()
            ->assertSee('INBOX-AR-ONLY-4397');
    }

    public function test_direct_finance_approval_post_requires_live_module_and_store_entitlement(): void
    {
        $finance = $this->roleUser('FINANCE');
        $requester = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        $orderId = $this->order($store, 'b2b', 'INBOX-ENTITLEMENT-4397');
        $requestId = $this->pendingRequest($orderId, $requester);
        $reviewUrl = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $orderId, 'discountRequest' => $requestId,
        ]);

        // An actor's FINANCE role alone never enables a dormant Finance
        // module or grants the direct POST endpoint authority.
        $this->actingAs($finance)->post($reviewUrl, ['decision' => 'approve'])
            ->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);

        $this->enableFinance($finance, $store);
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            false, null, $finance,
        );

        // Revoking the legal-entity entitlement must deny a crafted POST,
        // even if Finance is enabled and the actor has finance.manage.
        $this->actingAs($finance)->post($reviewUrl, ['decision' => 'approve'])
            ->assertForbidden();

        // A legal-entity grant must not override a more-specific STORE denial.
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read'], $finance,
        );
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            false, null, $finance,
        );
        $this->actingAs($finance)->post($reviewUrl, ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'grand_total' => 9]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'order.customer_service_discount.approved',
            'user_id' => $finance->id,
        ]);
    }

    public function test_retail_accounting_queue_never_reads_other_store_or_wholesale_requests(): void
    {
        $owned = $this->store('B2C', '4397-RETAIL-OWNED');
        $foreign = $this->store('B2C', '4397-RETAIL-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->grantPlatformErpAccess($actor);
        $role = Role::query()->where('code', 'RETAIL_FINANCE')->where('scope', 'store')->firstOrFail();
        foreach (['finance.view', 'finance.manage'] as $code) {
            $role->permissions()->syncWithoutDetaching([
                Permission::query()->where('code', $code)->firstOrFail()->id,
            ]);
        }
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id, 'store_id' => $owned->id, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = $actor->fresh();
        $this->enableFinance($actor, $owned);
        $requester = $this->roleUser('CUSTOMER_SERVICE_AGENT');
        $localId = $this->order($owned, 'b2c', 'INBOX-B2C-OWNED');
        $foreignId = $this->order($foreign, 'b2c', 'INBOX-B2C-FOREIGN');
        $localRequestId = $this->pendingRequest($localId, $requester);
        $this->pendingRequest($foreignId, $requester);

        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $owned->id, 'tab' => 'discount_approvals',
        ]))->assertOk()->assertSee('INBOX-B2C-OWNED')->assertDontSee('INBOX-B2C-FOREIGN');
        $this->actingAs($actor)->get(route('admin.erp.finance.index', [
            'store_id' => $foreign->id, 'tab' => 'discount_approvals',
        ]))->assertNotFound();

        $this->actingAs($actor)->post(route('admin.operations.orders.customer-service-discount.review', [
            'order' => $foreignId,
            'discountRequest' => (int) DB::table('order_customer_service_discount_requests')
                ->where('order_id', $foreignId)->value('id'),
        ]), ['decision' => 'approve'])->assertNotFound();
        $this->assertDatabaseHas('orders', ['id' => $foreignId, 'grand_total' => 9]);

        // A genuine store-scoped Retail Finance reviewer may reject only this
        // store's request, without mutating its price or enabling replay.
        $localReview = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $localId, 'discountRequest' => $localRequestId,
        ]);
        $this->actingAs($actor)->post($localReview, [
            'decision' => 'reject', 'review_reason' => 'Retail accounting rejection',
        ])->assertRedirect();
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $localRequestId, 'status' => 'rejected', 'reviewed_by_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'order.customer_service_discount.rejected', 'user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $localId, 'discount_total' => 1, 'grand_total' => 9,
        ]);
        $this->actingAs($actor)->post($localReview, [
            'decision' => 'approve',
        ])->assertStatus(409);
        $this->assertDatabaseHas('orders', [
            'id' => $localId, 'discount_total' => 1, 'grand_total' => 9,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $foreignId, 'grand_total' => 9]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user->fresh();
    }

    private function store(string $type, string $code): Store
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code, 'name' => $code, 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function enableFinance(User $actor, Store $store): void
    {
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $lifecycle = app(ModuleLifecycleManager::class);
        if (! app(ModuleRegistry::class)->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($store->id);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read'], $actor,
        );
    }

    private function order(Store $store, string $channel, string $number): int
    {
        $customer = app(B2bCustomerService::class)->create(['name' => $number.' customer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store->id, 'customer_id' => (int) $customer->legacy_customer_id,
            'b2b_customer_id' => (int) $customer->getKey(), 'order_number' => $number,
            'channel' => $channel, 'status' => 'pending', 'currency' => 'KWD',
            'subtotal' => 10, 'discount_total' => 1, 'delivery_total' => 0,
            'tax_total' => 0, 'grand_total' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pendingRequest(int $orderId, User $requester): int
    {
        return (int) DB::table('order_customer_service_discount_requests')->insertGetId([
            'order_id' => $orderId, 'requested_by_user_id' => $requester->id,
            'idempotency_key' => (string) Str::uuid(), 'percentage' => 12.5,
            'reason' => 'Independent Accounting review', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
