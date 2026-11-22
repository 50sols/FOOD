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
 * P031: Wholesale Storefront Admin GET must not authorize Retail identities or
 * trust client-supplied channel/store selectors.
 */
final class V2WholesaleStorefrontHttpIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_storefront_http_isolation_in_arabic_and_english(): void
    {
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-STOREFRONT-RETAIL',
            'name' => 'P031 Storefront Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $wholesaleActor = $this->actor('B2B_ADMIN');
        $retailActor = $this->actor('B2C_STORE_ADMIN', $retailStoreId);
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertTrue(Route::has('admin.b2b.module'));
        $path = route('admin.b2b.module', ['module' => 'storefront'], false);
        $this->assertSame('/admin/b2b/storefront', $path);

        $counts = [];
        foreach (['orders', 'payments', 'invoices', 'driver_assignments'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $wholesaleActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($wholesaleActor->fresh())
                ->get($path)
                ->assertOk()
                ->assertViewHas('storeIds', [$wholesaleStoreId])
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($wholesaleActor->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'storefront',
                    'channel' => 'b2c',
                    'store_id' => $retailStoreId,
                ], false))
                ->assertOk()
                ->assertViewHas('storeIds', [$wholesaleStoreId])
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'storefront',
                    'channel' => 'b2b',
                    'store_id' => $retailStoreId,
                ], false))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get($path)
                ->assertForbidden();
        }

        foreach ($counts as $table => $before) {
            $this->assertSame($before, DB::table($table)->count(), $table.' changed during Storefront GET');
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
