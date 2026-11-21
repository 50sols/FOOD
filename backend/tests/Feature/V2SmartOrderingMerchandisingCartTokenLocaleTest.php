<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingMerchandisingCartTokenLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_merchandising_get_and_post_require_cart_bearer_in_exact_arabic_and_english(): void
    {
        $cart = (string) Str::uuid();
        $getPath = '/api/smart-ordering/carts/'.$cart.'/merchandising?placement=cart_upsell';
        $postPath = '/api/smart-ordering/carts/'.$cart.'/merchandising/events';
        $event = [
            'event_type' => 'add',
            'placement' => 'cart_upsell',
            'recommendation_id' => (string) Str::uuid(),
        ];

        foreach ([
            ['ar-KW', 'en', 'رمز سلة الطلب الذكي مطلوب.'],
            ['en-US', 'ar', 'Cart token is required.'],
        ] as [$acceptLanguage, $oppositeDefault, $expected]) {
            app()->setLocale($oppositeDefault);

            foreach ([null, '   '] as $missingOrWhitespaceToken) {
                $this->flushHeaders();
                $headers = ['Accept-Language' => $acceptLanguage];
                if ($missingOrWhitespaceToken !== null) {
                    $headers['X-Smart-Cart-Token'] = $missingOrWhitespaceToken;
                }

                $this->withHeaders($headers)
                    ->getJson($getPath)
                    ->assertUnauthorized()
                    ->assertJsonPath('message', $expected);

                $this->withHeaders([...$headers, 'Idempotency-Key' => 'merch-cart-token-0001'])
                    ->postJson($postPath, $event)
                    ->assertUnauthorized()
                    ->assertJsonPath('message', $expected);

                $this->assertNoWrites();
            }
        }
    }

    public function test_unsupported_accept_language_uses_supported_application_locale_for_missing_cart_token(): void
    {
        app()->setLocale('ar');
        $this->flushHeaders();

        $this->withHeaders(['Accept-Language' => 'fr-FR'])
            ->getJson('/api/smart-ordering/carts/'.Str::uuid().'/merchandising?placement=cart_upsell')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'رمز سلة الطلب الذكي مطلوب.');

        $this->assertNoWrites();
    }

    private function assertNoWrites(): void
    {
        foreach ([
            'smart_ordering_carts',
            'smart_ordering_cart_items',
            'smart_ordering_merchandising_events',
            'smart_ordering_order_contexts',
            'orders',
            'payments',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'Unexpected write in '.$table);
        }
    }
}
