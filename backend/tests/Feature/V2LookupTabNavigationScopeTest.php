<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2LookupTabNavigationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_brands_and_units_tabs_keep_scoped_store_search_status_and_bilingual_current_page(): void
    {
        $storeA = $this->store('V2-4024-LOOKUP-A');
        $storeB = $this->store('V2-4024-LOOKUP-B');
        $actor = $this->retailActor($storeA);

        $before = $this->recordCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach (['brands', 'units'] as $type) {
                $response = $this->actingAs($actor->fresh())->get(route('admin.lookups.index', [
                    'type' => $type,
                    'store_id' => $storeA,
                    'q' => 'محددة%_!',
                    'status' => 'active',
                ]))->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertViewHas('type', $type);

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(1, preg_match(
                    '/<nav class="tabs"[^>]*data-v2-lookup-tabs[^>]*>(.*?)<\/nav>/s',
                    $html,
                    $nav,
                ));
                $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
                $this->assertStringContainsString('min-height:44px', $html);
                $this->assertStringContainsString('overflow-x:auto', $html);

                $links = $this->navQueries($nav[1]);
                $this->assertCount(2, $links);
                foreach (['brands', 'units'] as $candidate) {
                    $this->assertArrayHasKey($candidate, $links);
                    $this->assertSame('محددة%_!', $links[$candidate]['q'] ?? null);
                    $this->assertSame('active', $links[$candidate]['status'] ?? null);
                    $this->assertSame((string) $storeA, $links[$candidate]['store_id'] ?? null);
                    $this->assertArrayNotHasKey('page', $links[$candidate]);
                    $this->assertArrayNotHasKey('support_access', $links[$candidate]);
                }
            }
        }

        $this->actingAs($actor)->get(route('admin.lookups.index', [
            'type' => 'units', 'store_id' => $storeB,
        ]))->assertNotFound();

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_system_lookup_five_tabs_keep_validated_search_status_and_active_page(): void
    {
        $actor = $this->globalActor();

        $types = [
            'payment-operation-types',
            'payment-methods',
            'pricing-tiers',
            'order-statuses',
            'failed-delivery-reasons',
        ];
        $before = $this->recordCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            foreach ($types as $type) {
                $response = $this->actingAs($actor->fresh())->get(route('admin.operations.lookups.index', [
                    'type' => $type,
                    'q' => 'مسموح%_!',
                    'status' => 'inactive',
                ]))->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertViewHas('typeKey', $type);

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(1, preg_match(
                    '/<nav class="tabs"[^>]*data-v2-system-lookup-tabs[^>]*>(.*?)<\/nav>/s',
                    $html,
                    $nav,
                ));
                $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
                $this->assertStringContainsString('min-height:44px', $html);

                $links = $this->navQueries($nav[1]);
                $this->assertCount(5, $links);
                foreach ($types as $candidate) {
                    $this->assertArrayHasKey($candidate, $links);
                    $this->assertSame('مسموح%_!', $links[$candidate]['q'] ?? null);
                    $this->assertSame('inactive', $links[$candidate]['status'] ?? null);
                    $this->assertArrayNotHasKey('page', $links[$candidate]);
                }
            }
        }

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_malformed_nested_lookup_tab_queries_are_not_found_and_never_mutate_records(): void
    {
        $actor = $this->globalActor();
        $before = $this->recordCounts();

        foreach (['/admin/lookups', '/admin/operations/lookups'] as $path) {
            foreach ([
                ['type' => ['brands']],
                ['type' => ['pricing-tiers', 'units']],
                ['type' => ['nested' => ['payment-methods']]],
            ] as $query) {
                $this->actingAs($actor)->get($path.'?'.http_build_query($query))
                    ->assertNotFound();
            }
        }

        // Keep existing scalar behavior: Catalog unknown scalar defaults to
        // Brands; System Lookups still reject an unknown type with 404.
        $this->actingAs($actor)->get('/admin/lookups?type=unknown')
            ->assertOk()->assertViewHas('type', 'brands');
        $this->actingAs($actor)->get('/admin/operations/lookups?type=unknown')
            ->assertNotFound();

        $this->assertSame($before, $this->recordCounts());
    }

    public function test_roleless_user_cannot_use_either_tab_bar_or_access_lookups(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        foreach (['/admin/lookups?type=units', '/admin/operations/lookups?type=pricing-tiers'] as $url) {
            $this->actingAs($actor)->get($url)->assertForbidden();
        }
    }

    /** @return array<string,array<string,string>> */
    private function navQueries(string $nav): array
    {
        $links = [];
        preg_match_all('/<a\b[^>]*href="([^"]+)"[^>]*>/', $nav, $matches);
        foreach ($matches[1] as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
            $type = $params['type'] ?? null;
            if (is_string($type)) {
                $links[$type] = $params;
            }
        }

        return $links;
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailActor(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function globalActor(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $actor;
    }

    /** @return array<int,int> */
    private function recordCounts(): array
    {
        return [
            DB::table('brands')->count(),
            DB::table('units')->count(),
            DB::table('operational_lookups')->count(),
            DB::table('b2b_price_tiers')->count(),
            DB::table('audit_logs')->count(),
        ];
    }
}
