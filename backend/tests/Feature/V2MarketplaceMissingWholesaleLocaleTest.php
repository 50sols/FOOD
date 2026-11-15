<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2MarketplaceMissingWholesaleLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_public_marketplace_rejects_missing_active_wholesale_store_in_ar_en_without_mutations(): void
    {
        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->assertGreaterThan(0, $wholesaleType);
        $this->assertGreaterThan(0, $retailType);

        $wholesaleId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $wholesaleType,
            'code' => 'V2-3740-WHOLESALE',
            'name' => 'V2 Wholesale Marketplace Fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailType,
            'code' => 'V2-3740-RETAIL',
            'name' => 'V2 Retail Marketplace Fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/marketplace')
            ->assertOk()
            ->assertJsonPath('main_wholesale_store.channel', 'b2b');

        DB::table('stores')->where('store_type_id', $wholesaleType)->update(['is_active' => false]);
        $this->assertDatabaseHas('stores', ['id' => $wholesaleId, 'is_active' => false]);
        $this->assertDatabaseHas('stores', ['id' => $retailId, 'is_active' => true]);

        $storeCount = DB::table('stores')->count();
        $orderCount = DB::table('orders')->count();
        $cartCount = DB::table('carts')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach ([
            'ar-KW,ar;q=0.9,en;q=0.4' => 'متجر الجملة الرئيسي غير متاح حاليًا.',
            'en-US,en;q=0.9,ar;q=0.4' => 'Main Wholesale store is not configured.',
        ] as $acceptedLanguage => $message) {
            $this->getJson('/api/v1/marketplace', ['Accept-Language' => $acceptedLanguage])
                ->assertNotFound()
                ->assertJsonPath('message', $message)
                ->assertJsonMissingPath('main_wholesale_store');

            $this->assertSame($storeCount, DB::table('stores')->count());
            $this->assertSame($orderCount, DB::table('orders')->count());
            $this->assertSame($cartCount, DB::table('carts')->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertDatabaseHas('stores', ['id' => $retailId, 'is_active' => true]);
            $this->assertDatabaseHas('stores', ['id' => $wholesaleId, 'is_active' => false]);
        }
    }
}
