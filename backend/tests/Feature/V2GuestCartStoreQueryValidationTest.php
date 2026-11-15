<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2GuestCartStoreQueryValidationTest extends TestCase
{
    use RefreshDatabase;

    private int $store;

    private int $otherStore;

    private int $wholesaleStore;

    private int $inactiveStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');

        $this->store = $this->store('V2-GUEST-CART-QUERY-A', $retailType, true);
        $this->otherStore = $this->store('V2-GUEST-CART-QUERY-B', $retailType, true);
        $this->wholesaleStore = $this->store('V2-GUEST-CART-QUERY-W', $wholesaleType, true);
        $this->inactiveStore = $this->store('V2-GUEST-CART-QUERY-I', $retailType, false);
    }

    public function test_missing_or_malformed_guest_store_selector_rejects_invalid_query_without_writes_in_arabic_and_english(): void
    {
        $cartCount = DB::table('carts')->count();
        $itemCount = DB::table('cart_items')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            foreach ($this->invalidSelectors() as $query) {
                $this->getJson('/api/v1/cart?'.$query, ['Accept-Language' => $locale])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('store')
                    ->assertJsonPath('errors.store.0', $locale === 'ar'
                        ? 'معرّف متجر السلة غير صالح.'
                        : 'The cart store identifier is invalid.');
            }
        }

        $this->assertSame($cartCount, DB::table('carts')->count());
        $this->assertSame($itemCount, DB::table('cart_items')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_existing_guest_token_does_not_allow_malformed_store_selector_or_reassign_cart(): void
    {
        $initial = $this->getJson('/api/v1/cart?store='.$this->store)
            ->assertOk()->assertJsonPath('store_id', $this->store);
        $token = (string) $initial->headers->get('X-Guest-Token');
        $this->assertGreaterThanOrEqual(16, strlen($token));

        $cartId = (int) DB::table('carts')->where('guest_token', $token)->value('id');
        $cartCount = DB::table('carts')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            foreach ($this->invalidSelectors() as $query) {
                $this->withHeaders([
                    'X-Guest-Token' => $token,
                    'Accept-Language' => $locale,
                ])->getJson('/api/v1/cart?'.$query)
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('store')
                    ->assertJsonPath('errors.store.0', $locale === 'ar'
                        ? 'معرّف متجر السلة غير صالح.'
                        : 'The cart store identifier is invalid.');
            }
        }

        $this->withHeader('X-Guest-Token', $token)
            ->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('store_id', $this->store);

        $this->withHeader('X-Guest-Token', $token)
            ->getJson('/api/v1/cart?store='.$this->store)->assertOk()
            ->assertJsonPath('store_id', $this->store);

        $this->withHeader('X-Guest-Token', $token)
            ->getJson('/api/v1/cart?store='.$this->otherStore)->assertConflict();

        $this->assertDatabaseHas('carts', [
            'id' => $cartId,
            'store_id' => $this->store,
            'guest_token' => $token,
        ]);
        $this->assertSame($cartCount, DB::table('carts')->count());
        $this->assertSame(0, DB::table('cart_items')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_inactive_and_wholesale_stores_remain_unavailable_to_guest_cart_initialization(): void
    {
        $this->getJson('/api/v1/cart?store='.$this->wholesaleStore)->assertNotFound();
        $this->getJson('/api/v1/cart?store='.$this->inactiveStore)->assertNotFound();
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_authenticated_customer_malformed_store_query_is_rejected_before_cart_resolution_using_account_locale(): void
    {
        $user = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        Sanctum::actingAs($user);
        $cartCount = DB::table('carts')->count();

        $this->getJson('/api/v1/cart?store%5B%5D='.$this->store, [
            'Accept-Language' => 'en',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.store.0', 'معرّف متجر السلة غير صالح.');

        $user->forceFill(['locale' => 'en'])->save();

        $this->getJson('/api/v1/cart?store=wrong', [
            'Accept-Language' => 'ar',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.store.0', 'The cart store identifier is invalid.');

        $this->assertSame($cartCount, DB::table('carts')->count());
        $this->assertDatabaseCount('cart_items', 0);
    }

    /** @return list<string> */
    private function invalidSelectors(): array
    {
        return [
            'store%5B%5D=1',
            'store%5Bnested%5D=1',
            'store=',
            'store=0',
            'store=-2',
            'store=abc',
            'store=1.5',
        ];
    }

    private function store(string $code, int $type, bool $active): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
