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
        $originalBreaks = DB::table('b2b_price_rules')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->value('quantity_price_breaks');

        // Domain normalization reports structural errors at the list level;
        // Laravel's numeric rule reports a negative price on its nested field.
        foreach ([
            [[['minimum_quantity' => 10, 'unit_price' => 9], ['minimum_quantity' => 10, 'unit_price' => 8]], 'quantity_price_breaks'],
            [[['minimum_quantity' => 20, 'unit_price' => 8], ['minimum_quantity' => 10, 'unit_price' => 7]], 'quantity_price_breaks'],
            [[['minimum_quantity' => 11, 'unit_price' => 8]], 'quantity_price_breaks'],
            [[['minimum_quantity' => 10, 'unit_price' => 11]], 'quantity_price_breaks'],
            [[['minimum_quantity' => 10, 'unit_price' => -1]], 'quantity_price_breaks.0.unit_price'],
        ] as [$breaks, $expectedErrorField]) {
            $this->putJson('/api/v1/admin/b2b/prices', [
                ...$base, 'quantity_price_breaks' => $breaks,
            ])->assertUnprocessable()->assertJsonValidationErrors($expectedErrorField);

            $this->assertDatabaseHas('b2b_price_rules', [
                'store_id' => $storeId,
                'product_id' => $productId,
                'unit_price' => 10,
                'minimum_quantity' => 5,
                'quantity_price_breaks' => $originalBreaks,
            ]);
        }
    }

    public function test_legacy_price_update_cannot_invalidate_retained_quantity_breaks(): void
    {
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->pricingMode($storeId, true);
        Sanctum::actingAs($this->actor('B2B_ADMIN', 'volume-legacy-guard@example.test'));

        $original = $this->rule($storeId, $productId, $tierId);
        $this->putJson('/api/v1/admin/b2b/prices', $original)->assertCreated();
        $savedBreaks = DB::table('b2b_price_rules')
            ->where('store_id', $storeId)->where('product_id', $productId)
            ->value('quantity_price_breaks');
        $auditCount = DB::table('audit_logs')->count();

        // Existing clients omit the new field entirely. Compatible requests
        // keep the schedule; incompatible base terms must fail before writes.
        $legacy = $original;
        unset($legacy['quantity_price_breaks']);
        foreach ([
            ['unit_price' => 7],
            ['minimum_quantity' => 10],
            ['ordering_increment' => 3],
        ] as $changed) {
            $this->putJson('/api/v1/admin/b2b/prices', [...$legacy, ...$changed])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('quantity_price_breaks');

            $this->assertDatabaseHas('b2b_price_rules', [
                'store_id' => $storeId,
                'product_id' => $productId,
                'unit_price' => 10,
                'minimum_quantity' => 5,
                'ordering_increment' => 5,
                'quantity_price_breaks' => $savedBreaks,
            ]);
            $this->assertDatabaseCount('audit_logs', $auditCount);
        }

        $this->putJson('/api/v1/admin/b2b/prices', $legacy)
            ->assertOk()
            ->assertJsonPath('data.quantity_price_breaks.0.minimum_quantity', 10);
        $this->assertSame($savedBreaks, DB::table('b2b_price_rules')
            ->where('store_id', $storeId)->where('product_id', $productId)
            ->value('quantity_price_breaks'));

        // An explicit empty schedule is the supported way to change the
        // base terms and return safely to legacy flat pricing.
        $this->putJson('/api/v1/admin/b2b/prices', [
            ...$legacy,
            'unit_price' => 7,
            'quantity_price_breaks' => [],
        ])->assertOk()->assertJsonPath('data.quantity_price_breaks', []);

        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 7,
            'quantity_price_breaks' => '[]',
        ]);
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

    public function test_pricing_read_only_actor_sees_price_rows_without_edit_actions_in_both_locales(): void
    {
        [$storeId, $productId, $tierId] = $this->catalog(usePrincipalStore: true);
        $this->pricingMode($storeId, true);
        $manager = $this->actor('B2B_ADMIN', 'volume-reader-seed@example.test');
        Sanctum::actingAs($manager);
        $this->putJson('/api/v1/admin/b2b/prices', $this->rule($storeId, $productId, $tierId))
            ->assertCreated();

        $reader = $this->actor('B2B_ADMIN', 'volume-read-only@example.test');
        DB::table('user_permission_overrides')->insert([
            'user_id' => $reader->getKey(),
            'permission_id' => DB::table('permissions')->where('code', 'b2b.pricing.manage')->value('id'),
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reader->forceFill(['has_permission_overrides' => true])->save();

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $reader->forceFill(['locale' => $locale])->save();

            $this->actingAs($reader->fresh())
                ->get('/admin/b2b/pricing-approvals')
                ->assertOk()
                ->assertSee('Volume product')
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee('data-v2-pricing-edit', false)
                ->assertDontSee('data-v2-volume-pricing-form', false)
                ->assertViewHas('moduleData', static fn (array $data): bool => ! in_array('actions', $data['columns'], true));
        }

        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 10,
        ]);
    }

    public function test_editing_volume_breaks_preserves_existing_pack_reference_and_inactive_status(): void
    {
        [$storeId, $productId, $tierId] = $this->catalog(usePrincipalStore: true);
        $this->pricingMode($storeId, true);
        $manager = $this->actor('B2B_ADMIN', 'volume-pack-editor@example.test');
        Sanctum::actingAs($manager);

        $original = [
            ...$this->rule($storeId, $productId, $tierId),
            'pack_size' => 12,
            'case_size' => 24,
            'pack_label' => 'Case of 12',
            'retail_reference_price' => 15.5,
            'is_active' => false,
        ];
        $this->putJson('/api/v1/admin/b2b/prices', $original)->assertCreated();

        $this->actingAs($manager)
            ->get('/admin/b2b/pricing-approvals')
            ->assertOk()
            ->assertSee('data-v2-pricing-edit', false)
            ->assertSee('data-pack-size="12"', false)
            ->assertSee('data-case-size="24"', false)
            ->assertSee('data-pack-label="Case of 12"', false)
            ->assertSee('data-retail-reference="15.5"', false)
            ->assertSee('data-active="0"', false);

        // The Dashboard edit form must round-trip metadata that the volume
        // pricing editor does not otherwise expose as mutable controls.
        $this->actingAs($manager)
            ->post(route('admin.b2b.pricing.save'), [
                ...$original,
                'quantity_price_breaks' => [['minimum_quantity' => 10, 'unit_price' => 7.5]],
                'replace_quantity_price_breaks' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price_tier_id' => $tierId,
            'unit_price' => 10,
            'pack_size' => 12,
            'case_size' => 24,
            'pack_label' => 'Case of 12',
            'retail_reference_price' => 15.5,
            'is_active' => false,
        ]);
        $saved = DB::table('b2b_price_rules')
            ->where('store_id', $storeId)
            ->where('product_id', $productId)
            ->value('quantity_price_breaks');
        $this->assertEquals(
            [['minimum_quantity' => 10, 'unit_price' => 7.5]],
            json_decode((string) $saved, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{int,int,int} */
    private function catalog(bool $usePrincipalStore = false): array
    {
        $type = DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = $usePrincipalStore
            ? app(WholesalePrincipal::class)->storeId()
            : (int) DB::table('stores')->insertGetId([
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
