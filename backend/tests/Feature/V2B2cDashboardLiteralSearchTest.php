<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DashboardDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2B2cDashboardLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_dashboard_search_treats_special_characters_as_literals_and_never_crosses_store_scope(): void
    {
        $this->seed(DashboardDemoSeeder::class);

        $storeId = (int) DB::table('stores')->where('code', 'FOODEX-DEMO-B2C')->value('id');
        $foreignStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-3634-FOREIGN',
            'name' => 'Unassigned Retail Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->where('email', 'admin.demo@foodex.test')->firstOrFail();
        $literal = 'Q%_!X';

        $this->addProduct($storeId, 'V2-Q%_!X', 'Retail Q%_!X');
        $this->addProduct($storeId, 'V2-QABC!X', 'Wildcard decoy QABC!X');
        $this->addProduct($foreignStoreId, 'FOREIGN-Q%_!X', 'Foreign Q%_!X');
        $this->addProduct($storeId, 'V2-ARABIC', 'زيت زيتون للبحث');
        $this->addProduct($storeId, 'V2-SLASH', 'Slash\Retail path');

        $customerId = $this->addCustomer($storeId, 'Retail client Q%_!X', 'client3634@example.test');
        $this->addCustomer($storeId, 'Wildcard decoy QABC!X', 'decoy3634@example.test');
        $foreignCustomerId = $this->addCustomer($foreignStoreId, 'Foreign client Q%_!X', 'foreign3634@example.test');
        $this->addCustomer($storeId, 'Email only customer', 'mail%_!3634@example.test');
        $this->addCustomer($storeId, 'Phone only customer', 'phone3634@example.test', '555%_!3634');

        $orderId = $this->addOrder($storeId, $customerId, 'V2-ORDER-Q%_!X');
        $this->addOrder($storeId, $customerId, 'V2-ORDER-QABC!X');
        $this->addOrder($foreignStoreId, $foreignCustomerId, 'FOREIGN-ORDER-Q%_!X');

        $before = [
            'orders' => DB::table('orders')->count(),
            'customers' => DB::table('b2c_customers')->count(),
            'products' => DB::table('products')->count(),
        ];

        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'q' => $literal]))
            ->assertOk()
            ->assertViewHas('dashboard', function (array $dashboard) use ($orderId): bool {
                $results = collect($dashboard['search']);

                return $results->pluck('type')->sort()->values()->all() === ['customer', 'order', 'product']
                    && $results->firstWhere('type', 'order')['title'] === '#'.$orderId
                    && $results->firstWhere('type', 'product')['subtitle'] === 'V2-Q%_!X'
                    && $results->firstWhere('type', 'customer')['title'] === 'Retail client Q%_!X';
            });

        // A numeric #ID remains an exact order lookup even when the text
        // predicate has no match. A foreign store never becomes selectable.
        $this->actingAs($user)
            ->get(route('admin.b2c.module', ['module' => 'dashboard', 'q' => '#'.$orderId]))
            ->assertOk()
            ->assertViewHas('dashboard', fn (array $dashboard): bool => collect($dashboard['search'])
                ->contains(fn (array $row): bool => $row['type'] === 'order' && $row['title'] === '#'.$orderId));

        $this->actingAs($user)
            ->get(route('admin.b2c.module', [
                'module' => 'dashboard',
                'store_id' => $foreignStoreId,
                'q' => $literal,
            ]))
            ->assertNotFound();

        foreach ([
            ['term' => 'mail%_!', 'type' => 'customer', 'title' => 'Email only customer'],
            ['term' => '555%_!', 'type' => 'customer', 'title' => 'Phone only customer'],
            ['term' => 'زيت زيتون للبحث', 'type' => 'product', 'title' => 'زيت زيتون للبحث'],
            ['term' => 'Slash\Retail', 'type' => 'product', 'title' => 'Slash\Retail path'],
        ] as $case) {
            $this->actingAs($user)
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'q' => $case['term']]))
                ->assertOk()
                ->assertViewHas('dashboard', fn (array $dashboard): bool => collect($dashboard['search'])
                    ->contains(fn (array $row): bool => $row['type'] === $case['type'] && $row['title'] === $case['title']));
        }

        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['customers'], DB::table('b2c_customers')->count());
        $this->assertSame($before['products'], DB::table('products')->count());
    }

    private function addProduct(int $storeId, string $sku, string $name): void
    {
        $productId = (int) DB::table('products')->insertGetId([
            'unit_id' => DB::table('units')->where('code', 'PC')->value('id'),
            'sku' => $sku,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addCustomer(int $storeId, string $name, string $email, ?string $phone = null): int
    {
        $legacyId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('b2c_customers')->insertGetId([
            'legacy_customer_id' => $legacyId,
            'store_id' => $storeId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addOrder(int $storeId, int $customerId, string $reference): int
    {
        $legacyId = (int) DB::table('b2c_customers')
            ->where('id', $customerId)
            ->value('legacy_customer_id');

        return (int) DB::table('orders')->insertGetId([
            'store_id' => $storeId,
            'customer_id' => $legacyId,
            'b2c_customer_id' => $customerId,
            'order_number' => $reference,
            'channel' => 'b2c',
            'status' => 'processing',
            'currency' => 'EGP',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
