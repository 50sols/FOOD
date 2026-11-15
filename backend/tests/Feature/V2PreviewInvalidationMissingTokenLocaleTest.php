<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2PreviewInvalidationMissingTokenLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_preview_event_feed_rejects_missing_or_whitespace_token_in_selected_language_without_writes(): void
    {
        $before = [];
        foreach (['app_preview_sessions', 'app_preview_invalidation_events', 'audit_logs', 'notifications'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach ([
            ['ar-KW,ar;q=0.9,en;q=0.5', 'en', 'يلزم توفير رمز معاينة صالح للوصول إلى المعاينة.'],
            ['en-US,en;q=0.9,ar;q=0.5', 'ar', 'Preview token is required.'],
            ['fr-FR,fr;q=0.9', 'en', 'Preview token is required.'],
            [null, 'en', 'Preview token is required.'],
        ] as [$acceptLanguage, $contraryAppLocale, $expectedMessage]) {
            app()->setLocale($contraryAppLocale);

            foreach ([null, '   ', " \t "] as $token) {
                $headers = [];
                if ($acceptLanguage !== null) {
                    $headers['Accept-Language'] = $acceptLanguage;
                }
                if ($token !== null) {
                    $headers['X-Foodex-Preview-Token'] = $token;
                }

                $this->getJson('/api/v1/app-preview/events', $headers)
                    ->assertUnauthorized()
                    ->assertJsonPath('message', $expectedMessage)
                    ->assertJsonMissingPath('data');

                foreach ($before as $table => $count) {
                    $this->assertSame($count, DB::table($table)->count(), $table.' must not change');
                }
            }
        }
    }
}
