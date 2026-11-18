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
 * G009 #4026: Analytics GET filter touch targets are presentational only.
 * Never widen orders/dispatch scope or change B2B Van / B2C Driver routing.
 */
final class V2OrdersAnalyticsFilterTouchTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_b2b_and_b2c_analytics_filters_keep_exact_scope_and_dates_in_both_locales(): void
    {
        $wholesale = $this->actor();
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $this->assertGreaterThan(0, $wholesaleStore);

        $retailStore = $this->retailStore('V2-ANALYTICS-FILTER-OWN');
        $retail = $this->retailActor($retailStore);
        $before = $this->businessCounts();

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
                        'from' => '2026-11-01',
                        'to' => '2026-11-15',
                    ]))->assertOk()->assertSee('dir="'.$direction.'"', false);

                $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $this->assertSame(1, substr_count($html, 'data-order-header-controls'));

                $this->assertSame(1, preg_match(
                    '/<form\b[^>]*data-order-header-filter\b[^>]*>.*?<\/form>/s',
                    $html,
                    $desktop,
                ));
                $this->assertSame(1, preg_match(
                    '/<form\b[^>]*class="order-header-mobile-filter-panel"[^>]*>.*?<\/form>/s',
                    $html,
                    $mobile,
                ));

                foreach ([$desktop[0], $mobile[0]] as $form) {
                    $this->assertStringContainsString(
                        'action="'.route('admin.operations.orders.index').'#order-tab-analytics"',
                        $form,
                    );
                    $this->assertSame(1, substr_count($form, 'name="channel"'));
                    $this->assertSame(1, substr_count($form, 'name="action_required"'));
                    $this->assertSame(1, substr_count($form, 'name="store_id"'));
                    $this->assertSame(1, substr_count($form, 'name="from"'));
                    $this->assertSame(1, substr_count($form, 'name="to"'));
                    $this->assertSame(1, substr_count($form, 'type="submit"'));
                    $this->assertStringContainsString('name="channel" value="'.$channel.'"', $form);
                    $this->assertStringContainsString('name="action_required" value="0"', $form);
                    $this->assertStringContainsString('value="2026-11-01"', $form);
                    $this->assertStringContainsString('value="2026-11-15"', $form);
                    $this->assertStringContainsString((string) $storeId, $form);
                }

                $this->assertStringContainsString('data-order-tabs', $html);
                $this->assertStringContainsString('data-order-panel="analytics"', $html);
                $this->assertStringContainsString('data-order-panel="orders"', $html);
            }
        }

        $this->assertSame($before, $this->businessCounts(),
            'GET Analytics filters must not post orders, driver assignments or Van movements.');
    }

    public function test_analytics_toolbar_has_accessible_44px_targets_and_only_one_visible_form_per_breakpoint(): void
    {
        $source = file_get_contents(resource_path('views/admin/_order-operations-header-filters.blade.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('.order-header-controls{display:flex;', $source);
        $this->assertStringContainsString('.order-header-filter{display:flex;', $source);
        $this->assertStringContainsString('overflow-x:auto;white-space:nowrap;', $source);
        $this->assertStringContainsString('height:44px!important;min-height:44px!important;', $source);
        $this->assertStringContainsString('.order-header-filter .foodex-action-secondary{min-width:44px}', $source);
        $this->assertStringContainsString('min-height:44px;min-width:44px;', $source);
        $this->assertStringContainsString('min-height:44px;font-size:1rem', $source);
        $this->assertStringContainsString('.order-header-mobile-filter-actions>button,.order-header-mobile-filter-actions>a{min-height:44px;', $source);
        $this->assertStringContainsString(':focus-visible{outline:2px solid var(--foodex-action-green)', $source);
        $this->assertStringNotContainsString('height:34px!important', $source);
        $this->assertStringNotContainsString('height:36px;', $source);
        $this->assertStringContainsString('@media(max-width:1180px)', $source);
        $this->assertStringContainsString('.order-header-filter{display:none}', $source);
        $this->assertStringContainsString('.order-header-mobile-filter{display:block;position:relative}', $source);
        $this->assertStringContainsString('action="{{ route(\'admin.operations.orders.index\') }}#order-tab-analytics"', $source);
        $this->assertSame(1, substr_count($source, 'data-order-header-filter'));
        $this->assertSame(1, substr_count($source, '<form class="order-header-filter"'));
        $this->assertSame(1, substr_count($source, '<form class="order-header-mobile-filter-panel"'));
    }

    public function test_analytics_filters_do_not_authorize_wrong_channel_or_foreign_store(): void
    {
        $wholesale = $this->actor();
        $wholesale->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $mine = $this->retailStore('V2-ANALYTICS-STORE-A');
        $foreign = $this->retailStore('V2-ANALYTICS-STORE-B');
        $retail = $this->retailActor($mine);
        $before = $this->businessCounts();

        $this->actingAs($wholesale)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'from' => '2026-11-01',
        ]))->assertForbidden();

        $this->actingAs($retail)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
            'to' => '2026-11-15',
        ]))->assertForbidden();

        $this->actingAs($retail)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $foreign,
            'from' => '2026-11-01',
        ]))->assertNotFound();

        $roleless = $this->actor();
        $this->actingAs($roleless)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
        ]))->assertForbidden();

        $this->assertSame($before, $this->businessCounts());
    }

    /** @return list<int> */
    private function businessCounts(): array
    {
        return [
            DB::table('orders')->count(),
            DB::table('order_dispatch_states')->count(),
            DB::table('driver_assignments')->count(),
            DB::table('order_van_assignments')->count(),
            DB::table('audit_logs')->count(),
        ];
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'V2 Analytics Filter Operator',
            'email' => 'v2-analytics-filter-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailActor(int $storeId): User
    {
        $actor = $this->actor();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }
}
