<?php

namespace Tests\Feature;

use App\Models\SmartOrderingCart;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingExpiredCartLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_public_cart_returns_localized_410_and_wrong_token_stays_404_without_writes(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-EXPIRED-CART-RETAIL',
            'name' => 'V2 Expired Cart Test Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'v2-expired-cart',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => 'v2-expired-cart-branch',
            'name_ar' => 'فرع سلة منتهية',
            'name_en' => 'Expired Cart Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            'en' => 'Cart expired.',
            'ar' => 'انتهت صلاحية سلة المشتريات.',
        ] as $locale => $expectedMessage) {
            $token = 'v2-smart-ordering-expired-'.$locale.'-token';
            $cart = SmartOrderingCart::query()->create([
                'public_id' => (string) Str::uuid(),
                'token_hash' => hash('sha256', $token),
                'branch_id' => $branchId,
                'status' => 'active',
                'expires_at' => now()->subMinute(),
            ]);

            $countsBefore = [
                'carts' => DB::table('smart_ordering_carts')->count(),
                'items' => DB::table('smart_ordering_cart_items')->count(),
                'orders' => DB::table('orders')->count(),
                'contexts' => DB::table('smart_ordering_order_contexts')->count(),
            ];

            app()->setLocale($locale);
            $url = '/api/smart-ordering/carts/'.$cart->public_id;

            $this->withHeaders([
                'Accept-Language' => $locale,
                'X-Smart-Cart-Token' => $token,
            ])->getJson($url)
                ->assertStatus(410)
                ->assertJsonPath('message', $expectedMessage);

            // Invalid bearer tokens must remain indistinguishable from missing carts.
            $this->withHeaders([
                'Accept-Language' => $locale,
                'X-Smart-Cart-Token' => 'invalid-'.$token,
            ])->getJson($url)->assertNotFound();

            $this->assertSame($countsBefore, [
                'carts' => DB::table('smart_ordering_carts')->count(),
                'items' => DB::table('smart_ordering_cart_items')->count(),
                'orders' => DB::table('orders')->count(),
                'contexts' => DB::table('smart_ordering_order_contexts')->count(),
            ]);
            $this->assertSame('active', (string) $cart->fresh()->status);
        }
    }
}
