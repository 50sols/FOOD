<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2VanDirectSalesDashboardFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_wholesale_actor_sees_only_scoped_van_origin_orders_not_other_b2b_orders(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $customer = app(B2bCustomerService::class)->create(['name' => 'P275 Wholesale Buyer']);

        $directOrderId = $this->order(
            $storeId,
            (int) $customer->legacy_customer_id,
            (int) $customer->getKey(),
            'P275-VAN-DIRECT-1001',
            'van',
        );
        $this->order(
            $storeId,
            (int) $customer->legacy_customer_id,
            (int) $customer->getKey(),
            'P275-DASHBOARD-1002',
            'dashboard',
        );

        $rogueStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P275-FOREIGN-B2B',
            'name' => 'P275 Foreign Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->order(
            $rogueStoreId,
            (int) $customer->legacy_customer_id,
            (int) $customer->getKey(),
            'P275-FOREIGN-VAN-1003',
            'van',
        );

        $actor = $this->superAdmin('p275-van-dashboard@example.test');

        $this->actingAs($actor)
            ->get('/admin/operations/orders?channel=b2b&origin=van&action_required=0')
            ->assertOk()
            ->assertViewHas('rows', static function (array $rows) use ($directOrderId): bool {
                return collect($rows)->pluck('id')->map(static fn ($id): int => (int) $id)->all() === [$directOrderId];
            })
            ->assertSee('data-p275-van-direct-sales-tab', false)
            ->assertSee('data-van-order-origin-filter', false)
            ->assertSee('Van direct sales')
            ->assertDontSee('P275-DASHBOARD-1002')
            ->assertDontSee('P275-FOREIGN-VAN-1003');

        $this->assertDatabaseCount('orders', 3);

        $actor->forceFill(['locale' => 'ar'])->save();
        app()->setLocale('ar');

        $this->actingAs($actor)
            ->get('/admin/operations/orders?channel=b2b&origin=van&action_required=0')
            ->assertOk()
            ->assertSee('البيع المباشر للفان')
            ->assertSee('data-p275-van-direct-sales-reset', false);

        $this->assertDatabaseCount('orders', 3);
    }

    public function test_retail_actor_and_all_channel_queries_cannot_use_van_direct_sale_source(): void
    {
        $retailStoreId = $this->retailStore('P275-RETAIL');
        $retailActor = $this->retailAdmin($retailStoreId, 'p275-retail@example.test');

        $this->actingAs($retailActor)
            ->get('/admin/operations/orders?channel=b2c&origin=van')
            ->assertForbidden();

        $this->actingAs($retailActor)
            ->get('/admin/operations/orders?channel=b2b&origin=van')
            ->assertForbidden();

        $this->actingAs($retailActor)
            ->get('/admin/operations/orders?origin=van')
            ->assertForbidden();

        $super = $this->superAdmin('p275-multichannel@example.test');
        app(WholesalePrincipal::class)->storeId();

        $this->actingAs($super)
            ->get('/admin/operations/orders?channel=all&origin=van')
            ->assertForbidden();

        $this->actingAs($retailActor)
            ->get('/admin/operations/orders?channel=b2c')
            ->assertOk()
            ->assertDontSee('data-p275-van-direct-sales-tab', false);
    }

    public function test_unknown_origin_is_rejected_before_any_order_mutation(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $super = $this->superAdmin('p275-invalid-origin@example.test');
        $before = DB::table('orders')->count();

        $this->actingAs($super)
            ->withHeaders(['Accept' => 'application/json'])
            ->get('/admin/operations/orders?channel=b2b&origin=not-van')
            ->assertUnprocessable();

        $this->assertSame($before, DB::table('orders')->count());
    }

    private function order(int $storeId, int $legacyCustomerId, int $customerId, string $number, string $origin): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $customerId,
            'order_number' => $number,
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 20,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 20,
            'pricing_snapshot' => json_encode(['commercial_origin' => $origin], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
