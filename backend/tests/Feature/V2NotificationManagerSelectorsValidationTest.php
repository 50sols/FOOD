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

    public function test_notification_selector_validation_preserves_authorization_and_existing_nested_filter_precedence(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $before = [DB::table('notifications')->count(), DB::table('audit_logs')->count()];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ([
                '?page%5B%5D=2',
                '?page=0',
                '?page=-2',
                '?page=1.5',
                '?page=not-a-number',
                '?page=99999999999999999999999999999',
                '?review_notification=-1',
                '?review_notification=1.5',
                '?review_notification=abc',
                '?review_notification=99999999999999999999999999999',
            ] as $query) {
                $this->actingAs($admin->fresh())->get('/admin/notifications'.$query)
                    ->assertStatus(422);
            }

            $this->actingAs($admin->fresh())
                ->get('/admin/notifications?q%5B%5D=test&page=0')
                ->assertNotFound();
            $this->actingAs($admin->fresh())
                ->get('/admin/notifications?review_notification%5B%5D=1&page=0')
                ->assertNotFound();

            foreach (['', '?page=', '?page=1', '?review_notification=', '?review_notification=0'] as $query) {
                $this->actingAs($admin->fresh())
                    ->get('/admin/notifications'.$query)
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false);
            }

            $this->actingAs($roleless)->get('/admin/notifications?page=0')->assertForbidden();
            $this->actingAs($roleless)->get('/admin/notifications?review_notification=abc')->assertForbidden();
        }

        $this->assertSame($before, [
            DB::table('notifications')->count(),
            DB::table('audit_logs')->count(),
        ]);
        $this->assertSame(0, Notification::query()->count());
    }
}
