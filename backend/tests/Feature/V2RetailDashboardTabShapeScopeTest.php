<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailDashboardTabShapeScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_five_dashboard_tabs_keep_exact_selected_authorized_store_and_dates_in_both_locales(): void
    {
        $storeOne = $this->retailStore('V2-3978-STORE-A');
        $storeTwo = $this->retailStore('V2-3978-STORE-B');
        $actor = $this->retailActor([$storeOne, $storeTwo]);
        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        foreach (['overview', 'sales', 'inventory', 'customers', 'analytics'] as $index => $tab) {
            $storeId = $index % 2 === 0 ? $storeOne : $storeTwo;
            $locale = $index % 2 === 0 ? 'ar' : 'en';
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.dashboard', [
                    'store_id' => $storeId,
                    'dashboard_tab' => $tab,
                    'from' => $from,
                    'to' => $to,
                ]));

            $response->assertOk()
                ->assertViewHas('storeId', $storeId)
                ->assertViewHas('supportAccess', false)
                ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                ->assertSee('data-dashboard-pane="'.$tab.'"', false);

            foreach (['overview', 'sales', 'inventory', 'customers', 'analytics'] as $target) {
                $link = route('admin.b2c.dashboard', [
                    'store_id' => $storeId,
                    'from' => $from,
                    'to' => $to,
                    'dashboard_tab' => $target,
                ]);
                $response->assertSee(e($link), false);
            }
        }
    }

    public function test_retail_tabs_are_44px_single_row_keyboard_links_with_exactly_one_current_page_in_ar_and_en(): void
    {
        $storeOne = $this->retailStore('V2-4004-A11Y-A');
        $storeTwo = $this->retailStore('V2-4004-A11Y-B');
        $actor = $this->retailActor([$storeOne, $storeTwo]);
        $counts = $this->businessCounts();
        $tabs = ['overview', 'sales', 'inventory', 'customers', 'analytics'];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach ($tabs as $index => $selectedTab) {
                $storeId = $index % 2 === 0 ? $storeOne : $storeTwo;
                $query = [
                    'store_id' => $storeId,
                    'dashboard_tab' => $selectedTab,
                ];

                $html = $this->actingAs($actor->fresh())
                    ->get(route('admin.b2c.dashboard', $query))
                    ->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('data-dashboard-pane="'.$selectedTab.'"', false)
                    ->getContent();

                $this->assertSame(
                    1,
                    preg_match('/<nav class="dashboard-tabs"[^>]*>(.*?)<\\/nav>/s', $html, $matches),
                );
                $nav = $matches[1];
                $this->assertSame(5, preg_match_all('/<a\\b[^>]*class="dashboard-tab(?: active)?\\s*"[^>]*>/s', $nav));
                $this->assertSame(
                    1,
                    preg_match_all('/<a\\b[^>]*class="dashboard-tab active"[^>]*aria-current="page"/s', $nav),
                );
                $this->assertStringNotContainsString('role="tab"', $nav);
                $this->assertStringNotContainsString('aria-selected', $nav);
                $this->assertStringContainsString(
                    'href="'.e(route('admin.b2c.dashboard', $query)).'"',
                    $nav,
                );

                // This is a native GET navigation rail, not a client-side tab widget.
                // Enter follows an anchor; Space and arrows retain browser scrolling.
                $this->assertStringContainsString(
                    '.dashboard-tabs{display:flex;flex-wrap:nowrap;align-items:center;',
                    $html,
                );
                $this->assertStringContainsString(
                    'max-width:100%;min-width:0;overflow-x:auto;overscroll-behavior-inline:contain',
                    $html,
                );
                $this->assertStringContainsString(
                    '.dashboard-tab{min-height:44px;flex:0 0 auto;',
                    $html,
                );
                $this->assertStringContainsString(
                    '.dashboard-tab:focus-visible{outline:3px solid var(--foodex-green-dark);outline-offset:1px;',
                    $html,
                );
            }
        }

        $this->assertSame($counts, $this->businessCounts());
    }

    public function test_malformed_dashboard_tab_shape_returns_404_without_mutating_retail_data_or_changing_legacy_scalar_fallback(): void
    {
        $storeId = $this->retailStore('V2-3978-SHAPE');
        $actor = $this->retailActor([$storeId]);
        $counts = $this->businessCounts();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/dashboard?store_id='.$storeId.'&dashboard_tab%5B%5D=inventory')
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get('/admin/b2c/dashboard?store_id='.$storeId.'&dashboard_tab%5Bname%5D=sales')
                ->assertNotFound();

            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.dashboard', [
                    'store_id' => $storeId,
                    'dashboard_tab' => 'unrecognized-legacy-value',
                ]))
                ->assertOk()
                ->assertViewHas('storeId', $storeId)
                ->assertSee('data-dashboard-pane="overview"', false);
        }

        $this->assertSame($counts, $this->businessCounts());
    }

    public function test_tab_query_never_grants_foreign_store_roleless_or_inactive_user_access(): void
    {
        $storeOne = $this->retailStore('V2-3978-OWN');
        $storeTwo = $this->retailStore('V2-3978-FOREIGN');
        $inactiveStore = $this->retailStore('V2-3978-INACTIVE');
        DB::table('stores')->where('id', $inactiveStore)->update(['is_active' => false]);

        $actor = $this->retailActor([$storeOne]);
        foreach ([$storeTwo, $inactiveStore] as $deniedStoreId) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.dashboard', [
                    'store_id' => $deniedStoreId,
                    'dashboard_tab' => 'inventory',
                ]))
                ->assertNotFound();
        }

        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $this->actingAs($roleless)
            ->get(route('admin.b2c.dashboard', [
                'store_id' => $storeOne,
                'dashboard_tab' => 'analytics',
            ]))
            ->assertForbidden();

        $inactive = $this->retailActor([$storeOne]);
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive->fresh())
            ->get(route('admin.b2c.dashboard', [
                'store_id' => $storeOne,
                'dashboard_tab' => 'sales',
            ]))
            ->assertForbidden();
    }

    /**
     * @param  list<int>  $storeIds
     */
    private function retailActor(array $storeIds): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $roleId = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey();

        foreach ($storeIds as $storeId) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->getKey(),
                'store_id' => $storeId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function businessCounts(): array
    {
        return [
            'stores' => DB::table('stores')->count(),
            'orders' => DB::table('orders')->count(),
            'store_products' => DB::table('store_products')->count(),
            'inventories' => DB::table('inventories')->count(),
            'promotions' => DB::table('promotions')->count(),
        ];
    }
}
