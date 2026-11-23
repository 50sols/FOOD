<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: canonical Retail Orders cannot bypass Restaurant workspace profile admission. */
final class V2RestaurantCanonicalOrdersAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_restaurant_store_admin_is_denied_canonical_orders_but_retail_store_admin_is_allowed(): void
    {
        $restaurant = $this->store('B2C', 'P031-R-4301', 'RESTAURANT');
        $retail = $this->store('B2C', 'P031-T-4301', 'RETAIL_STORE');
        $otherRestaurant = $this->store('B2C', 'P031-X-4301', 'RESTAURANT');
        $wholesale = $this->store('B2B', 'P031-W-4301');
        $actor = $this->actor();
        $this->grant($actor, 'B2C_STORE_ADMIN', $restaurant);
        $retailActor = $this->actor();
        $this->grant($retailActor, 'B2C_STORE_ADMIN', $retail);
        $mixed = $this->actor();
        $this->grant($mixed, 'B2C_STORE_ADMIN', $restaurant);
        $this->grant($mixed, 'B2C_STORE_ADMIN', $retail);
        $b2b = $this->actor();
        $b2b->roles()->attach(Role::where('code', 'B2B_ADMIN')->firstOrFail());
        $roleless = $this->actor();

        $counts = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        foreach (['ar', 'en'] as $locale) {
            foreach ([$actor, $retailActor, $mixed] as $user) {
                $user->forceFill(['locale' => $locale])->saveQuietly();
            }

            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?status=pending&store_id='.$restaurant)
                ->assertNotFound();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?status=pending')
                ->assertNotFound();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?store_id='.$otherRestaurant)
                ->assertNotFound();
            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/orders?store_id='.$wholesale)
                ->assertNotFound();

            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?status=pending&store_id='.$retail)
                ->assertOk();
            $this->actingAs($mixed->fresh())
                ->get('/admin/b2c/orders?status=pending')
                ->assertNotFound();
            // Only store_id actually filters OrderOperationsController::index.
            // A store alias alone must not let a mixed-profile actor bypass
            // the no-selector Restaurant admission check.
            $this->actingAs($mixed->fresh())
                ->get('/admin/b2c/orders?status=pending&store='.$retail)
                ->assertNotFound();
            $this->actingAs($mixed->fresh())
                ->get('/admin/b2c/orders?status=pending&store_id='.$retail)
                ->assertOk();
            $this->actingAs($mixed->fresh())
                ->get('/admin/b2c/orders?status=pending&store_id='.$restaurant)
                ->assertNotFound();
            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?status=pending&store_id='.$retail.'&store='.$retail)
                ->assertOk();
            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?status=pending&store_id='.$retail.'&channel=b2b')
                ->assertOk();
            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store_id='.$retail.'&store='.$restaurant)
                ->assertNotFound();

            foreach (['0', '-1', '01', 'not-an-id', '1.0'] as $invalidStore) {
                $this->actingAs($retailActor->fresh())
                    ->get('/admin/b2c/orders?store_id='.$invalidStore)
                    ->assertNotFound();
            }
            $this->actingAs($retailActor->fresh())
                ->get('/admin/b2c/orders?store_id%5B%5D='.$retail)
                ->assertNotFound();
            $this->actingAs($b2b)
                ->get('/admin/b2c/orders?store_id='.$restaurant)
                ->assertForbidden();
            $this->actingAs($roleless)
                ->get('/admin/b2c/orders?store_id='.$restaurant)
                ->assertForbidden();
        }

        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' was mutated by GET');
        }
    }

    private function actor(): User
    {
        return User::factory()->create(['is_active' => true, 'locale' => 'en']);
    }

    private function grant(User $actor, string $roleCode, int $storeId): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::where('code', $roleCode)->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
