<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: direct Operations popup URLs may not evade Restaurant profile admission. */
final class V2RestaurantOperationsOrderPopupAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_order_popup_is_denied_but_retail_and_super_admin_remain_authorized_in_both_locales(): void
    {
        $restaurant = $this->store('P031-POPUP-R', 'RESTAURANT');
        $retail = $this->store('P031-POPUP-T', 'RETAIL_STORE');
        $foreign = $this->store('P031-POPUP-F', 'RETAIL_STORE');
        $restaurantOrder = $this->order($restaurant, 'P031-POPUP-R-ORDER');
        $retailOrder = $this->order($retail, 'P031-POPUP-T-ORDER');
        $foreignOrder = $this->order($foreign, 'P031-POPUP-F-ORDER');

        $restaurantActor = $this->actor('restaurant');
        $this->grant($restaurantActor, $restaurant);
        $retailActor = $this->actor('retail');
        $this->grant($retailActor, $retail);
        $mixedActor = $this->actor('mixed');
        $this->grant($mixedActor, $restaurant);
        $this->grant($mixedActor, $retail);
        $superAdmin = $this->actor('super');
        $superAdmin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->firstOrFail());

        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            foreach ([$restaurantActor, $retailActor, $mixedActor, $superAdmin] as $actor) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
            }

            foreach (['details', 'timeline', 'invoice'] as $kind) {
                $this->actingAs($restaurantActor->fresh())
                    ->get(route('admin.operations.orders.popup', ['order' => $restaurantOrder, 'kind' => $kind]))
                    ->assertNotFound();
                $this->actingAs($mixedActor->fresh())
                    ->get(route('admin.operations.orders.popup', ['order' => $restaurantOrder, 'kind' => $kind]))
                    ->assertNotFound();
                $this->actingAs($retailActor->fresh())
                    ->get(route('admin.operations.orders.popup', ['order' => $foreignOrder, 'kind' => $kind]))
                    ->assertNotFound();
            }

            foreach (['details', 'timeline'] as $kind) {
                $this->actingAs($retailActor->fresh())
                    ->get(route('admin.operations.orders.popup', ['order' => $retailOrder, 'kind' => $kind]))
                    ->assertOk();
                $this->actingAs($mixedActor->fresh())
                    ->get(route('admin.operations.orders.popup', ['order' => $retailOrder, 'kind' => $kind]))
                    ->assertOk();
                $this->actingAs($superAdmin->fresh())
                    ->get(route('admin.operations.orders.popup', ['order' => $restaurantOrder, 'kind' => $kind]))
                    ->assertOk();
            }
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during popup GET');
        }
    }

    private function actor(string $suffix): User
    {
        return User::factory()->create([
            'email' => 'p031-popup-'.$suffix.'@example.test',
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
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P031 Popup Buyer']);

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
}
