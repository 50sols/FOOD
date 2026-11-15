<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2MobileRuntimeMissingLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_mobile_runtime_is_localized_for_each_app_without_writes(): void
    {
        foreach (['customer', 'driver', 'van'] as $app) {
            foreach ([
                'ar' => 'لم يتم إعداد إعدادات تشغيل التطبيق.',
                'en' => 'Mobile runtime settings are not configured.',
            ] as $locale => $message) {
                $contraryLanguage = $locale === 'ar' ? 'en-US' : 'ar-KW';

                $this->getJson(
                    "/api/v1/mobile/runtime?app={$app}&environment=staging&locale={$locale}",
                    ['Accept-Language' => $contraryLanguage],
                )->assertNotFound()->assertExactJson(['message' => $message]);

                $this->assertDatabaseCount('mobile_app_settings', 0);
                $this->assertDatabaseCount('push_provider_settings', 0);
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }

    public function test_missing_runtime_keeps_existing_default_arabic_and_validation_contract(): void
    {
        $this->getJson('/api/v1/mobile/runtime?app=driver&environment=development', [
            'Accept-Language' => 'en-US',
        ])->assertNotFound()->assertExactJson([
            'message' => 'لم يتم إعداد إعدادات تشغيل التطبيق.',
        ]);

        $this->getJson('/api/v1/mobile/runtime?app=driver&environment=staging&locale=fr')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');

        $this->getJson('/api/v1/mobile/runtime?app=invalid&environment=staging&locale=en')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('app');

        $this->getJson('/api/v1/mobile/runtime?app=van&environment=invalid&locale=en')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('environment');

        $this->assertDatabaseCount('mobile_app_settings', 0);
        $this->assertDatabaseCount('push_provider_settings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
