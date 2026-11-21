<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2PreviewResolveMissingTokenLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_public_preview_resolve_without_token_returns_localized_401_and_does_not_write(): void
    {
        $sessionCount = DB::table('app_preview_sessions')->count();
        $auditCount = DB::table('audit_logs')->count();
        $notificationCount = DB::table('notifications')->count();

        foreach ([
            'ar-KW,ar;q=0.9,en;q=0.5' => 'يلزم توفير رمز معاينة صالح للوصول إلى المعاينة.',
            'en-US,en;q=0.9,ar;q=0.5' => 'Preview token is required.',
        ] as $language => $expected) {
            $this->postJson('/api/v1/app-preview/resolve', [], [
                'Accept-Language' => $language,
            ])->assertUnauthorized()
                ->assertJsonPath('message', $expected)
                ->assertJsonMissingPath('data');

            // A non-string payload must still be rejected before the token
            // resolver; this does not relax the existing type guard.
            $this->postJson('/api/v1/app-preview/resolve', [
                'preview_token' => 123,
            ], [
                'Accept-Language' => $language,
            ])->assertUnauthorized()
                ->assertJsonPath('message', $expected);

            $this->assertSame($sessionCount, DB::table('app_preview_sessions')->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertSame($notificationCount, DB::table('notifications')->count());
        }
    }
}
