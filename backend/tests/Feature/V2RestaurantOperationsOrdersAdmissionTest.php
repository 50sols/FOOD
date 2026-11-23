<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: generic Operations Orders GET must honor Restaurant workspace admission. */
final class V2RestaurantOperationsOrdersAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_store_admin_cannot_read_generic_operations_orders_in_arabic_or_english(): void
    {
        $restaurant = $this->store('P031-GENERIC-R', 'RESTAURANT');
        $retail = $this->store('P031-GENERIC-T', 'RETAIL_STORE');
        $foreignRetail = $this->store('P031-GENERIC-X', 'RETAIL_STORE');
        $restaurantOrder = $this->order($restaurant, 'P031-GENERIC-R-ORDER');
        $retailOrder = $this->order($retail, 'P031-GENERIC-T-ORDER');
        $this->order($foreignRetail, 'P031-GENERIC-X-ORDER');

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

            $this->actingAs($restaurantActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $restaurant]))
                ->assertNotFound();
            $this->actingAs($restaurantActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
                ->assertNotFound();
            $this->actingAs($restaurantActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'all']))
                ->assertForbidden();

            $this->actingAs($mixedActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c']))
                ->assertNotFound();
            $this->actingAs($mixedActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'all']))
                ->assertForbidden();
            $this->actingAs($mixedActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $restaurant]))
                ->assertNotFound();

            $this->actingAs($mixedActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $retail]))
                ->assertOk()
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $retailOrder)
                    && ! collect($rows)->contains(static fn (array $row): bool => $row['id'] === $restaurantOrder));

            $this->actingAs($retailActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $retail]))
                ->assertOk();
            $this->actingAs($retailActor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $foreignRetail]))
                ->assertNotFound();

            // Super Admin retains the explicit privileged path, with no
            // Restaurant store-admin role attached to this actor.
            $this->actingAs($superAdmin->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $restaurant]))
                ->assertOk()
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $restaurantOrder));
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during GET');
        }
    }

    private function actor(string $name): User
    {
        return User::factory()->create([
            'email' => 'p031-generic-'.$name.'@example.test',
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
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P031 Generic Buyer']);

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
