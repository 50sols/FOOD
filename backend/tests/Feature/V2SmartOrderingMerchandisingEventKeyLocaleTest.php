<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingMerchandisingEventKeyLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_merchandising_event_invalid_keys_return_exact_bilingual_422_without_writes(): void
    {
        $cart = (string) Str::uuid();
        $recommendation = (string) Str::uuid();
        $url = '/api/smart-ordering/carts/'.$cart.'/merchandising/events';
        $payload = [
            'event_type' => 'add',
            'placement' => 'cart_upsell',
            'recommendation_id' => $recommendation,
        ];

        foreach ([
            ['ar-KW', 'ar', 'مفتاح منع التكرار (Idempotency-Key) مطلوب.'],
            ['en-US', 'en', 'Idempotency-Key is required.'],
        ] as [$acceptLanguage, $locale, $expected]) {
            app()->setLocale($locale);

            foreach (['', 'short', str_repeat('x', 101)] as $invalidKey) {
                $this->withHeaders([
                    'Accept-Language' => $acceptLanguage,
                    'X-Smart-Cart-Token' => 'not-used-before-header-rejection',
                    'Idempotency-Key' => $invalidKey,
                ])->postJson($url, $payload)
                    ->assertUnprocessable()
                    ->assertJsonPath('message', $expected);

                $this->assertDatabaseCount('smart_ordering_merchandising_events', 0);
                $this->assertDatabaseCount('smart_ordering_carts', 0);
            }
        }
    }

    public function test_unsupported_accept_language_uses_supported_application_locale(): void
    {
        app()->setLocale('ar');

        $this->withHeaders([
            'Accept-Language' => 'fr-FR',
            'Idempotency-Key' => 'short',
        ])->postJson('/api/smart-ordering/carts/'.Str::uuid().'/merchandising/events', [
            'event_type' => 'add',
            'placement' => 'cart_upsell',
            'recommendation_id' => (string) Str::uuid(),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'مفتاح منع التكرار (Idempotency-Key) مطلوب.');

        $this->assertDatabaseCount('smart_ordering_merchandising_events', 0);
    }
}
