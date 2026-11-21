<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\CatalogOwnership;
use App\Services\OrderManualDispatchService;
use App\Services\VanRegistryService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #2705 / T115R1: a real Wholesale Dashboard order must remain B2B,
 * reach a Van (never a legacy Driver), and complete through the authenticated
 * Van execution HTTP contract. The V2 owner has deferred Flutter source changes.
 */
final class V2Stage1WholesaleOrderDeliveryJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_wholesale_dashboard_order_is_delivered_by_its_assigned_van_without_driver_fallback(): void
    {
        [$storeId, $warehouseId, $productId, $customerId] = $this->wholesaleFixture();
        $admin = $this->actor('B2B_ADMIN', 'ar');

        // Use the existing real Admin route, not a fabricated Order fixture.
        $this->actingAs($admin)->post('/admin/b2b/orders', [
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'payment_method' => config('checkout.default_payment_method'),
            'discount_total' => 0,
            'delivery_total' => 0,
            'items' => [['product_id' => $productId, 'quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = Order::query()->where('store_id', $storeId)
            ->where('channel', 'b2b')->latest('id')->firstOrFail();

        $this->assertSame($warehouseId, (int) $order->warehouse_id);
        $this->assertSame('b2b', $order->channel);
        $this->assertDatabaseMissing('driver_assignments', ['order_id' => $order->id]);

        $this->actingAs($admin)->get('/admin/b2b/orders')
            ->assertOk()->assertSee('dir="rtl"', false);
        $admin->forceFill(['locale' => 'en'])->save();
        $this->actingAs($admin->fresh())->get('/admin/b2b/orders')
            ->assertOk()->assertSee('dir="ltr"', false);

        // A Van's representative is tied to one real registry assignment.
        $vanActor = $this->actor('VAN_OPERATOR', 'en');
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'STAGE1-VAN-'.$vanActor->id]);
        $registry->assign($vanActor, $van, [
            'representative_user_id' => $vanActor->id,
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);

        $state = app(OrderManualDispatchService::class)
            ->assignVan($order, $admin->fresh(), (int) $van->id, 'Stage 1 wholesale delivery');
        $this->assertSame('van', $state->current_assignee_type);
        $this->assertSame((int) $van->id, (int) $state->current_assignee_id);
        $this->assertDatabaseHas('order_van_assignments', [
            'order_id' => $order->id,
            'van_id' => $van->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('driver_assignments', ['order_id' => $order->id]);

        Sanctum::actingAs($vanActor, ['app:van']);
        $path = '/api/v1/van/orders/'.$order->id.'/execution';
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'assigned');

        foreach (['accepted', 'picked_up', 'out_for_delivery'] as $index => $status) {
            $this->withHeader('Idempotency-Key', 'stage1-transition-'.$index)
                ->postJson($path.'/transition', ['status' => $status])
                ->assertOk()->assertJsonPath('data.status', $status);
        }

        $this->withHeader('Idempotency-Key', 'stage1-proof-01')
            ->post($path.'/proof', [
                'proof_image' => UploadedFile::fake()->image('stage1-delivery.jpg', 640, 480),
                'note' => 'Wholesale delivery confirmation',
            ])
            ->assertOk()->assertJsonPath('data.delivery_proof_ready', true);

        // A fixture payment represents the independently collected COD amount;
        // no Finance/AR/Treasury posting or fabricated receipt lineage is claimed.
        DB::table('payments')->insert([
            'order_id' => $order->id,
            'invoice_id' => null,
            'provider' => 'cash_on_delivery',
            'provider_reference' => 'STAGE1-COD-'.$order->id,
            'status' => 'paid',
            'amount' => $order->grand_total,
            'currency' => $order->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'stage1-delivered-01')
            ->postJson($path.'/transition', ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.order_status', 'delivered');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'store_id' => $storeId,
            'channel' => 'b2b',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseCount('driver_assignments', 0);
        $this->assertSame(1, DB::table('order_van_assignments')
            ->where('order_id', $order->id)->where('status', 'active')->count());
    }

    public function test_retail_actor_and_foreign_store_warehouse_cannot_create_wholesale_orders(): void
    {
        [$storeId, $warehouseId, $productId, $customerId] = $this->wholesaleFixture();
        $retailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'STAGE1-RETAIL-FOREIGN',
            'name' => 'Stage 1 Retail Foreign',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailWarehouse = $this->warehouse($retailId, 'STAGE1-FOREIGN-WH');
        $admin = $this->actor('B2B_ADMIN', 'ar');
        $retailAdmin = $this->actor('B2C_STORE_ADMIN', 'en');
        $payload = [
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'payment_method' => config('checkout.default_payment_method'),
            'items' => [['product_id' => $productId, 'quantity' => 2]],
            'discount_total' => 0,
            'delivery_total' => 0,
        ];
        $beforeOrders = DB::table('orders')->count();
        $beforeReservations = DB::table('inventories')->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)->value('reserved_quantity');

        $this->actingAs($admin)->postJson('/admin/b2b/orders', [
            ...$payload,
            'warehouse_id' => $retailWarehouse,
        ])->assertUnprocessable();

        $this->actingAs($retailAdmin)->postJson('/admin/b2b/orders', $payload)
            ->assertForbidden();

        $this->assertSame($beforeOrders, DB::table('orders')->count());
        $this->assertEquals($beforeReservations, DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)->value('reserved_quantity'));
        $this->assertDatabaseCount('order_van_assignments', 0);
        $this->assertDatabaseCount('driver_assignments', 0);
        $this->assertSame($storeId, app(WholesalePrincipal::class)->storeId());
    }

    /** @return array{int,int,int,int} */
    private function wholesaleFixture(): array
    {
        $store = app(WholesalePrincipal::class)->storeId();
        $warehouse = $this->warehouse($store, 'STAGE1-WH');
        $catalog = app(CatalogOwnership::class)->defaultCatalogForStore($store, 'b2b');
        $unit = DB::table('units')->where('is_active', true)->value('id');
        if ($unit === null) {
            $unit = DB::table('units')->insertGetId([
                'scope' => 'global', 'scope_key' => 'global', 'code' => 'STAGE1-EA',
                'name' => 'Each', 'name_ar' => 'قطعة', 'name_en' => 'Each',
                'decimal_places' => 0, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog->id,
            'unit_id' => $unit,
            'sku' => 'STAGE1-ORDER-PRODUCT',
            'name' => 'Stage 1 Wholesale Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $product,
            'price' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'STAGE1-GOLD',
            'name' => 'Stage 1 Gold',
            'priority' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $legacy = (int) DB::table('customers')->insertGetId([
            'type' => 'b2b',
            'name' => 'Stage 1 Buyer',
            'email' => 'stage1-wholesale@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customer = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacy,
            'name' => 'Stage 1 Buyer',
            'email' => 'stage1-wholesale@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacy,
            'b2b_customer_id' => $customer,
            'price_tier_id' => $tier,
            'company_name' => 'Stage 1 Buyer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tier,
            'store_id' => $store,
            'product_id' => $product,
            'unit_price' => 7.250,
            'minimum_quantity' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('settings')->updateOrInsert(
            ['store_id' => $store, 'key' => 'b2b.pricing_tiers_enabled'],
            ['value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false, 'created_at' => now(), 'updated_at' => now()],
        );

        return [$store, $warehouse, $product, $customer];
    }

    private function warehouse(int $storeId, string $code): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actor(string $role, string $locale): User
    {
        $user = User::factory()->create([
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
