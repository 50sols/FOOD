<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G009 #3996: Orders filter presentation cannot hide functional search controls,
 * corrupt the Apply action, or change any routing/fulfillment state.
 */
final class V2OrdersFilterRowAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_and_retail_orders_keep_one_working_filter_form_in_arabic_and_english(): void
    {
        $wholesale = $this->wholesaleActor();
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $this->assertGreaterThan(0, $wholesaleStore);

        $retailStore = $this->retailStore('V2-ORDERS-FILTERS-OWN');
        $retail = $this->retailActor($retailStore);
        $before = $this->readOnlyCounts();

        foreach ([
            [$wholesale, 'b2b', $wholesaleStore],
            [$retail, 'b2c', $retailStore],
        ] as [$actor, $channel, $storeId]) {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->save();
                app()->setLocale($locale);

                $response = $this->actingAs($actor->fresh())
                    ->get(route('admin.operations.orders.index', [
                        'channel' => $channel,
                        'store_id' => $storeId,
                        'order_number' => 'O-1234',
                        'status' => 'pending',
                        'per_page' => 10,
                    ]))->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('data-order-grid-filter', false)
                    ->assertSee('data-order-primary-filters', false);

                $this->assertSame(1, preg_match(
                    '/<form\b[^>]*data-order-grid-filter\b[^>]*>(.*?)<\/form>/s',
                    $response->getContent(),
                    $matches,
                ));
                $form = $matches[0];

                $this->assertSame(1, substr_count($form, 'type="submit"'));
                $this->assertSame(1, substr_count($form, 'class="foodex-filter-action" type="submit"'));
                $this->assertSame(1, substr_count($form, 'name="order_number"'));
                $this->assertSame(1, substr_count($form, 'name="status"'));
                $this->assertSame(1, substr_count($form, 'name="driver_id"'));
                $this->assertSame(1, substr_count($form, 'data-order-primary-filters'));
                $this->assertSame(1, substr_count($form, 'data-order-filter-reset-context'));
                $this->assertSame(1, substr_count($form, 'class="order-grid-filter-more"'));
                $this->assertStringContainsString('name="channel" value="'.$channel.'"', $form);
                $this->assertStringContainsString('name="action_required"', $form);
                $this->assertStringContainsString('name="store_id"', $form);
                $this->assertStringContainsString('value="O-1234"', $form);

                $this->assertSame(1, preg_match('/data-order-filter-reset-context href="([^"]+)"/', $form, $reset));
                $href = html_entity_decode($reset[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                parse_str((string) parse_url($href, PHP_URL_QUERY), $resetQuery);
                $this->assertSame($channel, $resetQuery['channel'] ?? null);
                $this->assertSame((string) $storeId, (string) ($resetQuery['store_id'] ?? null));
                $this->assertSame('order-tab-orders', parse_url($href, PHP_URL_FRAGMENT));
            }
        }

        $this->assertSame($before, $this->readOnlyCounts(),
            'GET filtering must not create/modify orders, dispatch, drivers, vans or audit history.');
    }

    public function test_filter_markup_stays_one_row_scrollable_and_touch_accessible_without_breakpoint_hiding(): void
    {
        $source = file_get_contents(resource_path('views/admin/_order-operations-order-filters.blade.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('data-order-primary-filters role="group" tabindex="0"', $source);
        $this->assertStringContainsString('.order-grid-filter-primary{display:flex;', $source);
        $this->assertStringContainsString('overflow-x:auto;overflow-y:hidden;', $source);
        $this->assertStringContainsString('.order-grid-filter-primary>label{flex:0 0 auto}', $source);
        $this->assertStringContainsString('scrollbar-width:thin', $source);
        $this->assertStringContainsString('.order-workspace-filter-slot:has([data-order-grid-filter-controls]){flex:0 1 60%;max-width:60%;min-width:0}', $source);
        $this->assertStringContainsString('.order-workspace-bar:has([data-order-grid-filter-controls]){flex-wrap:wrap}', $source);
        $this->assertStringContainsString('.order-grid-filter-form{order:2;flex:1 1 100%;width:100%}', $source);
        $this->assertStringContainsString('height:44px!important;min-height:44px!important;', $source);
        $this->assertStringContainsString('font-size:1rem!important', $source);
        $this->assertStringContainsString('type="submit">{{ __(\'order_operations.filters.apply\') }}</button>', $source);
        $this->assertStringNotContainsString('order-gr="submit', $source);
        $this->assertStringNotContainsString('select[name="driver_id"]{display:none}', $source);
        $this->assertStringNotContainsString('select[name="status"]{display:none}', $source);
        $this->assertStringNotContainsString('input[name="order_number"]{display:none}', $source);
        $this->assertStringContainsString("route('admin.operations.orders.index') }}#order-tab-orders", $source);

        // No new page, endpoint, script or dispatcher was introduced to switch tabs.
        $this->assertSame(1, substr_count($source, 'type="submit"'));
        $this->assertSame(1, substr_count($source, 'data-order-primary-filters'));
        $this->assertSame(1, substr_count($source, '<form class="order-grid-filter-form"'));
    }

    public function test_filters_do_not_grant_other_channels_or_foreign_stores_and_inactive_principal_is_denied(): void
    {
        $wholesale = $this->wholesaleActor();
        $store = $this->retailStore('V2-ORDERS-FILTERS-RETAIL');
        $other = $this->retailStore('V2-ORDERS-FILTERS-OTHER');
        $retail = $this->retailActor($store);
        $before = $this->readOnlyCounts();

        $this->actingAs($wholesale)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'order_number' => 'O-1234',
        ]))->assertForbidden();

        $this->actingAs($retail)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'order_number' => 'O-1234',
        ]))->assertForbidden();

        $this->actingAs($retail)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $other,
            'order_number' => 'O-1234',
        ]))->assertNotFound();

        $inactive = $this->wholesaleActor();
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
        ]))->assertForbidden();

        $this->assertSame($before, $this->readOnlyCounts());
    }

    private function readOnlyCounts(): array
    {
        return [
            DB::table('orders')->count(),
            DB::table('order_dispatch_states')->count(),
            DB::table('driver_assignments')->count(),
            DB::table('order_van_assignments')->count(),
            DB::table('audit_logs')->count(),
        ];
    }

    private function wholesaleActor(): User
    {
        $actor = $this->user();
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailActor(int $storeId): User
    {
        $actor = $this->user();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'V2 Orders Filter User',
            'email' => 'v2-orders-filter-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
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
}
