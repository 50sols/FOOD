<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2RestaurantIntegrationWorkspaceTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sibling_sections_render_one_server_selected_panel_without_duplicate_create_forms_in_ar_and_en(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN');
        $tabs = ['overview', 'logs', 'reports', 'connectors', 'api-clients', 'webhooks'];

        foreach (['ar', 'en'] as $locale) {
            $owner->forceFill(['locale' => $locale])->save();
            foreach ($tabs as $tab) {
                $response = $this->actingAs($owner->fresh())->get($this->indexUrl($site, ['tab' => $tab]));
                $response->assertOk()
                    ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee('data-integration-workspace-tabs', false)
                    ->assertSee('id="ri-panel-'.$tab.'"', false)
                    ->assertSee('data-ri-active-panel', false)
                    ->assertSee('id="ri-tab-'.$tab.'" class="foodex-tab active"', false)
                    ->assertSee('role="region" aria-labelledby="ri-tab-'.$tab.'"', false)
                    ->assertDontSee('class="ri-tab-panel" role="tabpanel"', false);

                foreach (array_diff($tabs, [$tab]) as $other) {
                    $response->assertDontSee('id="ri-panel-'.$other.'"', false);
                }

                // A single creation form per selected sibling, with no hidden POST duplicates.
                $expectedForm = match ($tab) {
                    'connectors' => 'name="provider_key"',
                    'api-clients' => 'name="name"',
                    'webhooks' => 'name="target_url"',
                    default => null,
                };
                if ($expectedForm !== null) {
                    $this->assertSame(1, substr_count($response->getContent(), $expectedForm));
                }
            }
        }
    }

    public function test_native_link_navigation_has_scrollable_single_row_focus_style_and_no_false_tab_widget_roles(): void
    {
        $blade = (string) file_get_contents(resource_path('views/admin/smart-ordering-integrations.blade.php'));

        $this->assertStringContainsString('.ri-workspace-tabs{display:flex;flex-wrap:nowrap;', $blade);
        $this->assertStringContainsString('overflow-x:auto;', $blade);
        $this->assertStringContainsString('min-width:0;padding-block:8px;', $blade);
        $this->assertStringContainsString('.ri-workspace-tabs .foodex-tab:focus-visible{outline:', $blade);
        $this->assertStringContainsString('aria-current="page"', $blade);
        $this->assertStringNotContainsString('role="tablist"', $blade);
        $this->assertStringNotContainsString('role="tab"', $blade);
        $this->assertStringNotContainsString('aria-selected=', $blade);
        $this->assertStringContainsString('class="ri-tab-panel" role="region"', $blade);
    }

    public function test_malformed_tab_falls_back_without_privilege_change_and_log_filters_keep_context(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN', 'en');

        foreach (['not-a-tab', ['logs']] as $input) {
            $this->actingAs($owner)->get($this->indexUrl($site, ['tab' => $input]))
                ->assertOk()
                ->assertSee('id="ri-panel-overview"', false)
                ->assertDontSee('id="ri-panel-logs"', false);
        }

        $this->actingAs($owner)->get($this->indexUrl($site, [
            'tab' => 'logs', 'q' => 'event_hello', 'status' => 'retry',
        ]))->assertOk()
            ->assertSee('id="ri-panel-logs"', false)
            ->assertSee('name="tab" value="logs"', false)
            ->assertSee('name="q" value="event_hello"', false)
            ->assertSee('status=retry')
            ->assertSee('support_access');

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_failed_create_returns_to_exact_form_tab_restores_old_inputs_and_error_focus(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN');
        $back = $this->indexUrl($site, ['tab' => 'logs']);

        $this->actingAs($owner)->from($back)->post(route('admin.smart-ordering.integrations.api-clients.store', $site), [
            'support_access' => 1,
            '_integration_form' => 'api-clients',
            'name' => 'Original client label',
            'scopes' => [],
        ])->assertRedirect($back)->assertSessionHasErrors(['scopes']);

        $this->actingAs($owner)->get($back)->assertOk()
            ->assertSee('id="ri-panel-api-clients"', false)
            ->assertDontSee('id="ri-panel-logs"', false)
            ->assertSee('value="Original client label"', false)
            ->assertSee('id="ri-validation-error"', false)
            ->assertSee('aria-invalid="true"', false);
        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);

        $this->actingAs($owner)->from($back)->post(route('admin.smart-ordering.integrations.install', $site), [
            'support_access' => 1,
            '_integration_form' => 'connectors',
            'connector_type' => 'generic',
            'provider_key' => 'invalid provider',
            'display_name' => 'Preserved Connector',
            'capabilities' => ['orders.ingest'],
        ])->assertRedirect($back)->assertSessionHasErrors(['provider_key']);

        $this->actingAs($owner)->get($back)->assertOk()
            ->assertSee('id="ri-panel-connectors"', false)
            ->assertSee('value="Preserved Connector"', false)
            ->assertSee('name="provider_key" value="invalid provider"', false)
            ->assertSee('aria-invalid="true"', false);

        $this->actingAs($owner)->from($back)->post(route('admin.smart-ordering.integrations.webhooks.store', $site), [
            'support_access' => 1,
            '_integration_form' => 'webhooks',
            'target_url' => 'not a valid URL',
            'event_types' => [],
        ])->assertRedirect($back)->assertSessionHasErrors(['target_url', 'event_types']);

        $this->actingAs($owner)->get($back)->assertOk()
            ->assertSee('id="ri-panel-webhooks"', false)
            ->assertSee('value="not a valid URL"', false)
            ->assertSee('id="ri-validation-error"', false);

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_create_client_preserves_back_target_and_shows_one_time_secret_in_correct_panel(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN');
        $back = $this->indexUrl($site, ['tab' => 'api-clients']);

        $this->actingAs($owner)->from($back)->post(route('admin.smart-ordering.integrations.api-clients.store', $site), [
            'support_access' => 1,
            '_integration_form' => 'api-clients',
            'name' => 'Safe Partner',
            'scopes' => ['restaurant.read'],
        ])->assertRedirect($back)->assertSessionHas('restaurant_api_credential');

        $this->actingAs($owner)->get($back)->assertOk()
            ->assertSee('id="ri-panel-api-clients"', false)
            ->assertSee('Safe Partner')
            ->assertSee('One-time API credential');
        $this->assertDatabaseCount('restaurant_saas_api_clients', 1);
    }

    public function test_cross_store_and_wholesale_roles_cannot_bypass_site_authority_using_tab_query(): void
    {
        $siteA = $this->restaurantSite();
        $siteB = $this->restaurantSite();
        $retail = $this->roleUser('B2C_STORE_ADMIN');
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $siteA->store_id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($retail)->get(route('admin.smart-ordering.integrations.index', [
            'site' => $siteB->getKey(), 'tab' => 'api-clients',
        ]))->assertForbidden();

        $wholesale = $this->roleUser('B2B_ADMIN');
        $this->actingAs($wholesale)->get(route('admin.smart-ordering.integrations.index', [
            'site' => $siteA->getKey(), 'tab' => 'webhooks',
        ]))->assertForbidden();
    }

    private function restaurantSite(): SmartOrderingSite
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'TAB-'.Str::upper(Str::random(8)),
            'name' => 'Tab Fixture Restaurant',
            'is_active' => true,
        ]);

        $this->enableRestaurantSaasForTestStore((int) $store->id);

        return SmartOrderingSite::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $store->id,
            'slug' => 'tabs-'.Str::lower(Str::random(8)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
        ]);
    }

    private function roleUser(string $roleCode, string $locale = 'en'): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => strtolower($roleCode).'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'is_active' => true,
            'locale' => $locale,
        ]);

        if ($roleCode !== 'B2C_STORE_ADMIN') {
            $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());
        }

        return $user;
    }

    private function indexUrl(SmartOrderingSite $site, array $query = []): string
    {
        return route('admin.smart-ordering.integrations.index', ['site' => $site->getKey(), 'support_access' => 1] + $query);
    }
}
