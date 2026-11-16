<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2RestaurantGrowthHorizontalTabsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const TABS = ['gift_cards', 'lifecycle', 'franchise', 'analytics', 'accounting', 'portability'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_growth_page_reuses_six_authorized_sibling_tabs_with_one_visible_panel(): void
    {
        [$actor, $site] = $this->restaurantOperator();

        foreach (['en', 'ar'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach (self::TABS as $selected) {
                $url = route('admin.smart-ordering.growth.index', ['site' => $site, 'tab' => $selected]);
                $response = $this->actingAs($actor)->get($url)->assertOk();
                $html = $response->getContent();

                $this->assertStringContainsString('class="foodex-tabs rg-tabs"', $html);
                $this->assertSame(6, preg_match_all('/data-growth-tab="[^"]+"/', $html));
                $this->assertSame(6, preg_match_all('/data-growth-panel="[^"]+"/', $html));
                $this->assertSame(1, preg_match_all('/data-growth-tab="[^"]+"[^>]*aria-current="page"/', $html));
                $this->assertSame(5, preg_match_all('/data-growth-panel="[^"]+"[^>]*hidden/', $html));

                foreach (self::TABS as $tab) {
                    $this->assertMatchesRegularExpression(
                        '/<section\b[^>]*data-growth-panel="'.preg_quote($tab, '/').'"[^>]*>/',
                        $html,
                    );

                    $this->assertSame($tab !== $selected, (bool) preg_match(
                        '/<section\b[^>]*data-growth-panel="'.preg_quote($tab, '/').'"[^>]*hidden[^>]*>/',
                        $html,
                    ));
                }

                $this->assertStringContainsString('lang="'.$locale.'"', $html);
                $this->assertStringContainsString(__('restaurant_growth.'.$selected, [], $locale), $html);
            }
        }

        $this->assertDatabaseCount('restaurant_saas_gift_cards', 0);
        $this->assertDatabaseCount('restaurant_saas_lifecycle_journeys', 0);
    }

    public function test_unknown_or_array_tab_is_safe_and_defaults_to_gift_cards(): void
    {
        [$actor, $site] = $this->restaurantOperator();

        foreach ([['tab', 'no-such-panel'], ['tab[]', 'analytics'], ['tab', '<script>alert(1)</script>']] as [$key, $value]) {
            $url = route('admin.smart-ordering.growth.index', ['site' => $site]).'?'.urlencode($key).'='.urlencode($value);
            $html = $this->actingAs($actor)->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match_all('/data-growth-tab="gift_cards"[^>]*aria-current="page"/', $html));
            $this->assertMatchesRegularExpression('/data-growth-panel="analytics"[^>]*hidden/', $html);
        }
    }

    public function test_growth_tabs_do_not_widen_authorization_to_another_retail_store_or_wholesale_actor(): void
    {
        [$actor, $site] = $this->restaurantOperator();
        [, $foreignSite] = $this->restaurantOperator();

        $this->actingAs($actor)
            ->get(route('admin.smart-ordering.growth.index', ['site' => $foreignSite, 'tab' => 'accounting']))
            ->assertNotFound();

        $wholesale = User::query()->create([
            'name' => 'Wholesale Only',
            'email' => 'growth-wholesale-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $this->actingAs($wholesale)
            ->get(route('admin.smart-ordering.growth.index', ['site' => $site, 'tab' => 'gift_cards']))
            ->assertNotFound();

        $this->assertDatabaseCount('restaurant_saas_gift_cards', 0);
    }

    public function test_invalid_post_redirect_preserves_the_selected_lifecycle_tab_without_writes(): void
    {
        [$actor, $site] = $this->restaurantOperator();

        $page = route('admin.smart-ordering.growth.index', ['site' => $site, 'tab' => 'lifecycle']);
        $this->actingAs($actor)
            ->withHeaders(['Referer' => $page])
            ->post(route('admin.smart-ordering.growth.journeys.store', $site), [])
            ->assertRedirect($page)
            ->assertSessionHasErrors(['name', 'trigger_event']);

        $this->assertDatabaseCount('restaurant_saas_lifecycle_journeys', 0);
    }

    /** @return array{0:User,1:SmartOrderingSite} */
    private function restaurantOperator(): array
    {
        $store = Store::query()->create([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'TABS-'.Str::upper(Str::random(8)),
            'name' => 'Growth Tabs Restaurant',
            'is_active' => true,
        ]);

        $this->enableRestaurantSaasForTestStore((int) $store->getKey());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        $site = SmartOrderingSite::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $store->getKey(),
            'slug' => 'growth-tabs-'.Str::lower(Str::random(10)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
        ]);

        $actor = User::query()->create([
            'name' => 'Growth Tabs Operator',
            'email' => 'growth-tabs-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'store_id' => $store->getKey(),
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$actor, $site];
    }
}
