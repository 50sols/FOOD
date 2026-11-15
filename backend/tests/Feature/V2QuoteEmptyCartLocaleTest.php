<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2QuoteEmptyCartLocaleTest extends TestCase
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
        $this->retailStoreId = $this->store('B2C', 'V2-EMPTY-QUOTE-RETAIL');
    }

    public function test_existing_owned_empty_cart_is_bilingual_conflict_for_both_channels_without_writes(): void
    {
        foreach ([
            [$this->wholesaleStoreId, 'v2-empty-quote-b2b@example.test', 'b2b'],
            [$this->retailStoreId, 'v2-empty-quote-b2c@example.test', 'b2c'],
        ] as [$storeId, $email, $channel]) {
            $customer = $this->customer($email, $storeId);
            Sanctum::actingAs($customer);

            // Create a real, authenticated, owned cart; unlike #3765 it exists but has zero items.
            $cartResponse = $this->withHeaders([
                'X-Guest-Token' => '',
                'Accept-Language' => 'en',
            ])->getJson("/api/v1/cart?store={$storeId}")
                ->assertOk()
                ->assertJsonPath('channel', $channel)
                ->assertJsonCount(0, 'items');

            $cartId = (int) $cartResponse->json('id');
            $this->assertGreaterThan(0, $cartId);
            $before = $this->snapshot();

            foreach ([
                'ar' => ['en', 'سلة التسوق فارغة.'],
                'en' => ['ar', 'The cart is empty.'],
            ] as $locale => [$oppositeHeader, $message]) {
                $customer->forceFill(['locale' => $locale])->save();

                $this->withHeaders([
                    'X-Guest-Token' => '',
                    'Accept-Language' => $oppositeHeader,
                ])->postJson('/api/v1/quote', ['store_id' => $storeId])
                    ->assertConflict()
                    ->assertJsonPath('message', $message);

                $this->assertSame($before, $this->snapshot());
                $this->assertDatabaseHas('carts', ['id' => $cartId, 'store_id' => $storeId, 'channel' => $channel]);
                $this->assertDatabaseMissing('cart_items', ['cart_id' => $cartId]);
            }
        }
    }

    public function test_unsupported_actor_locale_uses_negotiated_supported_language(): void
    {
        $customer = $this->customer('v2-empty-quote-fallback@example.test', $this->retailStoreId);
        Sanctum::actingAs($customer);

        $this->withHeader('X-Guest-Token', '')
            ->getJson("/api/v1/cart?store={$this->retailStoreId}")
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $customer->forceFill(['locale' => 'fr'])->save();
        $before = $this->snapshot();

        $this->withHeader('Accept-Language', 'ar-KW,ar;q=0.9,en;q=0.5')
            ->postJson('/api/v1/quote', ['store_id' => $this->retailStoreId])
            ->assertConflict()
            ->assertJsonPath('message', 'سلة التسوق فارغة.');

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9,ar;q=0.5')
            ->postJson('/api/v1/quote', ['store_id' => $this->retailStoreId])
            ->assertConflict()
            ->assertJsonPath('message', 'The cart is empty.');

        $this->assertSame($before, $this->snapshot());
    }

    public function test_quote_remains_authenticated_and_does_not_create_a_cart(): void
    {
        $this->postJson('/api/v1/quote', ['store_id' => $this->retailStoreId])
            ->assertUnauthorized();

        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    /**
     * @return array<string, int|string>
     */
    private function snapshot(): array
    {
        $counts = [];
        foreach (['carts', 'cart_items', 'orders', 'order_items', 'invoices', 'payments', 'audit_logs'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        // Include stock balances and reservations, not merely row counts.
        $counts['inventory_state'] = DB::table('inventories')
            ->orderBy('id')
            ->get(['id', 'quantity', 'reserved_quantity'])
            ->toJson();

        return $counts;
    }

    private function customer(string $email, int $storeId): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => 'V2 Existing Empty Quote Customer',
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
