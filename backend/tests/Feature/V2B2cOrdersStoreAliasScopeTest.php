<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: the legacy Retail store alias is a real scoped selector, not decoration. */
final class V2B2cOrdersStoreAliasScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_retail_store_alias_filters_rows_and_preserves_restaurant_and_foreign_store_denials(): void
    {
        $first = $this->store('P031-ALIAS-T1', 'RETAIL_STORE');
        $second = $this->store('P031-ALIAS-T2', 'RETAIL_STORE');
        $restaurant = $this->store('P031-ALIAS-R', 'RESTAURANT');
        $foreign = $this->store('P031-ALIAS-F', 'RETAIL_STORE');

        $firstOrder = $this->order($first, 'P031-ALIAS-FIRST');
        $secondOrder = $this->order($second, 'P031-ALIAS-SECOND');
        $restaurantOrder = $this->order($restaurant, 'P031-ALIAS-RESTAURANT');
        $foreignOrder = $this->order($foreign, 'P031-ALIAS-FOREIGN');

        $retailActor = $this->actor('two-retail');
        $this->grant($retailActor, $first);
        $this->grant($retailActor, $second);
        $mixedActor = $this->actor('mixed');
        $this->grant($mixedActor, $first);
        $this->grant($mixedActor, $restaurant);
        $superAdmin = $this->actor('super');
        $superAdmin->roles()->attach(Role::where('code', 'SUPER_ADMIN')->firstOrFail());

        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            foreach ([$retailActor, $mixedActor, $superAdmin] as $actor) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
            }

            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store='.$first)
                ->assertOk()
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $firstOrder)
                    && ! collect($rows)->contains(static fn (array $row): bool => $row['id'] === $secondOrder)
                    && ! collect($rows)->contains(static fn (array $row): bool => $row['id'] === $foreignOrder));

            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store='.$second)
                ->assertOk()
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $secondOrder)
                    && ! collect($rows)->contains(static fn (array $row): bool => $row['id'] === $firstOrder));

            // A mixed-profile actor can choose authorized Retail explicitly,
            // but neither an implicit Restaurant list nor direct Restaurant
            // selection should ever grant Retail Orders access.
            $this->actingAs($mixedActor->fresh())
                ->get('/admin/b2c/orders?store='.$first)
                ->assertOk()
                ->assertViewHas('rows', static fn (array $rows): bool => collect($rows)
                    ->contains(static fn (array $row): bool => $row['id'] === $firstOrder)
                    && ! collect($rows)->contains(static fn (array $row): bool => $row['id'] === $restaurantOrder));
            $this->actingAs($mixedActor->fresh())
                ->get('/admin/b2c/orders')
                ->assertNotFound();
            $this->actingAs($mixedActor->fresh())
                ->get('/admin/b2c/orders?store='.$restaurant)
                ->assertNotFound();

            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store='.$foreign)
                ->assertNotFound();
            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store='.$first.'&store_id='.$second)
                ->assertNotFound();

            foreach (['0', '01', '-1', 'nope', '1.0'] as $malformed) {
                $this->actingAs($retailActor->fresh())
                    ->get('/admin/b2c/orders?store='.$malformed)
                    ->assertNotFound();
            }
            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store%5B%5D='.$first)
                ->assertNotFound();

            $this->actingAs($superAdmin->fresh())
                ->get('/admin/b2c/orders?store='.$restaurant)
                ->assertOk();
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' modified by GET');
        }
    }

    private function actor(string $name): User
    {
        return User::factory()->create([
            'email' => 'p031-alias-'.$name.'@example.test',
            'is_active' => true,
            'locale' => 'en',
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
        $customer = app(B2cCustomerService::class)->create($storeId, ['name' => 'P031 Alias Buyer']);

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
