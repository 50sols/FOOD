<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P031: the Restaurant-profile B2C workspace must never borrow Retail modules, stores or
 * channel scope from caller-supplied query parameters.
 */
final class V2RestaurantWorkspaceProfileHttpIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_profile_enforces_module_allowlist_and_store_scope_in_both_locales(): void
    {
        $ownedStore = $this->store('B2C', 'P031-RESTAURANT-OWNED', 'RESTAURANT');
        $foreignStore = $this->store('B2C', 'P031-RESTAURANT-FOREIGN', 'RESTAURANT');
        $retailStore = $this->store('B2C', 'P031-RETAIL-FOREIGN', 'RETAIL_STORE');
        $wholesaleStore = $this->store('B2B', 'P031-RESTAURANT-WHOLESALE');

        $retailActor = $this->actor('B2C_STORE_ADMIN', $ownedStore);
        $wholesaleActor = $this->actor('B2B_ADMIN');
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
            'assignments' => DB::table('driver_assignments')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $retailActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($retailActor->fresh())
                ->get($this->customersUrl(['store_id' => $ownedStore]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            // Restaurant retains its own B2C authority even for forged channel.
            $this->actingAs($retailActor->fresh())
                ->get($this->customersUrl(['store_id' => $ownedStore, 'channel' => 'b2b']))
                ->assertOk();

            foreach (['products', 'inventory', 'orders', 'finance', 'drivers', 'storefront', 'content'] as $retailOnlyModule) {
                $this->actingAs($retailActor->fresh())
                    ->get(route('admin.b2c.module', ['module' => $retailOnlyModule, 'store_id' => $ownedStore], false))
                    ->assertNotFound();
            }

            foreach ([$foreignStore, $retailStore, $wholesaleStore] as $forbiddenStore) {
                $this->actingAs($retailActor->fresh())
                    ->get($this->customersUrl(['store_id' => $forbiddenStore]))
                    ->assertNotFound();
            }

            $this->actingAs($retailActor->fresh())
                ->get($this->customersUrl(['store_id' => $ownedStore, 'store' => $foreignStore]))
                ->assertNotFound();

            foreach (['0', '-1', 'not-an-id', '01'] as $invalidSelector) {
                $this->actingAs($retailActor->fresh())
                    ->get($this->customersUrl(['store_id' => $invalidSelector]))
                    ->assertNotFound();
            }

            $this->actingAs($wholesaleActor)
                ->get($this->customersUrl(['store_id' => $ownedStore]))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get($this->customersUrl(['store_id' => $ownedStore]))
                ->assertForbidden();
        }

        foreach ($before as $table => $count) {
            $tableName = $table === 'assignments' ? 'driver_assignments' : $table;
            $this->assertSame($count, DB::table($tableName)->count(), $tableName.' was mutated by GET');
        }
    }

    /** @param array<string, int|string> $query */
    private function customersUrl(array $query): string
    {
        return route('admin.b2c.module', ['module' => 'customers'] + $query, false);
    }

    private function actor(string $roleCode, ?int $storeId = null): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        if ($storeId === null) {
            $actor->roles()->attach($role);
        } else {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $storeId,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor;
    }

    private function store(string $channel, string $code, ?string $profile = null): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $profile ?? ($channel === 'B2C' ? 'RETAIL_STORE' : null),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
