<?php

namespace Tests\Feature;

use App\Models\AppTranslation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminManagementEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_uses_administration_hub_and_management_centers_keep_cross_links(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $user = User::query()->create([
            'name' => 'Admin Control Center',
            'email' => 'admin-control-center@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $this->actingAs($user)
            ->get('/admin/b2b/dashboard')
            ->assertOk()
            ->assertSee(route('admin.administration.index'));

        $this->actingAs($user)
            ->get('/admin/settings/translations')
            ->assertOk()
            ->assertSee(route('admin.notifications.index'))
            ->assertSee(__('notifications.title'));

        $this->actingAs($user)
            ->get('/admin/notifications')
            ->assertOk()
            ->assertSee(route('admin.translations.index'))
            ->assertSee(__('admin.translations.title'));
    }

    public function test_translation_pages_validate_selectors_before_sync_in_both_locales(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::query()->create([
            'name' => 'Translation paging administrator',
            'email' => 'translation-page-admin@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $roleless = User::query()->create([
            'name' => 'Translation paging denied user',
            'email' => 'translation-page-denied@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $url = route('admin.translations.index');
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $translationCount = AppTranslation::query()->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '?page[]=2',
                    '?page=abc',
                    '?page=0',
                    '?page=-2',
                    '?page=1.5',
                    '?page=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($translationCount, AppTranslation::query()->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                $this->actingAs($roleless)->getJson($url.'?page=invalid')
                    ->assertForbidden();
                $this->assertSame($translationCount, AppTranslation::query()->count());

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
