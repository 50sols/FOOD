<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
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

/**
 * P129: an ordinary Customer Service concession cannot silently write off
 * the full value of a paid-value order without independent approval.
 */
final class V2CommercialDiscountZeroValueGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_full_writeoff_and_rounding_to_zero_are_denied_without_financial_or_notification_mutation_in_both_locales(): void
    {
        $actor = $this->manager();
        $accountant = $this->finance();
        $customerUser = User::factory()->create(['is_active' => true]);
        $order = $this->order($customerUser);
        $submitUrl = route('admin.operations.orders.customer-service-discount', ['order' => $order]);

        DB::table('payments')->insert([
            'order_id' => $order,
            'provider' => 'cash',
            'status' => 'pending',
            'amount' => 9,
            'currency' => 'KWD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeNotifications = DB::table('notifications')->count();

        foreach (['ar' => '100', 'en' => '99.999'] as $locale => $percentage) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $accountant->forceFill(['locale' => $locale])->save();
            $key = (string) Str::uuid();

            // Customer Service submits the request but cannot grant a write-off.
            $this->actingAs($actor)->post($submitUrl, [
                'percentage' => $percentage,
                'reason' => 'Complete writeoff needs a separate finance approval',
                'idempotency_key' => $key,
            ])->assertRedirect();

            $requestId = (int) DB::table('order_customer_service_discount_requests')
                ->where('order_id', $order)->where('idempotency_key', $key)->value('id');
            $this->assertGreaterThan(0, $requestId);
            $this->assertDatabaseHas('order_customer_service_discount_requests', [
                'id' => $requestId, 'status' => 'pending',
            ]);
            $this->actingAs($accountant)->post(
                route('admin.operations.orders.customer-service-discount.review', [
                    'order' => $order, 'discountRequest' => $requestId,
                ]),
                ['decision' => 'approve'],
            )->assertSessionHasErrors('percentage');

            // Even a real Finance actor cannot bypass the P129 positive-total guard.
            $this->assertDatabaseHas('order_customer_service_discount_requests', [
                'id' => $requestId, 'status' => 'pending',
            ]);
            $this->assertDatabaseHas('orders', [
                'id' => $order, 'subtotal' => 10, 'discount_total' => 1, 'grand_total' => 9,
            ]);
            $this->assertDatabaseHas('payments', [
                'order_id' => $order, 'status' => 'pending', 'amount' => 9,
            ]);
            $this->assertDatabaseMissing('order_customer_service_discounts', ['order_id' => $order]);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('event', 'order.customer_service_discount.approved')->count());
            $this->assertSame($beforeNotifications, DB::table('notifications')->count());
        }
        $this->assertSame(2, DB::table('order_customer_service_discount_requests')
            ->where('order_id', $order)->where('status', 'pending')->count());
        $this->assertSame(2, DB::table('audit_logs')
            ->where('event', 'order.customer_service_discount.requested')->count());
    }

    public function test_legitimate_positive_value_discount_still_posts_once_with_same_role_and_scope(): void
    {
        $actor = $this->manager();
        $accountant = $this->finance();
        $order = $this->order();
        $submitUrl = route('admin.operations.orders.customer-service-discount', ['order' => $order]);
        $key = (string) Str::uuid();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)->post($submitUrl, [
                'percentage' => '10',
                'reason' => 'Authorized regular customer concession',
                'idempotency_key' => $key,
            ])->assertRedirect();
        }

        // Replaying the same request does not mutate pricing or enqueue twice.
        $this->assertDatabaseHas('orders', [
            'id' => $order, 'discount_total' => 1, 'grand_total' => 9,
        ]);
        $this->assertSame(1, DB::table('order_customer_service_discount_requests')
            ->where('order_id', $order)->where('idempotency_key', $key)
            ->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('order_customer_service_discounts')
            ->where('order_id', $order)->count());

        $requestId = (int) DB::table('order_customer_service_discount_requests')
            ->where('order_id', $order)->where('idempotency_key', $key)->value('id');
        $reviewUrl = route('admin.operations.orders.customer-service-discount.review', [
            'order' => $order, 'discountRequest' => $requestId,
        ]);
        $this->actingAs($actor)->post($reviewUrl, ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($accountant)->post($reviewUrl, ['decision' => 'approve'])->assertRedirect();
        $this->actingAs($accountant)->post($reviewUrl, ['decision' => 'approve'])->assertStatus(409);

        $this->assertDatabaseHas('orders', [
            'id' => $order, 'discount_total' => 1.9, 'grand_total' => 8.1,
        ]);
        $this->assertDatabaseHas('order_customer_service_discount_requests', [
            'id' => $requestId, 'status' => 'approved',
            'reviewed_by_user_id' => $accountant->getKey(),
        ]);
        $this->assertSame(1, DB::table('order_customer_service_discounts')
            ->where('order_id', $order)->where('applied_by_user_id', $accountant->getKey())->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'order.customer_service_discount.applied')
            ->where('auditable_id', $order)->count());
    }

    public function test_unprivileged_actor_cannot_use_discount_endpoint_as_full_writeoff_bypass(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $order = $this->order();

        $this->actingAs($actor)->post(
            route('admin.operations.orders.customer-service-discount', ['order' => $order]),
            [
                'percentage' => '100',
                'reason' => 'Unauthorized waiver attempt',
                'idempotency_key' => (string) Str::uuid(),
            ],
        )->assertForbidden();

        $this->assertDatabaseMissing('order_customer_service_discounts', ['order_id' => $order]);
        $this->assertDatabaseHas('orders', ['id' => $order, 'grand_total' => 9]);
    }

    private function manager(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail());

        return $actor->fresh();
    }

    private function finance(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'FINANCE')->firstOrFail());
        $this->enableFinanceForStore($actor);

        return $actor->fresh();
    }

    private function enableFinanceForStore(User $actor): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $store = Store::query()->findOrFail($storeId);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $modules = app(ModuleRegistry::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            $modules->manifest(FinanceFoundationService::MODULE)->version,
        );
        if (! $modules->isEnabled(FinanceFoundationService::MODULE)) {
            app(ModuleLifecycleManager::class)->transition(
                FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor,
            );
            app(ModuleLifecycleManager::class)->transition(
                FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor,
            );
        }
        $scope = app(OrganizationHierarchyService::class)->scopeForStore($storeId);
        $this->assertNotNull($scope);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope['legal_entity_id']),
            true, ['finance.foundation.read', 'finance.ar.read'], $actor,
        );
    }

    private function order(?User $customerUser = null): int
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $buyer = app(B2bCustomerService::class)->create(['name' => 'V2 P129 Buyer']);
        if ($customerUser !== null) {
            DB::table('b2b_customers')->where('id', $buyer->getKey())
                ->update(['user_id' => $customerUser->getKey()]);
        }

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => (int) $buyer->legacy_customer_id,
            'b2b_customer_id' => (int) $buyer->getKey(),
            'order_number' => 'V2-P129-'.Str::upper(Str::random(9)),
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 1,
            'delivery_total' => 0,
            'tax_total' => 0,
            'grand_total' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
