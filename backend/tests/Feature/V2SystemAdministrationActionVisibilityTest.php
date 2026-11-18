<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\UpdateHistory;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2SystemAdministrationActionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_system_administrator_can_read_health_without_privileged_update_or_restore_links_in_both_locales(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $actor = $this->userWithRole('SYSTEM_ADMINISTRATOR', $locale);
            $updateUrl = route('admin.system-update.index');
            $restoreUrl = route('admin.production-dataset-switch.index');
            $historyBefore = UpdateHistory::query()->count();

            $this->actingAs($actor)->get(route('admin.enterprise-system-health.index'))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('id="backup-history"', false)
                ->assertSee('id="update-history"', false)
                ->assertSee('id="module-health"', false)
                ->assertDontSee('href="'.$updateUrl.'"', false)
                ->assertDontSee('href="'.$restoreUrl.'"', false);

            $this->actingAs($actor)->get(route('admin.settings.index'))
                ->assertOk()
                ->assertSee('data-setting="system_update"', false)
                ->assertDontSee('class="settings-card__action" href="'.$updateUrl.'"', false);

            $this->actingAs($actor)->get(route('admin.administration.index'))
                ->assertOk()
                ->assertDontSee('data-admin-card="system-updates"', false);

            $this->actingAs($actor)->get($restoreUrl)->assertForbidden();
            $this->assertSame($historyBefore, UpdateHistory::query()->count());
        }
    }

    public function test_active_super_admin_keeps_update_and_recovery_navigation_in_arabic_and_english(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $actor = $this->userWithRole('SUPER_ADMIN', $locale);
            $updateUrl = route('admin.system-update.index');
            $restoreUrl = route('admin.production-dataset-switch.index');

            $this->actingAs($actor)->get(route('admin.enterprise-system-health.index'))
                ->assertOk()
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('href="'.$updateUrl.'"', false)
                ->assertSee('href="'.$restoreUrl.'"', false);

            $this->actingAs($actor)->get(route('admin.settings.index'))
                ->assertOk()
                ->assertSee('class="settings-card__action" href="'.$updateUrl.'"', false);

            $this->actingAs($actor)->get(route('admin.administration.index'))
                ->assertOk()
                ->assertSee('data-admin-card="system-updates"', false);
        }
    }

    public function test_non_platform_persona_cannot_open_system_health_direct_url(): void
    {
        $actor = $this->userWithRole('FINANCE', 'en');

        $this->actingAs($actor)->get(route('admin.enterprise-system-health.index'))
            ->assertForbidden();
    }

    private function userWithRole(string $code, string $locale): User
    {
        $user = User::query()->create([
            'name' => $code,
            'email' => strtolower($code).'-'.$locale.'-visibility@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $user;
    }
}
