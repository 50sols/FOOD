<?php

namespace Tests\Feature;

use App\Models\LiveAd;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2LiveAdsPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_ads_reject_malformed_page_after_authorization_in_both_locales(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $url = route('admin.live-ads.index');
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $adsCount = LiveAd::query()->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '?page[]=2',
                    '?page=invalid',
                    '?page=0',
                    '?page=-1',
                    '?page=1.5',
                    '?page=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($adsCount, LiveAd::query()->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                // The existing nested-filter 404 takes precedence over page validation.
                $this->actingAs($admin->fresh())->getJson($url.'?q[]=bad&page=invalid')
                    ->assertNotFound();

                // Authorization precedes both parsing and page validation.
                $this->actingAs($roleless)->getJson($url.'?page=invalid')
                    ->assertForbidden();

                foreach (['', '?page=1', '?page=2', '?page='] as $valid) {
                    $this->actingAs($admin->fresh())->get($url.$valid)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
