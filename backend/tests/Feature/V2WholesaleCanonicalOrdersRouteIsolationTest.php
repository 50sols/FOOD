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
 * P031: The canonical Wholesale orders URL must enforce its own HTTP channel
 * and store scope, independently from /admin/operations/orders and Retail.
 */
final class V2WholesaleCanonicalOrdersRouteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_wholesale_orders_rejects_retail_store_and_channel_forgery_in_ar_en(): void
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->retailStore('P031-B2B-ORDERS-RETAIL');
        $wholesaleActor = $this->globalActor('B2B_ADMIN');
        $retailActor = $this->retailActor($retailStore);
        $rolelessActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->assertTrue(Route::has('admin.b2b.orders.canonical'));
        $this->assertSame(
            '/admin/b2b/orders',
            route('admin.b2b.orders.canonical', [], false),
        );

        $before = [
            'orders' => DB::table('orders')->count(),
            'payments' => DB::table('payments')->count(),
            'invoices' => DB::table('invoices')->count(),
            'assignments' => DB::table('driver_assignments')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $wholesaleActor->forceFill(['locale' => $locale])->saveQuietly();

            $this->actingAs($wholesaleActor->fresh())
                ->get(route('admin.b2b.orders.canonical', ['store_id' => $wholesaleStore], false))
                ->assertOk()
                ->assertViewIs('admin.order-operations')
                ->assertViewHas('selectedChannel', 'b2b')
                ->assertSee('dir="'.$direction.'"', false);

            // The canonical b2bIndex fixes channel=b2b before scope resolution,
            // even if a caller supplies a different channel query value.
            foreach (['b2c', 'all'] as $forgedChannel) {
                $this->actingAs($wholesaleActor->fresh())
                    ->get(route('admin.b2b.orders.canonical', [
                        'store_id' => $wholesaleStore,
                        'channel' => $forgedChannel,
                    ], false))
                    ->assertOk()
                    ->assertViewHas('selectedChannel', 'b2b');
            }

            $this->actingAs($wholesaleActor->fresh())
                ->get(route('admin.b2b.orders.canonical', ['store_id' => $retailStore], false))
                ->assertNotFound();

            $this->actingAs($retailActor->fresh())
                ->get(route('admin.b2b.orders.canonical', ['store_id' => $wholesaleStore], false))
                ->assertForbidden();

            $this->actingAs($rolelessActor)
                ->get(route('admin.b2b.orders.canonical', ['store_id' => $wholesaleStore], false))
                ->assertForbidden();
        }

        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['payments'], DB::table('payments')->count());
        $this->assertSame($before['invoices'], DB::table('invoices')->count());
        $this->assertSame($before['assignments'], DB::table('driver_assignments')->count());
    }

    private function globalActor(string $roleCode): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function retailActor(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
