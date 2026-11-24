<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: an authorized order may not display unrelated/stale notification PII. */
final class V2OperationsNewOrderFeedCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_new_order_feed_uses_authoritative_customer_not_stale_notification_pii_in_ar_and_en(): void
    {
        $retail = $this->store('P031-IDENTITY-T');
        $foreign = $this->store('P031-IDENTITY-F');
        $customer = app(B2cCustomerService::class)->create($retail, [
            'name' => 'Current Buyer',
            'phone' => '5551000',
        ]);
        $order = $this->order($retail, (int) $customer->legacy_customer_id, (int) $customer->id, 'P031-IDENTITY-ORDER');

        $foreignCustomer = app(B2cCustomerService::class)->create($foreign, [
            'name' => 'Foreign Buyer',
            'phone' => '5559999',
        ]);
        $foreignOrder = $this->order($foreign, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'P031-FOREIGN-ORDER');

        $retailActor = $this->actor('retail');
        $this->grant($retailActor, $retail);
        $superAdmin = $this->actor('super');
        $superAdmin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->firstOrFail());

        foreach ([$retailActor, $superAdmin] as $actor) {
            $this->notify($actor, $order, $retail, 'Foreign Buyer', '5559999');
        }
        $this->notify($retailActor, $foreignOrder, $foreign, 'Foreign Buyer', '5559999');

        $before = [
            'orders' => DB::table('orders')->count(),
            'notifications' => DB::table('notifications')->count(),
            'payments' => DB::table('payments')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            $retailActor->forceFill(['locale' => $locale])->saveQuietly();
            $superAdmin->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($retailActor->fresh())
                ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.order_id', $order)
                ->assertJsonPath('data.0.customer_name', 'Current Buyer')
                ->assertJsonPath('data.0.customer_phone', '5551000')
                ->assertJsonPath('meta.cursor', (int) Notification::where('user_id', $retailActor->id)->max('id'))
                ->assertJsonMissing(['customer_phone' => '5559999']);

            $this->actingAs($superAdmin->fresh())
                ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
                ->assertOk()
                ->assertJsonPath('data.0.customer_name', 'Current Buyer')
                ->assertJsonPath('data.0.customer_phone', '5551000');
        }

        // Clearing a canonical field must not resurrect cached phone/name PII.
        DB::table('b2c_customers')->where('id', $customer->id)->update(['name' => '', 'phone' => null]);
        $this->actingAs($retailActor->fresh())
            ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
            ->assertOk()
            ->assertJsonPath('data.0.customer_name', null)
            ->assertJsonPath('data.0.customer_phone', null);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during feed GET');
        }
    }

    private function actor(string $suffix): User
    {
        return User::factory()->create([
            'email' => 'p031-identity-'.$suffix.'@example.test',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function grant(User $actor, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'role_id' => Role::where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, int $legacyCustomerId, int $customerId, string $number): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2c_customer_id' => $customerId,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notify(User $actor, int $orderId, int $storeId, string $name, string $phone): void
    {
        Notification::query()->create([
            'user_id' => $actor->id,
            'channel' => 'in_app',
            'type' => 'order.created',
            'title' => 'New order',
            'body' => 'Order notification',
            'title_ar' => 'طلب جديد',
            'title_en' => 'New order',
            'body_ar' => 'إشعار طلب',
            'body_en' => 'Order notification',
            'audience' => 'user',
            'app' => 'dashboard',
            'target_channel' => 'b2c',
            'store_id' => $storeId,
            'status' => 'published',
            'published_at' => now(),
            'data' => [
                'source' => 'customer_app',
                'channel' => 'b2c',
                'store_id' => $storeId,
                'order_id' => $orderId,
                'customer_name' => $name,
                'customer_phone' => $phone,
            ],
        ]);
    }
}
