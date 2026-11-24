<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RestaurantGlobalOrderSearchAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_profile_does_not_disclose_orders_in_admin_global_search(): void
    {
        $restaurant = $this->store('P031-GS-RESTAURANT', 'RESTAURANT');
        $retail = $this->store('P031-GS-RETAIL', 'RETAIL_STORE');
        $foreignRetail = $this->store('P031-GS-FOREIGN', 'RETAIL_STORE');

        $restaurantOrder = $this->order($restaurant, 'P031-GS-RESTAURANT-ORDER');
        $retailOrder = $this->order($retail, 'P031-GS-RETAIL-ORDER');
        $foreignOrder = $this->order($foreignRetail, 'P031-GS-FOREIGN-ORDER');

        $restaurantActor = $this->actor('restaurant');
        $this->grant($restaurantActor, $restaurant);
        $retailActor = $this->actor('retail');
        $this->grant($retailActor, $retail);
        $mixedActor = $this->actor('mixed');
        $this->grant($mixedActor, $restaurant);
        $this->grant($mixedActor, $retail);
        $superAdmin = $this->actor('super');
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $baseline = DB::table('orders')->count();

        foreach (['ar', 'en'] as $locale) {
            foreach ([$restaurantActor, $retailActor, $mixedActor, $superAdmin] as $actor) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
            }

            $this->actingAs($restaurantActor->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-GS-', 'limit' => 8]))
                ->assertOk()
                ->assertJsonMissing(['title' => '#'.$restaurantOrder->id])
                ->assertJsonCount(0, 'data');

            $this->actingAs($retailActor->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-GS-', 'limit' => 8]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.title', '#'.$retailOrder->id)
                ->assertJsonMissing(['title' => '#'.$restaurantOrder->id])
                ->assertJsonMissing(['title' => '#'.$foreignOrder->id]);

            // Excluded Restaurant rows must not consume the result LIMIT.
            $this->actingAs($mixedActor->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-GS-', 'limit' => 1]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.title', '#'.$retailOrder->id)
                ->assertJsonMissing(['title' => '#'.$restaurantOrder->id])
                ->assertJsonMissing(['title' => '#'.$foreignOrder->id]);

            // Platform support remains separately authorized by its Super Admin
            // role; no store-admin profile veto may revoke that access.
            $this->actingAs($superAdmin->fresh())
                ->getJson(route('admin.global-search', ['q' => 'P031-GS-RESTAURANT-ORDER']))
                ->assertOk()
                ->assertJsonFragment(['title' => '#'.$restaurantOrder->id]);
        }

        $this->assertSame($baseline, DB::table('orders')->count(), 'Search GET must not modify orders');
    }

    private function actor(string $suffix): User
    {
        return User::query()->create([
            'name' => 'Global Search Profile '.$suffix,
            'email' => 'p031-gs-'.$suffix.'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function grant(User $actor, Store $store): void
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permission = Permission::query()->where('code', 'orders.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
        ]);
    }

    private function store(string $code, string $profile): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return Store::query()->create([
            'store_type_id' => $typeId,
            'business_profile' => $profile,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function order(Store $store, string $number): Order
    {
        $customer = Customer::query()->create([
            'type' => 'b2c',
            'name' => 'Global Search Profile Customer',
        ]);

        return Order::query()->create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'grand_total' => 1,
        ]);
    }
}
