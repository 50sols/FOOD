<?php

namespace Tests\Feature;

use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2PushStoreChannelMismatchLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_customer_push_rejects_cross_channel_store_in_requested_ar_en_without_writes(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = app(WholesalePrincipal::class)->storeId();
        $baselineAuditCount = DB::table('audit_logs')->count();

        foreach ([
            'ar' => 'قناة الإشعارات المحددة لا تتوافق مع نوع هذا المتجر.',
            'en' => 'The selected notification channel does not match this store.',
        ] as $locale => $message) {
            $this->postJson('/api/v1/push/devices/guest', [
                'app' => 'customer',
                'platform' => 'android',
                'environment' => 'staging',
                'token' => 'v2-guest-mismatch-'.$locale,
                'install_id' => 'v2-guest-mismatch-install-'.$locale,
                'store_id' => $storeId,
                'target_channel' => 'b2c',
                'locale' => $locale,
            ], ['Accept-Language' => $locale === 'ar' ? 'en-US' : 'ar-KW'])
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertDatabaseCount('push_device_tokens', 0);
            $this->assertSame($baselineAuditCount, DB::table('audit_logs')->count());
        }
    }

    public function test_guest_push_without_requested_locale_rejects_mismatch_in_safe_arabic_default(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = app(WholesalePrincipal::class)->storeId();
        $baselineAuditCount = DB::table('audit_logs')->count();

        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'token' => 'v2-guest-mismatch-default',
            'install_id' => 'v2-guest-mismatch-default-install',
            'store_id' => $storeId,
            'target_channel' => 'b2c',
        ], ['Accept-Language' => 'en-US'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'قناة الإشعارات المحددة لا تتوافق مع نوع هذا المتجر.');

        $this->assertDatabaseCount('push_device_tokens', 0);
        $this->assertSame($baselineAuditCount, DB::table('audit_logs')->count());
    }

    public function test_guest_customer_push_accepts_matching_b2b_target_channel(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = app(WholesalePrincipal::class)->storeId();

        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'token' => 'v2-guest-matching-b2b',
            'install_id' => 'v2-guest-matching-install',
            'store_id' => $storeId,
            'target_channel' => 'b2b',
            'locale' => 'en',
        ])->assertCreated()
            ->assertJsonPath('data.app', 'customer')
            ->assertJsonPath('data.anonymous', true);

        $this->assertDatabaseHas('push_device_tokens', [
            'token_hash' => hash('sha256', 'v2-guest-matching-b2b'),
            'store_id' => $storeId,
            'target_channel' => 'b2b',
            'user_id' => null,
            'revoked_at' => null,
        ]);
    }
}
