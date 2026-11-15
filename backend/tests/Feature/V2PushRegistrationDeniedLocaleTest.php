<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2PushRegistrationDeniedLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_denied_driver_and_van_push_registration_uses_account_locale_without_writes(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        foreach ([
            'ar' => [
                'driver' => 'يمكن فقط لسائقي التجزئة (B2C) المصرّح لهم تسجيل أجهزة إشعارات تطبيق السائق.',
                'van' => 'الوصول إلى تطبيق الفان غير مفعّل لهذا الحساب.',
            ],
            'en' => [
                'driver' => 'Only authorized Retail (B2C) drivers may register Driver App push devices.',
                'van' => 'Van App access is not enabled for this account.',
            ],
        ] as $locale => $messages) {
            $actor = User::factory()->create([
                'is_active' => true,
                'locale' => $locale,
            ]);
            foreach (['driver', 'van'] as $app) {
                // Matching app ability must not bypass Driver profile or Van runtime authorization.
                Sanctum::actingAs($actor, ['app:'.$app]);

                $this->postJson('/api/v1/push/devices', [
                    'app' => $app,
                    'platform' => 'android',
                    'environment' => 'staging',
                    'token' => 'denied-'.$locale.'-'.$app,
                ], [
                    'Accept-Language' => $locale === 'ar' ? 'en-US' : 'ar-KW',
                ])->assertForbidden()
                    ->assertJsonPath('message', $messages[$app]);

                $this->assertDatabaseCount('push_device_tokens', 0);
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }
}
