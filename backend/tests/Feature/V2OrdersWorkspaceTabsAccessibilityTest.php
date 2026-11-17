<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\FulfillmentActorPolicy;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * G009 #3972: an accessible switch between existing Orders/Analytics panels
 * is a read-only UI choice, not a fulfillment or route-assignment operation.
 */
final class V2OrdersWorkspaceTabsAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_wholesale_orders_tabs_render_aria_panel_wiring_without_mutating_dispatch_in_both_locales(): void
    {
        $actor = $this->wholesaleActor();
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->assertGreaterThan(0, $storeId);
        $before = $this->readOnlyCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            $response = $this->actingAs($actor->fresh())->get(route('admin.operations.orders.index', [
                'channel' => 'b2b',
                'store_id' => $storeId,
                'per_page' => 10,
            ]))->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-order-tabs', false)
                ->assertSee('id="order-tab-orders"', false)
                ->assertSee('aria-controls="order-tabpanel-orders"', false)
                ->assertSee('id="order-tabpanel-orders"', false)
                ->assertSee('aria-labelledby="order-tab-orders"', false);

            $html = $response->getContent();
            $this->assertSame(1, preg_match(
                '/<nav\b[^>]*data-order-tabs[^>]*>(.*?)<\/nav>/s',
                $html,
                $outerMatch,
            ));
            $rail = $outerMatch[1];
            $this->assertSame(1, substr_count($rail, 'aria-selected="true"'));
            $this->assertStringContainsString('role="tab"', $rail);
            $this->assertStringContainsString('tabindex="0"', $rail);
            $this->assertStringContainsString('data-order-tab="orders"', $rail);

            // Analytics is an independent workspace only where the existing
            // backend-authorized dashboard is visible. Do not grant it here.
            if (str_contains($html, 'data-sales-operations-dashboard')) {
                $this->assertStringContainsString('id="order-tab-analytics"', $rail);
                $this->assertStringContainsString('aria-controls="order-tabpanel-analytics"', $rail);
                $this->assertStringContainsString('id="order-tabpanel-analytics"', $html);
                $this->assertSame(1, preg_match(
                    '/<div\b[^>]*id="order-tabpanel-analytics"[^>]*hidden>/',
                    $html,
                ));

                $this->assertSame(1, preg_match(
                    '/<nav\b[^>]*data-order-analytics-tabs[^>]*>(.*?)<\/nav>/s',
                    $html,
                    $analyticsMatch,
                ));
                $this->assertSame(4, substr_count($analyticsMatch[1], 'data-analytics-tab='));
                $this->assertSame(1, substr_count($analyticsMatch[1], 'aria-selected="true"'));
            } else {
                $this->assertStringNotContainsString('id="order-tab-analytics"', $rail);
                $this->assertSame(1, substr_count($rail, 'data-order-tab='));
            }

            $response->assertSee('#order-tab-orders', false);
            $this->assertSame($before, $this->readOnlyCounts(), 'GET tab/read must not assign Driver, Van or change order state.');
        }
    }

    public function test_existing_tab_control_has_keyboard_roving_focus_and_44px_touch_targets_for_rtl_ltr(): void
    {
        $source = file_get_contents(resource_path('views/admin/order-operations.blade.php'));
        $this->assertIsString($source);

        $this->assertStringContainsString('.order-workspace-tab{display:inline-flex;', $source);
        $this->assertStringContainsString('min-height:44px', $source);
        $this->assertStringContainsString('.order-analytics-subtab{min-height:44px', $source);
        $this->assertStringContainsString('.order-workspace-tab{min-height:44px;padding-inline:11px}', $source);
        $this->assertStringContainsString('.order-analytics-subtab{min-height:44px;padding-inline:10px}', $source);
        $this->assertStringNotContainsString('.order-workspace-tab{min-height:36px;', $source);
        $this->assertStringNotContainsString('.order-analytics-subtab{min-height:32px;', $source);
        $this->assertStringContainsString('.order-workspace-tab:focus-visible,.order-analytics-subtab:focus-visible', $source);

        // Both existing ARIA tablists support native keyboard semantics.
        $this->assertSame(2, substr_count($source, "tabs.addEventListener('keydown'"));
        $this->assertSame(2, substr_count($source, "'ArrowLeft', 'ArrowRight', 'Home', 'End'"));
        $this->assertSame(2, substr_count($source, "document.documentElement.dir === 'rtl'"));
        $this->assertSame(2, substr_count($source, 'target.focus();'));

        // No tab click may POST, dispatch or rewrite workflow state; existing
        // orders/deep-link filter hash and order panel selection remain intact.
        $this->assertStringContainsString("'#order-tab-' + name", $source);
        $this->assertStringContainsString('panel.hidden = panel.dataset.orderPanel !== name;', $source);
        $this->assertStringContainsString('filter.hidden = filter.dataset.orderFilterMode !== name;', $source);
        $this->assertStringContainsString('card.hidden = card.dataset.analyticsGroup !== name;', $source);
        $this->assertStringContainsString('data-order-inline-dispatch', $source);
    }

    public function test_channel_and_tenant_authority_is_unchanged_by_tab_navigation(): void
    {
        $b2b = $this->wholesaleActor();
        $b2cStore = $this->retailStore('V2-ORDERS-TABS-RETAIL');
        $b2c = $this->retailActor($b2cStore);
        $before = $this->readOnlyCounts();

        $this->actingAs($b2b)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
        ]))->assertForbidden();

        $this->actingAs($b2c)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
        ]))->assertForbidden();

        // Retail-origin Van/wholesale dispatch must not be made available
        // through a forged query even when the UI tab is changed.
        $this->actingAs($b2c)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'origin' => 'van',
            'store_id' => $b2cStore,
        ]))->assertForbidden();

        $otherStore = $this->retailStore('V2-ORDERS-TABS-OTHER');
        $this->actingAs($b2c)->get(route('admin.operations.orders.index', [
            'channel' => 'b2c',
            'store_id' => $otherStore,
        ]))->assertNotFound();

        $roleless = $this->user('Orders no role', 'v2-orders-tabs-no-role@example.test');
        $this->actingAs($roleless)->get(route('admin.operations.orders.index'))->assertForbidden();

        $inactive = $this->wholesaleActor();
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get(route('admin.operations.orders.index', [
            'channel' => 'b2b',
        ]))->assertForbidden();

        $this->assertSame($before, $this->readOnlyCounts());
    }

    public function test_dispatch_choice_ui_is_channel_scoped_and_server_policy_denies_cross_executor(): void
    {
        $source = file_get_contents(resource_path('views/admin/order-operations.blade.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString(
            "@if(\$row['channel']==='b2c' && (int)\$driver->store_id===(int)\$row['store_id']",
            $source,
        );
        $this->assertStringContainsString("@if(\$row['channel']==='b2b')", $source);
        $this->assertStringContainsString('value="van:{{ $van->id }}"', $source);

        $policy = app(FulfillmentActorPolicy::class);
        $this->assertSame(FulfillmentActorPolicy::VAN, $policy->actorForChannel('b2b'));
        $this->assertSame(FulfillmentActorPolicy::DRIVER, $policy->actorForChannel('b2c'));

        foreach ([
            ['b2b', FulfillmentActorPolicy::DRIVER],
            ['b2c', FulfillmentActorPolicy::VAN],
        ] as [$channel, $wrongActor]) {
            $order = new Order;
            $order->channel = $channel;

            try {
                $policy->assertManualDispatchActor($order, $wrongActor);
                $this->fail('Cross-channel dispatch must be rejected by existing server policy.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('assignee_type', $exception->errors());
            }
        }
    }

    private function readOnlyCounts(): array
    {
        return [
            'orders' => DB::table('orders')->count(),
            'dispatch' => DB::table('order_dispatch_states')->count(),
            'drivers' => DB::table('driver_assignments')->count(),
            'vans' => DB::table('order_van_assignments')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];
    }

    private function wholesaleActor(): User
    {
        $actor = $this->user('Wholesale orders', 'v2-orders-'.uniqid().'@example.test');
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailActor(int $storeId): User
    {
        $actor = $this->user('Retail orders', 'v2-orders-'.uniqid().'@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function user(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
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
