<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppModuleState;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2WhatsAppCampaignWorkspaceTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        $this->enableCampaigns();
    }

    public function test_existing_campaign_list_template_registry_and_operations_are_one_scoped_panel_in_each_locale(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $tabs = ['campaigns', 'templates', 'overview'];
        $counts = $this->businessCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach ($tabs as $selected) {
                $response = $this->actingAs($actor->fresh())->get(route('admin.whatsapp-campaigns.index', [
                    'q' => 'retained-filter',
                    'workspace_tab' => $selected,
                ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

                $html = $response->getContent();
                $this->assertSame(3, preg_match_all('/id="wa-workspace-panel-(campaigns|templates|overview)"/', $html));
                $this->assertSame(3, preg_match_all('/data-wa-workspace-tab="(campaigns|templates|overview)"/', $html));
                $this->assertStringContainsString('id="wa-campaign-form"', $html);
                $this->assertSame($selected === 'templates' ? 1 : 0, substr_count($html, 'id="wa-template-sync-form"'));

                foreach ($tabs as $tab) {
                    $this->assertSame(1, preg_match('/<div class="wa-workspace-panel" id="wa-workspace-panel-'.preg_quote($tab, '/').'"[^>]*>/', $html, $panelMatch));
                    $this->assertSame($tab !== $selected, str_contains($panelMatch[0], ' hidden'), $tab.' should have correct visibility');
                    // Other tabs exist only as empty ARIA placeholders, not data/forms.
                    $this->assertSame($tab === $selected ? 1 : 0, substr_count(
                        $html,
                        'id="'.(['campaigns' => 'wa-campaign-list', 'templates' => 'wa-template-registry', 'overview' => 'wa-operations-title'][$tab]).'"'
                    ));

                    $this->assertSame(1, preg_match('/<a id="wa-workspace-tab-'.preg_quote($tab, '/').'"[^>]*>/', $html, $tabMatch));
                    $this->assertStringContainsString('role="tab"', $tabMatch[0]);
                    $this->assertStringContainsString('aria-selected="'.($tab === $selected ? 'true' : 'false').'"', $tabMatch[0]);
                    $this->assertStringContainsString('tabindex="'.($tab === $selected ? '0' : '-1').'"', $tabMatch[0]);

                    $expected = route('admin.whatsapp-campaigns.index', [
                        'q' => 'retained-filter',
                        'workspace_tab' => $tab,
                    ]);
                    $response->assertSee(e($expected), false);
                }
            }
        }

        $this->assertSame($counts, $this->businessCounts());
    }

    public function test_invalid_array_unknown_or_missing_workspace_tab_fails_safely_to_campaign_list_without_changing_data(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $base = route('admin.whatsapp-campaigns.index', [], false);
        $before = $this->businessCounts();

        foreach ([
            $base,
            $base.'?workspace_tab=not-a-workspace',
            $base.'?workspace_tab%5B%5D=templates',
            $base.'?workspace_tab%5Bfoo%5D=overview',
        ] as $url) {
            $this->actingAs($actor)->get($url)->assertOk()
                ->assertSee('id="wa-workspace-panel-campaigns"', false)
                ->assertSee('data-wa-workspace-panel="campaigns"', false);
        }

        $this->assertSame($before, $this->businessCounts());
    }

    public function test_tabs_remain_a_view_only_choice_and_never_change_existing_draft_send_or_consent_forms(): void
    {
        $actor = $this->actor('SUPER_ADMIN');
        $response = $this->actingAs($actor)->get(route('admin.whatsapp-campaigns.index', [
            'workspace_tab' => 'templates',
        ]))->assertOk();

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'id="wa-campaign-form"'));
        $this->assertSame(1, substr_count($html, 'name="launch_action"'));
        $this->assertStringContainsString('value="draft"', $html);
        $this->assertStringContainsString('id="wa-audience-source"', $html);
        $this->assertStringContainsString('id="wa-selected-customer-inputs"', $html);
        $this->assertStringContainsString("url.searchParams.set('workspace_tab', key)", $html);
        $this->assertStringContainsString("window.history.pushState({waWorkspaceTab: key}", $html);
        $this->assertStringContainsString("window.addEventListener('popstate'", $html);
        $this->assertStringContainsString("event.key === ' '", $html);
        $this->assertStringContainsString("panel.replaceChildren()", $html);
        $this->assertStringContainsString('event.preventDefault();', $html);
        $this->assertStringContainsString('window.addEventListener(\'hashchange\', fromFragment)', $html);
        $this->assertStringContainsString('id="wa-template-registry"', $html);
        $this->assertStringNotContainsString('id="wa-campaign-list"', $html);
        $this->assertStringNotContainsString('id="wa-operations-title"', $html);
    }

    public function test_page_tabs_do_not_bypass_roleless_inactive_actor_or_disabled_whatsapp_capability(): void
    {
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($roleless)
            ->get(route('admin.whatsapp-campaigns.index', ['workspace_tab' => 'templates']))
            ->assertForbidden();

        $inactive = $this->actor('SUPER_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();
        // Existing campaign authorization rejects inactive accounts with HTTP 403.
        $this->actingAs($inactive->fresh())
            ->get(route('admin.whatsapp-campaigns.index', ['workspace_tab' => 'overview']))
            ->assertForbidden();

        $allowed = $this->actor('SUPER_ADMIN');
        WhatsAppModuleState::query()->findOrFail(1)->forceFill(['emergency_stop' => true])->save();
        $this->actingAs($allowed)
            ->get(route('admin.whatsapp-campaigns.index', ['workspace_tab' => 'campaigns']))
            ->assertNotFound();
    }

    private function actor(string $roleCode): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor;
    }

    private function enableCampaigns(): void
    {
        WhatsAppModuleState::query()->findOrFail(1)->forceFill([
            'lifecycle' => 'INTERNAL_TEST',
            'emergency_stop' => false,
            'capabilities' => [
                'outbound' => true,
                'campaigns' => true,
                'inbound' => true,
                'agent_inbox' => true,
            ],
            'test_numbers' => [],
        ])->save();
    }

    /** @return array<string, int> */
    private function businessCounts(): array
    {
        return [
            'campaigns' => DB::table('whats_app_campaigns')->count(),
            'campaign_recipients' => DB::table('whats_app_campaign_recipients')->count(),
            'templates' => DB::table('whats_app_templates')->count(),
            'consents' => DB::table('whats_app_marketing_consents')->count(),
            'messages' => DB::table('whats_app_messages')->count(),
        ];
    }
}
