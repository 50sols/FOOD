<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: central Retail order wizard cannot admit a Restaurant store-admin. */
final class V2RestaurantCentralOrderAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_central_order_lookup_respects_restaurant_profile_and_mixed_retail_selection_in_both_locales(): void
    {
        $restaurant = $this->store('P031-CREATE-R', 'RESTAURANT');
        $retail = $this->store('P031-CREATE-T', 'RETAIL_STORE');
        $foreign = $this->store('P031-CREATE-X', 'RETAIL_STORE');

        $role = Role::where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::where('code', 'orders.create')->firstOrFail()->id,
            Permission::where('code', 'orders.manage')->firstOrFail()->id,
        ]);

        $restaurantOnly = $this->actor('restaurant');
        $this->grant($restaurantOnly, $role, $restaurant);
        $retailOnly = $this->actor('retail');
        $this->grant($retailOnly, $role, $retail);
        $mixed = $this->actor('mixed');
        $this->grant($mixed, $role, $restaurant);
        $this->grant($mixed, $role, $retail);
        $super = $this->actor('super');
        $super->roles()->attach(Role::where('code', 'SUPER_ADMIN')->firstOrFail());

        $counts = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            foreach ([$restaurantOnly, $retailOnly, $mixed, $super] as $actor) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
            }

            $this->actingAs($restaurantOnly->fresh())
                ->getJson($this->lookup($restaurant))
                ->assertNotFound();
            $this->actingAs($mixed->fresh())
                ->getJson($this->lookup($restaurant))
                ->assertNotFound();

            $this->actingAs($retailOnly->fresh())
                ->getJson($this->lookup($retail))
                ->assertOk()
                ->assertJsonStructure(['data' => ['items', 'page', 'has_more']]);
            $this->actingAs($mixed->fresh())
                ->getJson($this->lookup($retail))
                ->assertOk();
            $this->actingAs($retailOnly->fresh())
                ->getJson($this->lookup($foreign))
                ->assertNotFound();

            $this->actingAs($super->fresh())
                ->getJson($this->lookup($restaurant))
                ->assertOk();
        }

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during GET');
        }
    }

    private function lookup(int $storeId): string
    {
        return route('admin.operations.orders.lookups', [
            'type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ]);
    }

    private function actor(string $name): User
    {
        return User::factory()->create([
            'email' => 'p031-central-'.$name.'@example.test',
            'is_active' => true,
            'locale' => 'en',
        ]);
    }

    private function grant(User $actor, Role $role, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'role_id' => $role->id,
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
}
