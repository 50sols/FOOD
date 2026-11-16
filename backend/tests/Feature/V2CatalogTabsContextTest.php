<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CatalogTabsContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_six_catalog_workspaces_have_one_authorized_active_link_in_both_locales(): void
    {
        $store = $this->retailStore('V2-CATALOG-TABS-OWN');
        $actor = $this->retailActor($store);

        $before = [
            DB::table('products')->count(),
            DB::table('categories')->count(),
            DB::table('store_products')->count(),
            DB::table('audit_logs')->count(),
        ];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach (['overview', 'products', 'app_products', 'categories', 'attributes', 'import'] as $tab) {
                $response = $this->actingAs($actor->fresh())
                    ->get(route('admin.catalog.index', ['tab' => $tab, 'store_id' => $store]))
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false)
                    ->assertSee('data-catalog-workspace-tabs', false);

                $rail = $this->workspaceRail($response->getContent());
                preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>/', $rail, $anchors, PREG_SET_ORDER);
                $this->assertCount(6, $anchors, 'Only the six existing Catalog workspaces are page tabs.');
                $this->assertSame(1, substr_count($rail, 'aria-current="page"'));
                $this->assertSame(6, substr_count($rail, 'foodex-tab'));

                foreach ($anchors as $anchor) {
                    $link = html_entity_decode($anchor[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
                    $this->assertSame((string) $store, (string) ($query['store_id'] ?? null));
                    $this->assertContains($query['tab'] ?? null, [
                        'overview', 'products', 'app_products', 'categories', 'attributes', 'import',
                    ]);
                    $this->assertStringNotContainsString('support_access=1', $link);
                    $this->assertStringNotContainsString('product_detail=', $link);
                    $this->assertStringNotContainsString('category_detail=', $link);
                }

                $this->assertSame(1, preg_match(
                    '/<a\b[^>]*aria-current="page"[^>]*href="[^"]*"/',
                    $rail,
                ) + preg_match(
                    '/<a\b[^>]*href="[^"]*"[^>]*aria-current="page"/',
                    $rail,
                ) >= 1 ? 1 : 0);

                if ($tab !== 'products') {
                    $response->assertDontSee('data-dialog-open="catalog-add-product"', false);
                    $response->assertDontSee('data-catalog-product-filters', false);
                }
                if ($tab !== 'import') {
                    $response->assertDontSee('action="'.route('admin.catalog.import.preview').'"', false);
                }
                if ($tab === 'import') {
                    $response->assertSee('action="'.route('admin.catalog.import.preview').'"', false);
                    $response->assertDontSee('action="'.route('admin.catalog.import.commit').'"', false);
                }
            }
        }

        $this->assertSame($before, [
            DB::table('products')->count(),
            DB::table('categories')->count(),
            DB::table('store_products')->count(),
            DB::table('audit_logs')->count(),
        ]);
    }

    public function test_catalog_tab_navigation_preserves_validated_search_and_store_but_not_record_or_pagination_intent(): void
    {
        $store = $this->retailStore('V2-CATALOG-FILTER-OWN');
        $actor = $this->retailActor($store);

        $response = $this->actingAs($actor)->get(route('admin.catalog.index', [
            'tab' => 'products',
            'store_id' => $store,
            'product_q' => 'Coffee & Milk',
            'product_status' => 'active',
            'product_dir' => 'desc',
            'category_q' => 'Beverages',
            'category_parent' => 'root',
            'product_page' => 3,
            'category_page' => 4,
        ]))->assertOk();

        $rail = $this->workspaceRail($response->getContent());
        preg_match_all('/href="([^"]+)"/', $rail, $anchors);
        $this->assertCount(6, $anchors[1]);

        foreach ($anchors[1] as $anchor) {
            $link = html_entity_decode($anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

            $this->assertSame((string) $store, (string) $query['store_id']);
            $this->assertSame('Coffee & Milk', $query['product_q']);
            $this->assertSame('active', $query['product_status']);
            $this->assertSame('desc', $query['product_dir']);
            $this->assertSame('Beverages', $query['category_q']);
            $this->assertSame('root', $query['category_parent']);
            $this->assertArrayNotHasKey('product_page', $query);
            $this->assertArrayNotHasKey('category_page', $query);
            $this->assertArrayNotHasKey('product_detail', $query);
        }

        $this->assertStringNotContainsString('/admin/lookups', $rail);
        $this->assertStringNotContainsString('Inventory Management</a>', $rail);
        $response->assertSee(route('admin.lookups.index'), false);
    }

    public function test_forged_array_tab_is_rejected_and_store_role_boundaries_remain_server_enforced(): void
    {
        $own = $this->retailStore('V2-CATALOG-SCOPE-OWN');
        $other = $this->retailStore('V2-CATALOG-SCOPE-OTHER');
        $actor = $this->retailActor($own);
        $before = DB::table('audit_logs')->count();

        $this->actingAs($actor)->get(route('admin.catalog.index').'?tab[]=products&store_id='.$own)
            ->assertNotFound();

        $this->actingAs($actor)->get(route('admin.catalog.index', [
            'tab' => 'products',
            'store_id' => $other,
        ]))->assertForbidden();

        $roleless = User::query()->create([
            'name' => 'No catalog role',
            'email' => 'v2-catalog-tabs-roleless@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)->get(route('admin.catalog.index', [
            'tab' => 'categories',
        ]))->assertForbidden();

        $inactive = $this->retailActor($own);
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get(route('admin.catalog.index', [
            'tab' => 'products',
        ]))->assertForbidden();

        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    private function workspaceRail(string $html): string
    {
        $this->assertSame(1, preg_match(
            '/<nav\b[^>]*data-catalog-workspace-tabs[^>]*>(.*?)<\/nav>/s',
            $html,
            $match,
        ));

        return $match[1];
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailActor(int $store): User
    {
        $actor = User::query()->create([
            'name' => 'V2 Catalog Tester',
            'email' => 'v2-catalog-tabs-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);

        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
