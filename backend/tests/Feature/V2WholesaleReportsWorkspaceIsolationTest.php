<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P031: the canonical Wholesale Reports HTTP page must not accept caller
 * selected Retail identity or bypass the server's channel authorization.
 */
final class V2WholesaleReportsWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_reports_route_preserves_channel_and_role_boundary_in_both_locales(): void
    {
        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-REPORTS-RETAIL',
            'name' => 'P031 Reports Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wholesale = $this->actor('B2B_ADMIN');
        $retail = $this->actor('B2C_STORE_ADMIN', $retailStore);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $before = [];
        foreach (['orders', 'payments', 'invoices', 'driver_assignments'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $route = route('admin.b2b.module', ['module' => 'reports'], false);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $wholesale->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($wholesale->fresh())
                ->get($route)
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            // Retail selectors on the URL must not replace the canonical
            // Wholesale principal with an attacker-controlled store/channel.
            $this->actingAs($wholesale->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'reports',
                    'channel' => 'b2c',
                    'store_id' => $retailStore,
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->actingAs($retail->fresh())
                ->get(route('admin.b2b.module', [
                    'module' => 'reports',
                    'channel' => 'b2b',
                    'store_id' => $retailStore,
                ], false))
                ->assertForbidden();

            $this->actingAs($roleless)
                ->get($route)
                ->assertForbidden();
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during Reports GET');
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
