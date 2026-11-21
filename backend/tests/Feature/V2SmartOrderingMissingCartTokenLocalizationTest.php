<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingMissingCartTokenLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_cart_token_header_required_in_both_locales_without_writes_or_cart_disclosure(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $unknownCartPublicId = (string) Str::uuid();
        $path = '/api/smart-ordering/carts/'.$unknownCartPublicId;

        foreach ([
            'en' => 'Cart token is required.',
            'ar' => 'رمز سلة الطلب الذكي مطلوب.',
        ] as $locale => $expectedMessage) {
            // Requests in the previous locale must not retain a forged token header.
            $this->flushHeaders();

            $before = [
                'carts' => DB::table('smart_ordering_carts')->count(),
                'items' => DB::table('smart_ordering_cart_items')->count(),
                'orders' => DB::table('orders')->count(),
                'contexts' => DB::table('smart_ordering_order_contexts')->count(),
            ];

            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale])
                ->getJson($path)
                ->assertStatus(401)
                ->assertJsonPath('message', $expectedMessage);

            // Whitespace-only headers cannot bypass the bearer token requirement.
            $this->withHeaders([
                'Accept-Language' => $locale,
                'X-Smart-Cart-Token' => '   ',
            ])->getJson($path)
                ->assertStatus(401)
                ->assertJsonPath('message', $expectedMessage);

            // With a present (but forged) bearer token, no cart existence is leaked.
            $this->withHeaders([
                'Accept-Language' => $locale,
                'X-Smart-Cart-Token' => 'forged-v2-token',
            ])->getJson($path)->assertNotFound();

            $this->assertSame($before, [
                'carts' => DB::table('smart_ordering_carts')->count(),
                'items' => DB::table('smart_ordering_cart_items')->count(),
                'orders' => DB::table('orders')->count(),
                'contexts' => DB::table('smart_ordering_order_contexts')->count(),
            ]);
        }
    }
}
