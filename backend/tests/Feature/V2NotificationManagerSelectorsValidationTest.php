<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2NotificationManagerSelectorsValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_list_and_review_selectors_fail_closed_in_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $url = route('admin.notifications.index');
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $notificationsCount = Notification::query()->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '?page[]=2',
                    '?page=invalid',
                    '?page=0',
                    '?page=-1',
                    '?page=1.5',
                    '?page=999999999999999999999999',
                    '?review_notification=invalid',
                    '?review_notification=-1',
                    '?review_notification=1.5',
                    '?review_notification=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($notificationsCount, Notification::query()->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                $this->actingAs($admin->fresh())->getJson($url.'?review_notification[]=1&page=invalid')
                    ->assertNotFound();
                $this->actingAs($admin->fresh())->getJson($url.'?q[]=bad&page=invalid')
                    ->assertNotFound();

                $this->actingAs($roleless)->getJson($url.'?page=invalid')
                    ->assertForbidden();

                foreach ([
                    '',
                    '?page=1',
                    '?page=2',
                    '?page=',
                    '?review_notification=',
                    '?review_notification=0',
                ] as $valid) {
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
