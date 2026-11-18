<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ErpCenterHorizontalTabsTest extends TestCase
{
    use RefreshDatabase;

    private const PANELS = ['snapshot', 'analytics', 'follow_up', 'modules'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_server_allowlist_displays_exactly_one_panel_and_fails_closed_on_array_and_invalid_tabs(): void
    {
        $actor = $this->globalSuperAdmin();

        foreach ([
            [[], 'snapshot'],
            [['tab' => 'snapshot'], 'snapshot'],
            [['tab' => 'analytics'], 'analytics'],
            [['tab' => 'follow_up'], 'follow_up'],
            [['tab' => 'modules'], 'modules'],
            [['tab' => 'unknown'], 'snapshot'],
            [['tab' => ['analytics']], 'snapshot'],
            [['module' => 'procurement'], 'modules'],
            [['module' => 'missing-module'], 'snapshot'],
            [['module' => 'procurement', 'tab' => ['analytics']], 'modules'],
            [['status' => 'available'], 'modules'],
            [['status' => 'available', 'tab' => ['analytics']], 'modules'],
        ] as [$query, $expected]) {
            $response = $this->actingAs($actor)->get(route('admin.erp.index', $query))->assertOk();
            $markup = $response->getContent();
            preg_match_all('/<section\\b[^>]*\\bdata-erp-panel="([^"]+)"[^>]*>/s', $markup, $matches, PREG_SET_ORDER);

            $this->assertCount(1, $matches, 'Only the authorized selected panel may be present in the HTML.');
            $this->assertSame($expected, $matches[0][1], json_encode($query));
            $this->assertStringContainsString('aria-labelledby="erp-tab-'.$expected.'"', $matches[0][0]);
            $this->assertStringNotContainsString(' hidden', $matches[0][0]);
            foreach (self::PANELS as $panel) {
                if ($panel !== $expected) {
                    $this->assertStringNotContainsString('data-erp-panel="'.$panel.'"', $markup);
                }
            }
            $this->assertSame(4, substr_count($markup, 'data-erp-tab="'));
            $this->assertSame(1, substr_count($markup, 'class="foodex-tab active"'));
        }
    }

    public function test_existing_erp_links_filters_and_localized_accessible_tabs_keep_authority_and_context(): void
    {
        $actor = $this->globalSuperAdmin();

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())->get(route('admin.erp.index', [
                'tab' => 'analytics', 'status' => 'available', 'module' => 'procurement',
            ]))->assertOk();

            $response
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('class="foodex-tabs erp-workspace-tabs"', false)
                ->assertSee('aria-current="page"', false)
                ->assertSee('data-erp-chart="workload"', false)
                ->assertDontSee('data-erp-kpis', false)
                ->assertDontSee('data-erp-my-work', false)
                ->assertDontSee('data-erp-modules', false);

            $markup = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString(
                route('admin.erp.index', [
                    'status' => 'available', 'module' => 'procurement', 'tab' => 'modules',
                ]),
                $markup,
            );
            // The modules filter links are emitted only when the modules panel is selected.
            $modulesHtml = html_entity_decode(
                $this->actingAs($actor->fresh())
                    ->get(route('admin.erp.index', ['status' => 'available', 'tab' => 'modules']))
                    ->assertOk()
                    ->assertSee('data-erp-modules', false)
                    ->assertDontSee('data-erp-my-work', false)
                    ->getContent(),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
            $this->assertStringContainsString(
                route('admin.erp.index', ['status' => 'available', 'tab' => 'modules']).'#erp-modules',
                $modulesHtml,
            );
            // Do not ship follow-up work, KPI cards or their deep links in inactive panels.
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index', ['tab' => 'snapshot']))
                ->assertOk()
                ->assertSee('data-erp-kpis', false)
                ->assertDontSee('data-erp-my-work', false)
                ->assertDontSee('data-erp-modules', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.erp.index', ['tab' => 'follow_up']))
                ->assertOk()
                ->assertSee('data-erp-my-work', false)
                ->assertSee(route('admin.action-center.index'), false)
                ->assertDontSee('data-erp-kpis', false)
                ->assertDontSee('data-erp-modules', false);
            foreach (self::PANELS as $key) {
                $this->assertStringContainsString('id="erp-tab-'.$key.'"', $markup);
            }
        }
    }

    public function test_switching_tabs_does_not_mutate_module_or_entitlement_state(): void
    {
        $actor = $this->globalSuperAdmin();
        $this->actingAs($actor)->get(route('admin.erp.index'))->assertOk();
        // Row counts alone miss a tab GET that silently changes an existing
        // module state or entitlement value. Snapshot every persisted column
        // after the permitted initial local Super Admin bootstrap.
        $before = [
            DB::table('enterprise_modules')->orderBy('id')->get()->toJson(),
            DB::table('enterprise_module_entitlements')->orderBy('id')->get()->toJson(),
        ];

        foreach ([
            ...array_map(static fn (string $tab): array => ['tab' => $tab], self::PANELS),
            ['tab' => ['modules']],
            ['tab' => 'modules', 'status' => 'available', 'module' => 'procurement'],
        ] as $query) {
            $this->actingAs($actor)->get(route('admin.erp.index', $query))->assertOk();
        }

        $this->assertSame($before, [
            DB::table('enterprise_modules')->orderBy('id')->get()->toJson(),
            DB::table('enterprise_module_entitlements')->orderBy('id')->get()->toJson(),
        ], 'Read-only ERP tab navigation must not modify persisted module state or entitlement values.');
    }

    public function test_unauthenticated_and_store_only_actor_cannot_reach_erp_via_tab_query(): void
    {
        $this->get(route('admin.erp.index', ['tab' => 'modules']))->assertRedirect();

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-TABS-STORE-B',
            'name' => 'V2 ERP Tabs Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['snapshot', 'modules', 'analytics'] as $tab) {
            $this->actingAs($retail)->get(route('admin.erp.index', [
                'tab' => $tab, 'module' => 'treasury', 'status' => 'available',
            ]))->assertForbidden();
        }
    }

    private function globalSuperAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $actor;
    }
}
