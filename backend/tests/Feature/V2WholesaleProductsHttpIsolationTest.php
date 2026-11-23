<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P031: a caller's Retail selectors may never replace the authorized
 * Wholesale Products workspace or admit a Retail-only account.
 */
final class V2WholesaleProductsHttpIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_products_http_scope_and_roles_are_isolated_in_both_locales(): void
    {
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-PRODUCTS-RETAIL',
            'name' => 'P031 Products Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $wholesaleActor = $this->actor('B2B_ADMIN');
        $retailActor = $this->actor('B2C_STORE_ADMIN', $retailStoreId);
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertTrue(Route::has('admin.b2b.module'));
        $canonical = route('admin.b2b.module', ['module' => 'products'], false);
        $this->assertSame('/admin/b2b/products', $canonical);

        $before = [];
        foreach (['orders', 'payments', 'invoices', 'driver_assignments'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $wholesaleActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($wholesaleActor->fresh())
                ->get($canonical)
                ->assertOk()
                ->assertViewHas('storeIds', [$wholesaleStoreId])
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($wholesaleActor->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'products',
                    'channel' => 'b2c',
                    'store_id' => $retailStoreId,
                ], false))
                ->assertOk()
                ->assertViewHas('storeIds', [$wholesaleStoreId])
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'products',
                    'channel' => 'b2b',
                    'store_id' => $retailStoreId,
                ], false))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get($canonical)
                ->assertForbidden();
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during Products GET');
        }
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
}
