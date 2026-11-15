<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2VolumeQuantityPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_volume_price_boundary_and_checkout_snapshot_remain_authoritative(): void
    {
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->pricingMode($storeId, true);
        Sanctum::actingAs($this->actor('B2B_ADMIN', 'volume-manager@example.test'));

        $payload = $this->rule($storeId, $productId, $tierId);
        $this->putJson('/api/v1/admin/b2b/prices', $payload)
            ->assertCreated()
            ->assertJsonPath('data.quantity_price_breaks.0.minimum_quantity', 10)
            ->assertJsonPath('data.quantity_price_breaks.0.unit_price', 8.5)
            ->assertJsonPath('data.quantity_price_breaks.1.minimum_quantity', 20)
            ->assertJsonPath('data.quantity_price_breaks.1.unit_price', 7.25);

        $buyer = $this->actor(null, 'volume-buyer@example.test');
        $customer = Customer::query()->create([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'Volume Buyer',
            'email' => $buyer->email,
        ]);
        $account = B2bAccount::query()->create([
            'customer_id' => $customer->id,
            'price_tier_id' => $tierId,
            'company_name' => 'Volume Buyer Co',
            'status' => 'active',
        ]);

        $address = Address::query()->create([
            'customer_id' => $customer->id,
            'label' => 'Warehouse',
            'line1' => 'Wholesale street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => true,
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson("/api/v1/b2b/products/{$productId}?store_id={$storeId}")
            ->assertOk()
            ->assertJsonPath('quantity_price_breaks.0.minimum_quantity', 10)
            ->assertJsonPath('quantity_price_breaks.1.unit_price', 7.25);

        $cart = $this->postJson('/api/v1/cart/items', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'quantity' => 5,
        ])->assertCreated()
            ->assertJsonPath('items.0.unit_price_snapshot', 10)
            ->assertJsonPath('subtotal', 50);

        $itemId = (int) $cart->json('items.0.id');
        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 10])
            ->assertOk()
            ->assertJsonPath('items.0.unit_price_snapshot', 8.5)
            ->assertJsonPath('subtotal', 85);
        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 20])
            ->assertOk()
            ->assertJsonPath('items.0.unit_price_snapshot', 7.25)
            ->assertJsonPath('subtotal', 145);

        $checkout = $this->withHeader('Idempotency-Key', 'v2-volume-quantity-00001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $storeId,
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated()
            ->assertJsonPath('items.0.unit_price', 7.25)
            ->assertJsonPath('subtotal', 145);

        $orderId = (int) $checkout->json('id');
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $productId,
            'unit_price' => 7.25,
            'line_total' => 145,
        ]);

        // Mutating the live price afterwards must NEVER rewrite booked financial history.
        DB::table('b2b_price_rules')->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->update(['unit_price' => 1, 'quantity_price_breaks' => '[]']);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'unit_price' => 7.25,
            'line_total' => 145,
        ]);
        $this->assertSame($tierId, (int) $account->price_tier_id);
    }

    public function test_invalid_duplicate_or_rising_volume_breaks_fail_atomically(): void
    {
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->pricingMode($storeId, true);
        Sanctum::actingAs($this->actor('B2B_ADMIN', 'volume-admin-reject@example.test'));
        $base = $this->rule($storeId, $productId, $tierId);
        $this->putJson('/api/v1/admin/b2b/prices', $base)->assertCreated();

        foreach ([
            [['minimum_quantity' => 10, 'unit_price' => 9], ['minimum_quantity' => 10, 'unit_price' => 8]],
            [['minimum_quantity' => 20, 'unit_price' => 8], ['minimum_quantity' => 10, 'unit_price' => 7]],
            [['minimum_quantity' => 11, 'unit_price' => 8]],
            [['minimum_quantity' => 10, 'unit_price' => 11]],
            [['minimum_quantity' => 10, 'unit_price' => -1]],
        ] as $breaks) {
            $this->putJson('/api/v1/admin/b2b/prices', [
                ...$base, 'quantity_price_breaks' => $breaks,
            ])->assertUnprocessable()->assertJsonValidationErrors('quantity_price_breaks');

            $this->assertDatabaseHas('b2b_price_rules', [
                'store_id' => $storeId,
                'product_id' => $productId,
                'unit_price' => 10,
                'minimum_quantity' => 5,
            ]);
        }
    }

    public function test_dashboard_break_editor_is_localized_and_denies_unapproved_actor(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->pricingMode($storeId, true);
        $manager = $this->actor('B2B_ADMIN', 'volume-ui@example.test');
        $this->actingAs($manager)->get('/admin/b2b/pricing-approvals')
            ->assertOk()
            ->assertSee('data-v2-volume-pricing-form', false)
            ->assertSee('Volume price breaks')
            ->assertSee('data-v2-add-volume-break', false);

        $manager->update(['locale' => 'ar']);
        $this->actingAs($manager)->get('/admin/b2b/pricing-approvals')
            ->assertOk()
            ->assertSee('أسعار الشرائح حسب الكمية')
            ->assertSee('إضافة شريحة كمية')
            ->assertSee('dir="rtl"', false);

        $finance = $this->actor('FINANCE', 'volume-finance@example.test');
        $this->actingAs($finance)->get('/admin/b2b/pricing-approvals')->assertForbidden();
    }

    /** @return array{int,int,int} */
    private function catalog(): array
    {
        $type = DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type, 'code' => 'V2-VOLUME-B2B', 'name' => 'Volume B2B',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store, 'channel' => 'b2b', 'code' => 'volume',
            'name' => 'Volume wholesale', 'is_active' => true,
            'is_migration_quarantine' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'V2-VOLUME-EA', 'name' => 'Each', 'decimal_places' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog, 'unit_id' => $unit, 'sku' => 'V2-VOLUME-P',
            'name' => 'Volume product', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store, 'product_id' => $product, 'price' => 10,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $store, 'code' => 'V2-VOLUME-WH', 'name' => 'Volume WH',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse, 'product_id' => $product,
            'quantity' => 100, 'reserved_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'V2-VOLUME-TIER', 'name' => 'Volume Tier', 'priority' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$store, $product, $tier];
    }

    private function pricingMode(int $storeId, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert([
            'store_id' => $storeId, 'key' => 'b2b.pricing_tiers_enabled',
        ], [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'is_secret' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actor(?string $role, string $email): User
    {
        $user = User::query()->create([
            'name' => 'V2 Volume Actor', 'email' => $email,
            'password' => 'password', 'is_active' => true, 'locale' => 'en',
        ]);
        if ($role !== null) {
            $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function rule(int $store, int $product, int $tier): array
    {
        return [
            'store_id' => $store, 'product_id' => $product,
            'price_tier_id' => $tier, 'unit_price' => 10,
            'minimum_quantity' => 5, 'ordering_increment' => 5,
            'quantity_price_breaks' => [
                ['minimum_quantity' => 10, 'unit_price' => 8.5],
                ['minimum_quantity' => 20, 'unit_price' => 7.25],
            ],
        ];
    }
}
