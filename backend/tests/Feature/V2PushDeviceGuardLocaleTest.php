<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2PushDeviceGuardLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_driver_and_van_push_registration_uses_explicit_ar_en_locale_without_writes(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $baselineAuditCount = \Illuminate\Support\Facades\DB::table('audit_logs')->count();
        $messages = [
            'driver' => [
                'ar' => 'يُسمح فقط لسائقي التجزئة (B2C) المخوّلين بتسجيل أجهزة إشعارات تطبيق السائق.',
                'en' => 'Only authorized Retail (B2C) drivers may register Driver App push devices.',
            ],
            'van' => [
                'ar' => 'الوصول إلى تطبيق الفان غير مفعّل لهذا الحساب.',
                'en' => 'Van App access is not enabled for this account.',
            ],
        ];

        foreach ($messages as $app => $translations) {
            foreach ($translations as $locale => $message) {
                $otherLocale = $locale === 'ar' ? 'en' : 'ar';
                $user = User::factory()->create([
                    'is_active' => true,
                    'locale' => $otherLocale,
                ]);

                Sanctum::actingAs($user, ['app:'.$app]);

                $this->postJson('/api/v1/push/devices', [
                    'app' => $app,
                    'platform' => 'android',
                    'environment' => 'staging',
                    'locale' => $locale,
                    'token' => 'v2-denied-'.$app.'-'.$locale,
                ], ['Accept-Language' => $otherLocale])
                    ->assertForbidden()
                    ->assertJsonPath('message', $message);

                $this->assertDatabaseCount('push_device_tokens', 0);
                $this->assertSame($baselineAuditCount, \Illuminate\Support\Facades\DB::table('audit_logs')->count());
            }
        }
    }

    public function test_unauthorized_push_registration_falls_back_to_authenticated_user_locale(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        foreach ([
            'driver' => 'Only authorized Retail (B2C) drivers may register Driver App push devices.',
            'van' => 'Van App access is not enabled for this account.',
        ] as $app => $message) {
            $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
            Sanctum::actingAs($user, ['app:'.$app]);

            $this->postJson('/api/v1/push/devices', [
                'app' => $app,
                'platform' => 'android',
                'environment' => 'staging',
                'token' => 'v2-denied-user-locale-'.$app,
            ], ['Accept-Language' => 'ar-KW'])
                ->assertForbidden()
                ->assertJsonPath('message', $message);

            $this->assertDatabaseCount('push_device_tokens', 0);
        }
    }
}
