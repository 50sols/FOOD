<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingBranchCapacityLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private string $branchPublicId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $storeType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'EA-V2-CAPACITY',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $store = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'code' => 'V2-CAPACITY-B2C',
            'name' => 'Capacity Restaurant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($store);

        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $store,
            'channel' => 'b2c',
            'code' => 'v2-capacity',
            'name' => 'Capacity Menu',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $category = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalog,
            'name' => 'Meals',
            'slug' => 'v2-capacity-meals',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'category_id' => $category,
            'unit_id' => $unit,
            'sku' => 'V2-CAPACITY-MEAL',
            'name' => 'Meal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $store,
            'product_id' => $this->productId,
            'price' => 1.250,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $store,
            'code' => 'V2-CAPACITY-WH',
            'name' => 'Capacity Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $this->productId,
            'quantity' => 50,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $store,
            'slug' => 'v2-capacity-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('smart_ordering_entitlements')->insert(
            collect(['ordering', 'dine_in', 'pickup', 'delivery'])
                ->map(fn (string $module): array => [
                    'site_id' => $site,
                    'branch_id' => null,
                    'scope_key' => 'site',
                    'module' => $module,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
        );
        $revisionPublicId = (string) Str::uuid();
        $payload = json_encode([
            'schema_version' => 1,
            'site' => ['slug' => 'v2-capacity', 'name_en' => 'Capacity Restaurant'],
        ], JSON_THROW_ON_ERROR);
        DB::table('smart_ordering_revisions')->insert([
            'public_id' => $revisionPublicId,
            'site_id' => $site,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site,
            'code' => 'capacity-kw',
            'name_ar' => 'فرع السعة',
            'name_en' => 'Capacity Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'max_active_orders' => 1,
            'capacity_window_minutes' => 60,
            'service_charge' => 0,
        ]);
        $this->branchPublicId = (string) $branch->public_id;
    }

    public function test_real_public_checkout_capacity_409_is_bilingual_and_never_writes_a_second_order(): void
    {
        foreach ([
            'ar' => 'وصل هذا الفرع إلى الحد الأقصى لعدد الطلبات في الوقت المحدد.',
            'en' => 'This branch has reached its order capacity for the selected time.',
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);

            [$firstCart, $firstToken] = $this->cartWithItem();
            $first = $this->checkout($firstCart, $firstToken, 'v2-capacity-first-'.$locale)
                ->assertCreated();
            $reference = (string) $first->json('data.reference');
            $this->assertNotSame('', $reference);

            [$blockedCart, $blockedToken] = $this->cartWithItem();
            $baseline = [
                'orders' => DB::table('orders')->count(),
                'contexts' => DB::table('smart_ordering_order_contexts')->count(),
                'payments' => DB::table('payments')->count(),
                'audit' => DB::table('audit_logs')->count(),
                'stock_movements' => DB::table('stock_movements')->count(),
                'cart_items' => DB::table('smart_ordering_cart_items')->count(),
                'inventory' => DB::table('inventories')->where('product_id', $this->productId)
                    ->first(['quantity', 'reserved_quantity']),
                'cart_status' => DB::table('smart_ordering_carts')
                    ->where('public_id', $blockedCart)->value('status'),
            ];

            $this->checkout($blockedCart, $blockedToken, 'v2-capacity-denied-'.$locale)
                ->assertConflict()
                ->assertJsonPath('message', $expected);

            foreach (['orders', 'contexts', 'payments', 'audit', 'stock_movements', 'cart_items'] as $table) {
                $this->assertSame($baseline[$table], DB::table(match ($table) {
                    'contexts' => 'smart_ordering_order_contexts',
                    'audit' => 'audit_logs',
                    'stock_movements' => 'stock_movements',
                    'cart_items' => 'smart_ordering_cart_items',
                    default => $table,
                })->count(), $table.' must not change after HTTP 409');
            }
            $this->assertEquals(
                $baseline['inventory'],
                DB::table('inventories')->where('product_id', $this->productId)
                    ->first(['quantity', 'reserved_quantity']),
            );
            $this->assertSame(
                $baseline['cart_status'],
                DB::table('smart_ordering_carts')->where('public_id', $blockedCart)->value('status'),
            );

            DB::table('smart_ordering_order_contexts')
                ->where('public_reference', $reference)
                ->update(['lifecycle_status' => 'COMPLETED', 'updated_at' => now()]);
        }
    }

    /** @return array{0: string, 1: string} */
    private function cartWithItem(): array
    {
        $created = $this->postJson('/api/smart-ordering/carts', [
            'branch_public_id' => $this->branchPublicId,
        ])->assertCreated();
        $cart = (string) $created->json('cart.public_id');
        $token = (string) $created->json('cart.token');

        $this->withHeader('X-Smart-Cart-Token', $token)
            ->postJson('/api/smart-ordering/carts/'.$cart.'/items', [
                'product_id' => $this->productId,
                'quantity' => 1,
            ])->assertCreated();

        return [$cart, $token];
    }

    private function checkout(string $cart, string $token, string $key)
    {
        return $this->withHeaders([
            'X-Smart-Cart-Token' => $token,
            'Idempotency-Key' => $key,
        ])->postJson('/api/smart-ordering/carts/'.$cart.'/checkout', [
            'fulfillment_mode' => 'pickup',
            'name' => 'Capacity Guest',
            'phone' => '+96550000000',
            'email' => 'capacity-guest@example.test',
        ]);
    }
}
