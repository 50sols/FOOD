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
 * P031: /admin/b2c/orders belongs to OrderOperationsController, not
 * B2cWorkspaceController. Its independent HTTP scope gate must remain closed
 * to actors from another store or the Wholesale channel.
 */
final class V2RetailCanonicalOrdersRouteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_retail_orders_list_cannot_switch_store_or_channel_in_ar_and_en(): void
    {
        $ownedStore = $this->store('B2C', 'P031-ORDERS-OWNED');
        $foreignStore = $this->store('B2C', 'P031-ORDERS-FOREIGN');
        $wholesaleStore = $this->store('B2B', 'P031-ORDERS-WHOLESALE');

        $retailActor = $this->actor('B2C_STORE_ADMIN', $ownedStore);
        $wholesaleActor = $this->actor('B2B_ADMIN');
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertTrue(Route::has('admin.b2c.orders.canonical'));
        $this->assertSame(
            route('admin.b2c.orders.canonical', [], false),
            route('admin.b2c.module', ['module' => 'orders'], false),
        );

        $before = [
            'orders' => DB::table('orders')->count(),
            'assignments' => DB::table('driver_assignments')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $retailActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2c.orders.canonical', ['store_id' => $ownedStore], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            // b2cIndex forces the canonical Retail channel even when a client
            // forges a conflicting channel query parameter.
            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2c.orders.canonical', [
                    'store_id' => $ownedStore,
                    'channel' => 'b2b',
                ], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            foreach ([$foreignStore, $wholesaleStore] as $forbiddenStore) {
                $this->actingAs($retailActor->fresh())
                    ->get(route('admin.b2c.orders.canonical', [
                        'store_id' => $forbiddenStore,
                    ], false))
                    ->assertNotFound();
            }

            // A Wholesale-only or roleless principal has no Retail orders
            // scope even when a valid Retail store identifier is supplied.
            $this->actingAs($wholesaleActor)
                ->get(route('admin.b2c.orders.canonical', ['store_id' => $ownedStore], false))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get(route('admin.b2c.orders.canonical', ['store_id' => $ownedStore], false))
                ->assertForbidden();
        }

        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['assignments'], DB::table('driver_assignments')->count());
        $this->assertSame($before['payments'], DB::table('payments')->count());
        $this->assertSame($before['invoices'], DB::table('invoices')->count());
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

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
