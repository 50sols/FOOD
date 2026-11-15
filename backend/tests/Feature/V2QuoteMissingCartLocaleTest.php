<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2QuoteMissingCartLocaleTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleStoreId;

    private int $retailStoreId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);

        $this->wholesaleStoreId = $this->store('B2B', 'WHOLESALE-MAIN');
        $this->retailStoreId = $this->store('B2C', 'V2-QUOTE-RETAIL');
    }

    public function test_missing_owned_cart_is_bilingual_conflict_for_both_channels_without_writes(): void
    {
        $channels = [
            [$this->wholesaleStoreId, $this->customer('v2-quote-wholesale@example.test', $this->wholesaleStoreId)],
            [$this->retailStoreId, $this->customer('v2-quote-retail@example.test', $this->retailStoreId)],
        ];

        foreach ($channels as [$storeId, $customer]) {
            Sanctum::actingAs($customer);

            foreach ([
                'ar' => 'لا توجد سلة تسوق مسجلة لهذا المتجر.',
                'en' => 'No authenticated cart exists for this store.',
            ] as $locale => $expected) {
                $customer->forceFill(['locale' => $locale])->save();

                $this->withHeader('Accept-Language', $locale === 'ar' ? 'en' : 'ar')
                    ->postJson('/api/v1/quote', ['store_id' => $storeId])
                    ->assertConflict()
                    ->assertJsonPath('message', $expected);

                $this->assertDatabaseCount('carts', 0);
                $this->assertDatabaseCount('cart_items', 0);
            }
        }
    }

    public function test_unsupported_customer_locale_falls_back_to_supported_accept_language(): void
    {
        $customer = $this->customer('v2-quote-unknown-locale@example.test', $this->retailStoreId);
        $customer->forceFill(['locale' => 'fr'])->save();
        Sanctum::actingAs($customer);

        $this->withHeader('Accept-Language', 'ar-KW,ar;q=0.9,en;q=0.7')
            ->postJson('/api/v1/quote', ['store_id' => $this->retailStoreId])
            ->assertConflict()
            ->assertJsonPath('message', 'لا توجد سلة تسوق مسجلة لهذا المتجر.');

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9,ar;q=0.4')
            ->postJson('/api/v1/quote', ['store_id' => $this->retailStoreId])
            ->assertConflict()
            ->assertJsonPath('message', 'No authenticated cart exists for this store.');

        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_quote_still_requires_authentication(): void
    {
        $this->postJson('/api/v1/quote', ['store_id' => $this->retailStoreId])
            ->assertUnauthorized();

        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    private function customer(string $email, int $storeId): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => 'V2 Quote Locale Customer',
            'email' => $email,
            'phone' => '+201000000777',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $storeId,
        ]);
    }

    private function store(string $type, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
