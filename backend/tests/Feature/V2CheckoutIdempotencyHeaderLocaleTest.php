<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2CheckoutIdempotencyHeaderLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_checkout_rejects_missing_short_and_oversized_idempotency_keys_in_ar_en_without_writes(): void
    {
        $before = [
            'orders' => DB::table('orders')->count(),
            'carts' => DB::table('carts')->count(),
            'payments' => DB::table('payments')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'notifications' => DB::table('notifications')->count(),
        ];

        foreach ([
            'ar' => 'يجب توفير ترويسة Idempotency-Key صالحة لإتمام الطلب.',
            'en' => 'A valid Idempotency-Key header is required.',
        ] as $locale => $message) {
            $user = User::factory()->create(['locale' => $locale, 'is_active' => true]);
            Sanctum::actingAs($user);

            foreach (['', 'short', str_repeat('a', 101)] as $badKey) {
                $this->postJson('/api/v1/checkout', [
                    'store_id' => 1,
                    'address_id' => 1,
                ], $badKey === '' ? [] : ['Idempotency-Key' => $badKey])
                    ->assertUnprocessable()
                    ->assertJsonPath('errors.Idempotency-Key.0', $message);

                foreach ($before as $table => $count) {
                    $this->assertSame($count, DB::table($table)->count(), $table.' must stay unchanged after invalid checkout header.');
                }
            }
        }
    }

    public function test_unsupported_customer_locale_falls_back_to_requested_arabic_language(): void
    {
        $user = User::factory()->create(['locale' => 'xx', 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/checkout', [
            'store_id' => 1,
            'address_id' => 1,
        ], ['Accept-Language' => 'ar-KW,ar;q=0.9,en;q=0.5'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.Idempotency-Key.0', 'يجب توفير ترويسة Idempotency-Key صالحة لإتمام الطلب.');
    }

    public function test_guest_checkout_still_requires_authentication(): void
    {
        $this->postJson('/api/v1/checkout', [
            'store_id' => 1,
            'address_id' => 1,
        ], ['Accept-Language' => 'ar-KW', 'Idempotency-Key' => str_repeat('a', 16)])
            ->assertUnauthorized();

        $this->assertDatabaseCount('orders', 0);
    }
}
