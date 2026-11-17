<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V2EnterpriseSecurityHorizontalTabRailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_security_page_reuses_single_row_native_links_for_both_locales(): void
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach($role->id);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $admin->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($admin)
                ->get(route('admin.enterprise-security.index'))
                ->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringContainsString('dir="'.$direction.'"', $html);
            $this->assertSame(1, preg_match(
                '/<nav class="es-tabs foodex-tabs foodex-tabs--horizontal"[^>]*>(.*?)<\/nav>/s',
                $html,
                $matches,
            ));

            $rail = $matches[1];
            $this->assertSame(2, substr_count($rail, '<a '));
            $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
            $this->assertStringContainsString(
                'href="'.route('admin.security.index').'"',
                $rail,
            );
            $this->assertStringContainsString(
                'class="es-link active" aria-current="page" href="'.route('admin.enterprise-security.index').'"',
                $rail,
            );
            $this->assertStringNotContainsString('role="tablist"', $matches[0]);
            $this->assertStringNotContainsString('role="tab"', $matches[0]);
            $this->assertStringNotContainsString('aria-selected', $matches[0]);

            // The existing FOODEX component owns nowrap/contained scroll and focus.
            // No page-local tab JS/CSS primitive or duplicated rail is needed.
            $this->assertStringContainsString(
                '.foodex-tabs.foodex-tabs--horizontal{min-width:0;max-width:100%;flex-wrap:nowrap;overflow-x:auto;',
                $html,
            );
            $this->assertStringContainsString(
                '.es-back,.es-link{display:inline-flex;min-height:var(--foodex-touch-target);',
                $html,
            );
            $this->assertStringContainsString(
                '.foodex-tabs.foodex-tabs--horizontal > a:focus-visible',
                $html,
            );

            // Existing privileged maker/checker forms remain server-rendered
            // for the authorized actor; navigation is not a permission gate.
            $this->assertStringContainsString('data-enterprise-security-scope-form', $html);
            $this->assertStringContainsString('data-enterprise-security-limit-form', $html);
            $this->assertStringContainsString('data-enterprise-security-delegation-form', $html);
        }
    }

    public function test_unauthorized_actor_cannot_read_enterprise_security_in_either_locale(): void
    {
        $unauthorized = User::factory()->create([
            'is_active' => true,
            'locale' => 'en',
        ]);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $unauthorized->forceFill(['locale' => $locale])->save();

            $this->actingAs($unauthorized)
                ->get(route('admin.enterprise-security.index'))
                ->assertForbidden();
        }
    }
}
