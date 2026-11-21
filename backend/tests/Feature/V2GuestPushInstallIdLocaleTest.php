<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2GuestPushInstallIdLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_customer_push_requires_install_id_in_requested_language_without_writes(): void
    {
        foreach ([
            'ar' => 'معرّف التثبيت مطلوب لأجهزة تطبيق العميل الخاصة بالضيوف.',
            'en' => 'install_id is required for anonymous Customer devices.',
        ] as $locale => $message) {
            $payload = [
                'app' => 'customer',
                'platform' => 'android',
                'environment' => 'staging',
                'locale' => $locale,
                'token' => 'guest-token-'.$locale,
            ];
            $headers = ['Accept-Language' => $locale === 'ar' ? 'en-US' : 'ar-KW'];

            $this->postJson('/api/v1/push/devices/guest', $payload, $headers)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->postJson('/api/v1/push/devices/guest', [
                ...$payload,
                'install_id' => '',
            ], $headers)->assertUnprocessable()->assertJsonPath('message', $message);

            $this->assertDatabaseCount('push_device_tokens', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_anonymous_guest_default_locale_and_other_apps_remain_restricted(): void
    {
        $payload = [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'token' => 'guest-token-default-ar',
        ];

        $this->postJson('/api/v1/push/devices/guest', $payload, [
            'Accept-Language' => 'en-US',
        ])->assertUnprocessable()->assertJsonPath(
            'message',
            'معرّف التثبيت مطلوب لأجهزة تطبيق العميل الخاصة بالضيوف.',
        );

        foreach (['driver', 'van'] as $app) {
            $this->postJson('/api/v1/push/devices/guest', [
                ...$payload,
                'app' => $app,
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/push/devices/guest', [
            ...$payload,
            'locale' => 'fr',
        ])->assertUnprocessable()->assertJsonValidationErrors('locale');

        $this->assertDatabaseCount('push_device_tokens', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_valid_anonymous_customer_install_id_still_registers_a_device(): void
    {
        $this->postJson('/api/v1/push/devices/guest', [
            'app' => 'customer',
            'platform' => 'android',
            'environment' => 'staging',
            'locale' => 'en',
            'token' => 'guest-valid-push-token',
            'install_id' => 'guest-valid-install-id',
        ])->assertCreated()
            ->assertJsonPath('data.app', 'customer')
            ->assertJsonPath('data.anonymous', true);

        $this->assertDatabaseHas('push_device_tokens', [
            'app' => 'customer',
            'install_id' => 'guest-valid-install-id',
            'user_id' => null,
            'revoked_at' => null,
        ]);
    }
}
