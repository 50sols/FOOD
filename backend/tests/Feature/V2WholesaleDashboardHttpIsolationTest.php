<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P031: the canonical Wholesale dashboard must retain its server-selected
 * Wholesale scope even when a caller forges Retail query parameters.
 */
final class V2WholesaleDashboardHttpIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_dashboard_enforces_channel_and_role_boundary_in_ar_and_en(): void
    {
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-DASHBOARD-RETAIL',
            'name' => 'P031 Dashboard Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wholesaleActor = $this->actor('B2B_ADMIN');
        $retailActor = $this->actor('B2C_STORE_ADMIN', $retailStore);
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertTrue(Route::has('admin.b2b.dashboard'));
        $path = route('admin.b2b.dashboard', [], false);
        $this->assertSame('/admin/b2b/dashboard', $path);

        $counts = [];
        foreach (['orders', 'payments', 'invoices', 'driver_assignments'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $wholesaleActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($wholesaleActor->fresh())
                ->get($path)
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            // Caller-supplied Retail selectors must never replace the
            // authenticated actor's canonical Wholesale store/channel.
            $this->actingAs($wholesaleActor->fresh())
                ->get(route('admin.b2b.dashboard', [
                    'channel' => 'b2c',
                    'store_id' => $retailStore,
                ], false))
                ->assertOk()
                ->assertViewHas('storeIds', static fn (array $ids): bool => ! in_array($retailStore, $ids, true))
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2b.dashboard', [
                    'channel' => 'b2b',
                    'store_id' => $retailStore,
                ], false))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get($path)
                ->assertForbidden();
        }

        foreach ($counts as $table => $before) {
            $this->assertSame($before, DB::table($table)->count(), $table.' changed during dashboard GET');
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
