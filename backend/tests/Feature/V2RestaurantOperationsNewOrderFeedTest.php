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

/** P031: an Operations new-order toast must never bypass Restaurant admission. */
final class V2RestaurantOperationsNewOrderFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_order_feed_excludes_restaurant_store_admin_pii_and_preserves_cursor_in_both_locales(): void
    {
        $restaurant = $this->store('P031-FEED-R', 'RESTAURANT');
        $retail = $this->store('P031-FEED-T', 'RETAIL_STORE');
        $foreign = $this->store('P031-FEED-F', 'RETAIL_STORE');
        $restaurantOrder = $this->order($restaurant, 'P031-FEED-R-ORDER');
        $retailOrder = $this->order($retail, 'P031-FEED-T-ORDER');
        $foreignOrder = $this->order($foreign, 'P031-FEED-F-ORDER');

        $restaurantActor = $this->actor('restaurant');
        $this->grant($restaurantActor, $restaurant);
        $retailActor = $this->actor('retail');
        $this->grant($retailActor, $retail);
        $mixedActor = $this->actor('mixed');
        $this->grant($mixedActor, $restaurant);
        $this->grant($mixedActor, $retail);
        $superAdmin = $this->actor('super');
        $superAdmin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->firstOrFail());

        foreach ([$restaurantActor, $mixedActor, $superAdmin] as $actor) {
            $this->notify($actor, $restaurantOrder, $restaurant);
        }
        foreach ([$retailActor, $mixedActor, $superAdmin] as $actor) {
            $this->notify($actor, $retailOrder, $retail);
        }
        $this->notify($retailActor, $foreignOrder, $foreign);

        $before = [
            'orders' => DB::table('orders')->count(),
            'notifications' => DB::table('notifications')->count(),
            'payments' => DB::table('payments')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            foreach ([$restaurantActor, $retailActor, $mixedActor, $superAdmin] as $actor) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
            }

            $this->actingAs($restaurantActor->fresh())
                ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
                ->assertOk()->assertJsonCount(0, 'data')
                ->assertJsonPath('meta.cursor', (int) Notification::where('user_id', $restaurantActor->id)->max('id'));

            $this->actingAs($mixedActor->fresh())
                ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.order_id', $retailOrder)
                ->assertJsonPath('meta.cursor', (int) Notification::where('user_id', $mixedActor->id)->max('id'));

            $this->actingAs($retailActor->fresh())
                ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.order_id', $retailOrder);

            $this->actingAs($superAdmin->fresh())
                ->getJson(route('admin.operations.orders.new-order-feed', ['after' => 0]))
                ->assertOk()->assertJsonCount(2, 'data');
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during feed GET');
        }
    }

    private function actor(string $suffix): User
    {
        return User::factory()->create([
            'email' => 'p031-feed-'.$suffix.'@example.test',
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

    private function store(string $code, string $profile): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => $profile,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $storeId, string $number): int
    {
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P031 Feed Buyer']);

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => (int) $customer->legacy_customer_id,
            'b2c_customer_id' => (int) $customer->id,
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

    private function notify(User $actor, int $orderId, int $storeId): void
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
                'customer_name' => 'P031 Secret Buyer',
                'customer_phone' => '00000000',
            ],
        ]);
    }
}
