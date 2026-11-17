<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2EnterpriseBiIndependentPageTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.enterprise_bi',
            app(ModuleRegistry::class)->manifest('enterprise_bi')->version,
        );
    }

    public function test_each_bi_workspace_renders_only_selected_authorized_panel_and_preserves_filters_ar_en(): void
    {
        [$actor, $store] = $this->authorizedStoreActor();
        $from = now()->subDays(3)->toDateString();
        $to = now()->toDateString();
        $panels = ['overview', 'reports', 'exports', 'schedules'];
        $before = $this->businessCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ($panels as $selected) {
                $params = [
                    'bi_tab' => $selected,
                    'report' => 'orders.summary',
                    'scope' => 'store:'.$store->id,
                    'from' => $from,
                    'to' => $to,
                    'dashboard_from' => $from,
                    'dashboard_to' => $to,
                ];

                $response = $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.bi', $params))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('data-bi-workspace-tabs', false)
                    ->assertSee('data-bi-workspace-panel="'.$selected.'"', false);

                $html = $response->getContent();
                $this->assertSame(1, preg_match_all('/data-bi-workspace-panel="(overview|reports|exports|schedules)"/', $html));
                $this->assertSame(4, preg_match_all('/data-bi-workspace-link="(overview|reports|exports|schedules)"/', $html));

                foreach ($panels as $other) {
                    if ($selected !== $other) {
                        $this->assertStringNotContainsString('data-bi-workspace-panel="'.$other.'"', $html);
                    }
                    $linkParams = $params;
                    unset($linkParams['bi_tab']);
                    $linkParams['bi_tab'] = $other;
                    $response->assertSee(e(route('admin.erp.bi', $linkParams)), false);
                }

                if ($selected !== 'overview') {
                    // The shared script contains this selector on every tab.
                    // Only the active Overview workspace may render the node.
                    $this->assertSame(0, preg_match('/<[^>]+\\bdata-enterprise-bi-overview(?:\\s|=|>)/i', $html));
                }

                if ($selected === 'overview') {
                    $response->assertSee('data-enterprise-bi-overview', false);
                    // The shared JS mentions the selector on every tab; assert actual form presence instead.
                    $this->assertSame(0, preg_match('/<form\\b[^>]*\\bdata-bi-run-form\\b/i', $html));
                    $this->assertStringContainsString("tab.name = 'bi_tab'", $html);
                } elseif ($selected === 'reports') {
                    $response->assertSee('data-bi-run-form', false)
                        ->assertSee('name="bi_tab" value="reports"', false);
                    $this->assertSame(1, preg_match_all('/<form\\b[^>]*\\bdata-bi-run-form\\b/i', $html));

                } elseif ($selected === 'schedules') {
                    $response->assertSee(__('enterprise_bi_ui.schedules_title'));
                    $this->assertSame(0, preg_match('/<form\\b[^>]*\\bdata-bi-run-form\\b/i', $html));
                }
            }
        }

        $this->assertSame($before, $this->businessCounts());
    }

    public function test_missing_and_malformed_bi_tab_fall_back_safely_without_enabling_expensive_sibling_panels(): void
    {
        [$actor, $store] = $this->authorizedStoreActor();
        $root = route('admin.erp.bi', [], false);

        foreach ([
            $root,
            $root.'?bi_tab=not-a-panel',
            $root.'?bi_tab%5B%5D=reports',
            $root.'?bi_tab%5Bbad%5D=exports',
        ] as $url) {
            $this->actingAs($actor)
                ->get($url)
                ->assertOk()
                ->assertSee('data-bi-workspace-panel="overview"', false)
                ->assertDontSee('data-bi-workspace-panel="reports"', false)
                ->assertDontSee('data-bi-workspace-panel="exports"', false)
                ->assertDontSee('data-bi-workspace-panel="schedules"', false);
        }

        $this->actingAs($actor)->get(route('admin.erp.bi', [
            'report' => 'orders.summary',
            'scope' => 'store:'.$store->id,
        ]))->assertOk()
            ->assertSee('data-bi-workspace-panel="reports"', false)
            ->assertDontSee('data-bi-workspace-panel="overview"', false);
    }

    public function test_tab_selection_never_bypasses_permissions_or_uncertified_module_readiness(): void
    {
        [$actor] = $this->authorizedStoreActor();
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $this->actingAs($roleless)->get(route('admin.erp.bi', ['bi_tab' => 'exports']))
            ->assertForbidden();

        $inactive = User::factory()->create(['is_active' => false, 'locale' => 'en']);
        $inactive->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->actingAs($inactive)->get(route('admin.erp.bi', ['bi_tab' => 'schedules']))
            ->assertForbidden();

        // A UI tab cannot self-certify an unapproved ERP module.
        config()->set('enterprise_module_readiness.certified_versions.enterprise_bi', null);
        $response = $this->actingAs($actor)->get(route('admin.erp.bi', ['bi_tab' => 'reports']));
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_schedule_post_preserves_selected_workspace_on_success_and_validation_error(): void
    {
        [$actor, $store] = $this->authorizedStoreActor();
        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $returnTo = route('admin.erp.bi', [
            'bi_tab' => 'schedules',
            'report' => 'orders.summary',
            'scope' => 'store:'.$store->id,
            'from' => $from,
            'to' => $to,
        ]);
        $schedulePayload = [
            'report' => 'orders.summary',
            'scope_type' => 'store',
            'scope_id' => $store->id,
            'from' => $from,
            'to' => $to,
            'dimensions' => ['date'],
            'measures' => ['orders'],
            'interval_minutes' => 1440,
        ];

        $this->from($returnTo)
            ->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), $schedulePayload)
            ->assertRedirect($returnTo)
            ->assertSessionHasErrors(['next_run_at']);

        $this->from($returnTo)
            ->actingAs($actor)
            ->post(route('admin.erp.bi.schedules.store'), [
                ...$schedulePayload,
                'next_run_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect($returnTo)
            ->assertSessionHasNoErrors();
    }

    /** @return array{User, Store} */
    private function authorizedStoreActor(): array
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLING);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLED);

        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'BI-TABS-3951',
            'name' => 'BI Tabs Store',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return [$actor, $store];
    }

    /** @return array<string, int> */
    private function businessCounts(): array
    {
        return [
            'exports' => DB::table('enterprise_report_exports')->count(),
            'schedules' => DB::table('enterprise_scheduled_reports')->count(),
            'orders' => DB::table('orders')->count(),
            'stores' => DB::table('stores')->count(),
        ];
    }
}
