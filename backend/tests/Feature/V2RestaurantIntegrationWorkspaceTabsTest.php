<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Enterprise\Modules\ModuleOperationalReadiness;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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

        $shared = (string) file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        // The existing shared PAGE_TAB rail supplies all geometry and keyboard focus.
        $this->assertStringContainsString('class="foodex-tabs foodex-tabs--horizontal ri-workspace-tabs"', $blade);
        $this->assertStringContainsString('.ri-workspace-tabs{padding-block:8px}', $blade);
        $this->assertStringNotContainsString('.ri-workspace-tabs{display:flex;', $blade);
        $this->assertStringContainsString('.foodex-tabs.foodex-tabs--horizontal{min-width:0;max-width:100%;flex-wrap:nowrap;overflow-x:auto;', $shared);
        $this->assertStringContainsString('.foodex-tabs.foodex-tabs--horizontal > a:focus-visible', $shared);
        $this->assertStringContainsString('.foodex-tabs a,.foodex-tab{min-height:var(--foodex-touch-target);', $shared);
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

    public function test_deep_link_tabs_preserve_validated_observability_filters_and_site_without_hidden_actions(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN', 'en');
        $filters = [
            'provider' => 'provider_alpha',
            'direction' => 'outbound',
            'status' => 'retry',
            'webhook_status' => 'pending',
            'event_type' => 'order.created',
            'q' => 'test needle',
            'from' => '2026-11-01',
            'to' => '2026-11-02',
        ];

        $response = $this->actingAs($owner)->get($this->indexUrl($site, ['tab' => 'logs'] + $filters));
        $response->assertOk()
            ->assertSee('id="ri-panel-logs"', false)
            ->assertSee('name="tab" value="logs"', false)
            ->assertSee('name="q" value="test needle"', false)
            ->assertDontSee('name="provider_key"', false)
            ->assertDontSee('name="target_url"', false);

        foreach (['overview', 'logs', 'reports', 'connectors', 'api-clients', 'webhooks'] as $tab) {
            $this->assertSame(1, preg_match(
                '/id="ri-tab-'.preg_quote($tab, '/').'"[^>]*href="([^"]+)"/',
                $response->getContent(),
                $matches,
            ));
            $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(
                parse_url(route('admin.smart-ordering.integrations.index', $site), PHP_URL_PATH),
                parse_url($url, PHP_URL_PATH),
            );
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($tab, $query['tab'] ?? null);
            $this->assertSame('1', $query['support_access'] ?? null);
            foreach ($filters as $key => $value) {
                $this->assertSame($value, $query[$key] ?? null, $tab.' must retain '.$key);
            }
        }

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_malformed_tabs_never_render_hidden_mutations_or_unrelated_credentials(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN', 'en');

        foreach (['not-a-real-panel', ['api-clients'], ['webhooks' => '1'], ''] as $malformed) {
            $this->actingAs($owner)->get($this->indexUrl($site, [
                'tab' => $malformed,
                'status' => 'retry',
                'q' => 'safe search',
            ]))->assertOk()
                ->assertSee('id="ri-panel-overview"', false)
                ->assertDontSee('name="_integration_form"', false)
                ->assertDontSee('name="provider_key"', false)
                ->assertDontSee('name="target_url"', false)
                ->assertDontSee('id="ri-panel-api-clients"', false)
                ->assertDontSee('id="ri-panel-webhooks"', false);
        }

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_foreign_store_post_actions_are_denied_even_with_an_authorized_tab_parameter(): void
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

        $this->actingAs($retail)->from($this->indexUrl($siteB, ['tab' => 'api-clients']))
            ->post(route('admin.smart-ordering.integrations.api-clients.store', $siteB), [
                '_integration_form' => 'api-clients',
                'name' => 'Foreign Client',
                'scopes' => ['restaurant.read'],
            ])->assertNotFound();

        $this->actingAs($retail)->from($this->indexUrl($siteB, ['tab' => 'webhooks']))
            ->post(route('admin.smart-ordering.integrations.webhooks.store', $siteB), [
                '_integration_form' => 'webhooks',
                'target_url' => 'https://example.test/hook',
                'event_types' => ['order.created'],
            ])->assertNotFound();

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

    public function test_one_time_credentials_never_leak_to_a_view_only_operator_after_manage_deny(): void
    {
        $site = $this->restaurantSite();
        $viewer = $this->roleUser('B2C_STORE_ADMIN');
        DB::table('user_store_roles')->insert([
            'user_id' => $viewer->id,
            'store_id' => $site->store_id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $viewer->id,
            'permission_id' => Permission::query()->where('code', 'smart_ordering.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $viewer->forceFill(['has_permission_overrides' => true])->save();

        $this->assertTrue($viewer->fresh()->hasPermission('smart_ordering.view', (int) $site->store_id));
        $this->assertFalse($viewer->fresh()->hasPermission('smart_ordering.manage', (int) $site->store_id));

        foreach (['api-clients', 'webhooks', 'not-a-tab', ['api-clients']] as $tab) {
            $this->actingAs($viewer->fresh())->withSession([
                'restaurant_api_credential' => 'EXAMPLE-TOKEN-MUST-NOT-LEAK',
                'restaurant_webhook_secret' => 'EXAMPLE-WEBHOOK-MUST-NOT-LEAK',
            ])->get(route('admin.smart-ordering.integrations.index', [
                'site' => $site->getKey(),
                'tab' => $tab,
            ]))->assertOk()
                ->assertDontSee('EXAMPLE-TOKEN-MUST-NOT-LEAK')
                ->assertDontSee('EXAMPLE-WEBHOOK-MUST-NOT-LEAK')
                ->assertDontSee('name="_integration_form"', false);
        }

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
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
        ]))->assertNotFound();

        // Tenant/store admission intentionally conceals inaccessible sites as 404.
        $wholesale = $this->roleUser('B2B_ADMIN');
        $this->actingAs($wholesale)->get(route('admin.smart-ordering.integrations.index', [
            'site' => $siteA->getKey(), 'tab' => 'webhooks',
        ]))->assertNotFound();

        $this->actingAs($wholesale)->post(route('admin.smart-ordering.integrations.webhooks.store', $siteA), [
            '_integration_form' => 'webhooks',
            'target_url' => 'https://example.test/forged-b2b',
            'event_types' => ['order.created'],
        ])->assertNotFound();
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_store_manager_positive_path_is_scoped_and_remains_localized_for_every_tab(): void
    {
        $site = $this->restaurantSite();
        $manager = $this->roleUser('B2C_STORE_ADMIN');
        DB::table('user_store_roles')->insert([
            'user_id' => $manager->id,
            'store_id' => $site->store_id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($manager->fresh()->hasPermission('smart_ordering.view', (int) $site->store_id));
        $this->assertTrue($manager->fresh()->hasPermission('smart_ordering.manage', (int) $site->store_id));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            foreach (['overview', 'logs', 'reports', 'connectors', 'api-clients', 'webhooks'] as $tab) {
                $this->actingAs($manager->fresh())->get(route('admin.smart-ordering.integrations.index', [
                    'site' => $site->getKey(),
                    'tab' => $tab,
                ]))->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('id="ri-panel-'.$tab.'"', false);
            }
        }

        $back = route('admin.smart-ordering.integrations.index', [
            'site' => $site->getKey(), 'tab' => 'api-clients',
        ]);
        $this->actingAs($manager->fresh())->from($back)->post(
            route('admin.smart-ordering.integrations.api-clients.store', $site),
            [
                '_integration_form' => 'api-clients',
                'name' => 'Authorized Store API Client',
                'scopes' => ['restaurant.read'],
            ],
        )->assertRedirect($back)->assertSessionHas('restaurant_api_credential');

        $this->assertDatabaseCount('restaurant_saas_api_clients', 1);
        $this->assertDatabaseHas('restaurant_saas_api_clients', [
            'site_id' => $site->getKey(),
            'name' => 'Authorized Store API Client',
        ]);
    }

    public function test_disabled_lifecycle_and_explicit_store_entitlement_deny_block_get_and_post(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN');
        $indexUrl = $this->indexUrl($site, ['tab' => 'api-clients']);
        $postUrl = route('admin.smart-ordering.integrations.api-clients.store', $site);
        $input = [
            'support_access' => 1,
            '_integration_form' => 'api-clients',
            'name' => 'Denied API Client',
            'scopes' => ['restaurant.read'],
        ];

        $this->actingAs($owner)->get($indexUrl)->assertOk();

        DB::table('enterprise_modules')
            ->where('code', 'smart_ordering')
            ->update(['state' => ModuleState::DISABLED->value]);

        $this->actingAs($owner)->get($indexUrl)->assertNotFound();
        $this->actingAs($owner)->post($postUrl, $input)->assertNotFound();

        DB::table('enterprise_modules')
            ->where('code', 'smart_ordering')
            ->update(['state' => ModuleState::ENABLED->value]);

        DB::table('enterprise_module_entitlements')
            ->where('module_code', 'smart_ordering')
            ->where('scope_type', 'store')
            ->where('scope_id', $site->store_id)
            ->update(['is_enabled' => false]);

        $this->actingAs($owner)->get($indexUrl)->assertNotFound();
        $this->actingAs($owner)->post($postUrl, $input)->assertNotFound();

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
    }

    public function test_view_only_permission_deny_and_inactive_actor_reject_real_mutation_routes(): void
    {
        $site = $this->restaurantSite();
        $operator = $this->roleUser('B2C_STORE_ADMIN', 'en');
        DB::table('user_store_roles')->insert([
            'user_id' => $operator->id,
            'store_id' => $site->store_id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $operator->id,
            'permission_id' => Permission::query()->where('code', 'smart_ordering.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operator->forceFill(['has_permission_overrides' => true])->save();

        foreach (['connectors', 'api-clients', 'webhooks', 'logs', 'reports'] as $tab) {
            $this->actingAs($operator->fresh())->get(route('admin.smart-ordering.integrations.index', [
                'site' => $site->getKey(), 'tab' => $tab,
            ]))->assertOk()->assertDontSee('name="_integration_form"', false);
        }

        // Explicit manage DENY is enforced before exposing write-side site resources.
        $this->actingAs($operator->fresh())->post(route('admin.smart-ordering.integrations.api-clients.store', $site), [
            '_integration_form' => 'api-clients',
            'name' => 'Forged Role',
            'scopes' => ['restaurant.read'],
        ])->assertNotFound();

        $this->actingAs($operator->fresh())->post(route('admin.smart-ordering.integrations.webhooks.store', $site), [
            '_integration_form' => 'webhooks',
            'target_url' => 'https://example.test/callback',
            'event_types' => ['order.created'],
        ])->assertNotFound();

        $operator->forceFill(['is_active' => false])->save();

        $this->actingAs($operator->fresh())->get(route('admin.smart-ordering.integrations.index', [
            'site' => $site->getKey(), 'tab' => 'logs',
        ]))->assertForbidden();

        $this->actingAs($operator->fresh())->post(route('admin.smart-ordering.integrations.api-clients.store', $site), [
            '_integration_form' => 'api-clients',
            'name' => 'Inactive Role',
            'scopes' => ['restaurant.read'],
        ])->assertForbidden();

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_csrf_protected_web_routes_have_no_get_mutation_method_and_render_tokens(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN');

        foreach ([
            'admin.smart-ordering.integrations.api-clients.store',
            'admin.smart-ordering.integrations.webhooks.store',
            'admin.smart-ordering.integrations.install',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('POST', $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('management.dashboard', $route->gatherMiddleware());

            // The install POST shares its URI with the GET integration index.
            // This is normal REST routing, not permission to run an install via GET.
            if ($name !== 'admin.smart-ordering.integrations.install') {
                $this->actingAs($owner)->get(route($name, $site))->assertStatus(405);
            }
        }

        foreach (['connectors', 'api-clients', 'webhooks'] as $tab) {
            $this->actingAs($owner)->get($this->indexUrl($site, ['tab' => $tab]))
                ->assertOk()
                ->assertSee('name="_token"', false);
        }

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
        $this->assertDatabaseCount('restaurant_saas_webhook_subscriptions', 0);
    }

    public function test_invalid_csrf_token_is_rejected_when_real_web_csrf_middleware_runs(): void
    {
        $site = $this->restaurantSite();
        $owner = $this->roleUser('SUPER_ADMIN');
        $this->actingAs($owner)->withSession(['_token' => 'csrf-valid-session-token']);

        // Laravel deliberately skips CSRF verification in the PHPUnit
        // 'testing' environment. Change only the app environment inside this
        // request to exercise the exact production web middleware; restore it
        // unconditionally, including when the assertion fails.
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->post(route('admin.smart-ordering.integrations.api-clients.store', $site), [
                '_token' => 'csrf-forged-token',
                'support_access' => 1,
                '_integration_form' => 'api-clients',
                'name' => 'Forged CSRF Client',
                'scopes' => ['restaurant.read'],
            ])->assertStatus(419);

            $this->post(route('admin.smart-ordering.integrations.webhooks.store', $site), [
                'support_access' => 1,
                '_integration_form' => 'webhooks',
                'target_url' => 'https://example.test/unauthorized-csrf',
                'event_types' => ['order.created'],
            ])->assertStatus(419);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseCount('restaurant_saas_api_clients', 0);
    }

    public function test_uncertified_status_cannot_be_mistaken_for_enterprise_module_readiness(): void
    {
        $site = $this->restaurantSite();
        $registry = app(ModuleRegistry::class);
        $manifestVersion = $registry->manifest('smart_ordering')->version;

        $this->assertSame(ModuleState::ENABLED, $registry->state('smart_ordering'));

        config()->set('enterprise_module_readiness.certified_versions.smart_ordering', null);

        $this->assertSame(
            'uncertified',
            app(ModuleOperationalReadiness::class)->status(
                'smart_ordering',
                $manifestVersion,
                $registry->state('smart_ordering'),
            ),
        );

        // The existing ERP readiness middleware protects only ERP route names.
        // Restaurant Integrations still uses its own server-side module
        // lifecycle + store entitlement + permission contract; do not fake
        // certification or widen the unrelated ERP middleware in this issue.
        $this->assertSame('admin.smart-ordering.integrations.index', Route::getRoutes()
            ->getByName('admin.smart-ordering.integrations.index')->getName());
        $this->assertDatabaseHas('enterprise_module_entitlements', [
            'module_code' => 'smart_ordering',
            'scope_id' => $site->store_id,
            'is_enabled' => true,
        ]);
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
