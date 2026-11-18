<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\CartService;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SupplyIntelligenceService;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailWholesaleAccountService;
use App\Services\SmartOrderingAttributionService;
use App\Services\WholesalePrincipal;
use Carbon\CarbonImmutable;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SmartOrderingSupplyIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_completed_orders_drive_estimated_consumption_with_conversion_waste_and_partial_coverage(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 0.5,
            'waste_factor' => 0.10,
            'effective_from' => now()->subDay(),
        ]);

        $unmapped = (string) Str::uuid();
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 2, 'selection' => []],
            ['menu_item_public_id' => $unmapped, 'product_id' => $f['restaurant_product_id'], 'quantity' => 1, 'selection' => []],
        ], now()->subHour());

        $this->smartOrder($f, 'cancelled', 'CANCELLED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 99, 'selection' => []],
        ], now()->subMinutes(45));

        $this->smartOrder($f, 'refunded', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 50, 'selection' => []],
        ], now()->subMinutes(30));

        $result = $supply->consumption(
            $f['site'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(2),
            CarbonImmutable::now('Asia/Kuwait'),
        );

        $this->assertSame(1, $result['total_completed_orders']);
        $this->assertSame(2, $result['total_lines']);
        $this->assertSame(1, $result['mapped_lines']);
        $this->assertSame(0.5, $result['coverage']);
        $this->assertSame('low', $result['confidence']);
        $this->assertCount(1, $result['products']);
        $this->assertEqualsWithDelta(1.1, $result['products'][0]['estimated_usage'], 0.000001);
        $this->assertStringContainsString('not counted or physical inventory', $result['disclaimer']);
    }

    public function test_consumption_respects_branch_scope_and_rejects_foreign_branch_mapping(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);

        $secondaryBranchId = $this->smartBranch($f['site_id'], 'SECONDARY');
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 5, 'selection' => []],
        ], now()->subHours(2));
        $secondary = $f;
        $secondary['branch_id'] = $secondaryBranchId;
        $this->smartOrder($secondary, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 7, 'selection' => []],
        ], now()->subHour());

        $from = CarbonImmutable::now('Asia/Kuwait')->subDay();
        $to = CarbonImmutable::now('Asia/Kuwait');

        $main = $supply->consumption($f['site'], $from, $to, $f['branch_id']);
        $second = $supply->consumption($f['site'], $from, $to, $secondaryBranchId);

        $this->assertSame(1, $main['total_completed_orders']);
        $this->assertEqualsWithDelta(5.0, $main['products'][0]['estimated_usage'], 0.000001);
        $this->assertSame(1, $second['total_completed_orders']);
        $this->assertEqualsWithDelta(7.0, $second['products'][0]['estimated_usage'], 0.000001);

        $foreignStoreId = $this->store('B2C', 'SO-FOREIGN-'.Str::upper(Str::random(6)));
        $foreignSiteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $foreignStoreId,
            'slug' => 'foreign-'.Str::lower(Str::random(10)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignBranchId = $this->smartBranch($foreignSiteId, 'FOREIGN');

        try {
            $supply->createMapping($f['actor'], $f['site'], [
                'menu_item_public_id' => $f['menu_item_public_id'],
                'branch_id' => $foreignBranchId,
                'foodex_product_id' => $f['wholesale_product_id'],
                'quantity_per_item' => 1,
                'waste_factor' => 0,
                'effective_from' => now(),
            ]);
            $this->fail('A foreign-tenant branch must not be accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_reorder_is_deterministic_uses_authoritative_stock_only_when_present_and_blocks_stale_sku(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 28, 'selection' => []],
        ], now()->subHour());

        $trend = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $this->assertSame('consumption_trend_only', $trend['reorder_basis']);
        $this->assertNull($trend['days_of_supply']);
        $this->assertGreaterThan(0, $trend['recommended_quantity']);
        $this->assertSame('ready_for_draft', $trend['state']);

        $supply->setAuthoritativeStock(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            2,
            'physical_count',
            CarbonImmutable::now('Asia/Kuwait'),
        );

        $stockBased = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $this->assertSame('authoritative_stock_plus_consumption', $stockBased['reorder_basis']);
        $this->assertNotNull($stockBased['days_of_supply']);
        $this->assertSame('physical_count', $stockBased['authoritative_stock']['source']);
        $this->assertStringContainsString('authoritative_on_hand', $stockBased['explanation']['formula']);

        DB::table('products')->where('id', $f['wholesale_product_id'])->update(['is_active' => false]);

        $stale = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $this->assertSame('stale_or_inactive_sku', $stale['state']);
        $this->assertFalse($stale['is_executable']);
    }

    public function test_one_click_reorder_only_updates_b2b_draft_cart_revalidates_and_exactly_attributes_later_checkout(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);
        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 28, 'selection' => []],
        ], now()->subHour());

        $beforeOrders = DB::table('orders')->count();
        $recommendation = $supply->recommendations(
            $f['site'],
            $f['branch_id'],
            CarbonImmutable::now('Asia/Kuwait')->subDays(27),
            CarbonImmutable::now('Asia/Kuwait'),
        )['rows'][0];

        $draft = $supply->createDraft(
            $f['actor'],
            $f['site'],
            [$f['wholesale_product_id'] => $recommendation['recommended_quantity']],
            $f['branch_id'],
        );

        $this->assertFalse($draft['order_created']);
        $this->assertSame($beforeOrders, DB::table('orders')->count());
        $this->assertDatabaseHas('carts', [
            'id' => $draft['cart_id'],
            'store_id' => $f['wholesale_store_id'],
            'b2b_customer_id' => $f['b2b_customer_id'],
            'channel' => 'b2b',
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $draft['cart_id'],
            'product_id' => $f['wholesale_product_id'],
            'quantity' => $recommendation['recommended_quantity'],
        ]);
        $this->assertDatabaseHas('smart_ordering_supply_reorder_drafts', [
            'public_id' => $draft['draft_public_id'],
            'cart_id' => $draft['cart_id'],
            'converted_order_id' => null,
        ]);

        $legacyCustomerId = (int) DB::table('b2b_customers')
            ->where('id', $f['b2b_customer_id'])
            ->value('legacy_customer_id');
        $order = Order::query()->create([
            'store_id' => $f['wholesale_store_id'],
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $f['b2b_customer_id'],
            'order_number' => 'SO-SUPPLY-B2B-'.Str::upper(Str::random(8)),
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'EGP',
            'subtotal' => $draft['assisted_value'],
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => $draft['assisted_value'],
        ]);

        app(SmartOrderingAttributionService::class)->attachOrderFromCart(
            Cart::query()->findOrFail($draft['cart_id']),
            $order,
        );

        $this->assertDatabaseHas('smart_ordering_supply_reorder_drafts', [
            'public_id' => $draft['draft_public_id'],
            'converted_order_id' => $order->id,
        ]);
        $commercial = $supply->commercialInsight($f['site']);
        $this->assertSame(1, $commercial['converted_assisted_orders']);
        $this->assertEqualsWithDelta($draft['assisted_value'], $commercial['attributed_foodex_sales_value'], 0.001);
    }

    public function test_cart_snapshot_preserves_menu_item_identity_for_unambiguous_recipe_consumption(): void
    {
        $f = $this->fixture();
        $carts = app(CartService::class);
        $created = $carts->create((string) $f['branch_public_id'], (string) $f['revision_public_id']);
        $cart = $created['cart'];

        $carts->add($cart, [
            'product_id' => $f['restaurant_product_id'],
            'menu_item_public_id' => $f['menu_item_public_id'],
            'quantity' => 1,
            'selection' => [],
        ]);

        $snapshot = $carts->cartSnapshot($cart->fresh());

        $this->assertSame($f['menu_item_public_id'], $snapshot['items'][0]['menu_item_public_id']);
    }

    public function test_recipe_cost_waste_and_stock_count_variance_are_auditable_without_replacing_inventory_truth(): void
    {
        $f = $this->fixture();
        $supply = app(SupplyIntelligenceService::class);

        $supply->createMapping($f['actor'], $f['site'], [
            'menu_item_public_id' => $f['menu_item_public_id'],
            'foodex_product_id' => $f['wholesale_product_id'],
            'quantity_per_item' => 1,
            'waste_factor' => 0,
            'effective_from' => now()->subDay(),
        ]);

        $from = CarbonImmutable::now('Asia/Kuwait')->subHours(3);
        $to = CarbonImmutable::now('Asia/Kuwait')->addMinute();

        $supply->setAuthoritativeStock(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            10,
            'opening_count',
            CarbonImmutable::now('Asia/Kuwait')->subHours(2),
        );

        $this->smartOrder($f, 'delivered', 'COMPLETED', [
            ['menu_item_public_id' => $f['menu_item_public_id'], 'product_id' => $f['restaurant_product_id'], 'quantity' => 2, 'selection' => []],
        ], now()->subHour());

        $wasteId = $supply->recordWaste(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            0.5,
            'prep_loss',
            CarbonImmutable::now('Asia/Kuwait')->subMinutes(30),
        );

        $supply->setAuthoritativeStock(
            $f['actor'],
            $f['site'],
            $f['wholesale_product_id'],
            $f['branch_id'],
            7,
            'closing_count',
            CarbonImmutable::now('Asia/Kuwait'),
        );

        $this->assertDatabaseCount('smart_ordering_supply_stock_snapshots', 2);
        $this->assertDatabaseHas('smart_ordering_supply_waste_events', [
            'public_id' => $wasteId,
            'site_id' => $f['site_id'],
            'branch_id' => $f['branch_id'],
            'quantity' => 0.5,
            'reason' => 'prep_loss',
        ]);

        $costs = $supply->recipeCosts($f['site'], $f['branch_id']);
        $this->assertCount(1, $costs);
        $this->assertTrue($costs[0]['cost_complete']);
        $this->assertEqualsWithDelta(5.0, $costs[0]['estimated_cost'], 0.001);

        $variance = collect($supply->stockVariance($f['site'], $from, $to, $f['branch_id']))
            ->firstWhere('foodex_product_id', $f['wholesale_product_id']);

        $this->assertNotNull($variance);
        $this->assertEqualsWithDelta(10.0, $variance['opening_on_hand'], 0.000001);
        $this->assertEqualsWithDelta(7.0, $variance['closing_on_hand'], 0.000001);
        $this->assertEqualsWithDelta(3.0, $variance['observed_stock_delta'], 0.000001);
        $this->assertEqualsWithDelta(2.0, $variance['theoretical_usage'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $variance['recorded_waste'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $variance['variance'], 0.000001);
        $this->assertSame('snapshot_delta_only', $variance['evidence']);
    }

    public function test_wholesale_supply_product_picker_uses_literal_bilingual_search_with_store_scope(): void
    {
        $f = $this->fixture();
        $storeId = $f['wholesale_store_id'];
        $supply = app(SupplyIntelligenceService::class);

        DB::table('products')->where('id', $f['wholesale_product_id'])
            ->update(['name' => 'مكونات %_! عربية']);

        $skuId = $this->product($storeId, 'b2b', 'FDX-SKU_!MARK', 5, 100);
        $barcodeId = $this->product($storeId, 'b2b', 'FDX-BARCODE-ONE', 5, 100);
        DB::table('products')->where('id', $barcodeId)
            ->update(['name' => 'English supply ingredient', 'barcode' => 'BC%!QR']);

        $lookalikeId = $this->product($storeId, 'b2b', 'FDX-LOOKALIKE', 5, 100);
        DB::table('products')->where('id', $lookalikeId)
            ->update(['name' => 'مكونات ABC! عربية']);

        $slashId = $this->product($storeId, 'b2b', 'FDX-SLASH', 5, 100);
        DB::table('products')->where('id', $slashId)
            ->update(['name' => 'Path '.chr(92).' ingredient']);

        $inactiveId = $this->product($storeId, 'b2b', 'FDX-INACTIVE', 5, 100);
        DB::table('products')->where('id', $inactiveId)
            ->update(['name' => 'مكونات %_! inactive', 'is_active' => false]);

        $foreignId = $this->product(
            $f['restaurant_store_id'],
            'b2c',
            'FDX-FOREIGN-'.Str::upper(Str::random(6)),
            5,
            100,
        );
        DB::table('products')->where('id', $foreignId)
            ->update(['name' => 'مكونات %_! foreign']);

        $counts = [
            'products' => DB::table('products')->count(),
            'store_products' => DB::table('store_products')->count(),
            'orders' => DB::table('orders')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach ([
            ['query' => '%_!', 'expected' => [$f['wholesale_product_id']]],
            ['query' => 'SKU_!', 'expected' => [$skuId]],
            ['query' => 'BC%!', 'expected' => [$barcodeId]],
            ['query' => chr(92), 'expected' => [$slashId]],
            ['query' => 'عربية', 'expected' => [$f['wholesale_product_id'], $lookalikeId]],
            ['query' => 'English', 'expected' => [$barcodeId]],
            ['query' => 'missing%_!', 'expected' => []],
        ] as $case) {
            $actual = array_column($supply->foodexProducts($case['query']), 'id');
            $expected = $case['expected'];
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual, 'Supply search must match literal text inside the authorized B2B catalog.');
        }

        $this->assertCount(1, $supply->foodexProducts('عربية', 1));
        $this->assertSame($counts['products'], DB::table('products')->count());
        $this->assertSame($counts['store_products'], DB::table('store_products')->count());
        $this->assertSame($counts['orders'], DB::table('orders')->count());
        $this->assertSame($counts['audit'], DB::table('audit_logs')->count());
    }

    public function test_supply_workspace_tabs_preserve_scope_locale_and_exclusive_rendering(): void
    {
        $f = $this->fixture();
        $actor = $f['actor'];
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $f['restaurant_store_id'],
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $expected = [
            'overview' => 'coverage_title',
            'mapping' => 'mappings_title',
            'stock' => 'stock_history_title',
            'waste' => 'waste_history',
            'cost' => 'recipe_cost_title',
            'analysis' => 'consumption_title',
            'reorder' => 'reorder_title',
        ];
        $scope = [
            'site' => $f['site_id'],
            'from' => now('Asia/Kuwait')->subDays(7)->toDateString(),
            'to' => now('Asia/Kuwait')->toDateString(),
            'branch_id' => $f['branch_id'],
            'q' => 'FDX-',
        ];

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            foreach ($expected as $tab => $section) {
                $response = $this->actingAs($actor)->get(route('admin.smart-ordering.supply.index', [
                    ...$scope, 'tab' => $tab,
                ]));

                $response->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('class="foodex-tabs si-tabs"', false)
                    ->assertSee('<h2>'.e(__('smart_ordering_supply.'.$section)).'</h2>', false)
                    ->assertSee('name="tab" value="'.$tab.'"', false)
                    ->assertSee('name="branch_id"', false)
                    ->assertSee('name="q" value="FDX-"', false)
                    ->assertSee('aria-current="page"', false);

                if ($tab !== 'stock') {
                    $response->assertDontSee('<h2>'.e(__('smart_ordering_supply.stock_history_title')).'</h2>', false);
                }
                if ($tab !== 'mapping') {
                    $response->assertDontSee('<h2>'.e(__('smart_ordering_supply.mappings_title')).'</h2>', false);
                }
                if ($tab !== 'reorder') {
                    $response->assertDontSee('action="'.route('admin.smart-ordering.supply.draft', $f['site']).'"', false);
                }
            }
        }

        foreach (['bogus', ['stock'], ''] as $invalidTab) {
            $response = $this->actingAs($actor)->get(route('admin.smart-ordering.supply.index', [
                ...$scope, 'tab' => $invalidTab,
            ]));
            $response->assertOk()
                ->assertSee('name="tab" value="overview"', false)
                ->assertDontSee('<h2>'.e(__('smart_ordering_supply.stock_history_title')).'</h2>', false);
        }
    }

    public function test_supply_invalid_mapping_post_returns_to_selected_tab_without_writes(): void
    {
        $f = $this->fixture();
        $actor = $f['actor'];
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $f['restaurant_store_id'],
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = route('admin.smart-ordering.supply.index', [
            'site' => $f['site_id'],
            'tab' => 'mapping',
            'from' => now('Asia/Kuwait')->subDays(7)->toDateString(),
            'to' => now('Asia/Kuwait')->toDateString(),
            'branch_id' => $f['branch_id'],
            'q' => 'FDX-',
        ]);
        $before = DB::table('smart_ordering_supply_mappings')->count();

        $this->actingAs($actor)->from($url)
            ->post(route('admin.smart-ordering.supply.mappings.store', $f['site']), [])
            ->assertRedirect($url)
            ->assertSessionHasErrors([
                'menu_item_public_id', 'foodex_product_id', 'quantity_per_item',
            ]);

        $this->assertSame($before, DB::table('smart_ordering_supply_mappings')->count());
        $this->actingAs($actor)->get($url)
            ->assertOk()
            ->assertSee('name="tab" value="mapping"', false)
            ->assertSee('name="q" value="FDX-"', false)
            ->assertSee('<h2>'.e(__('smart_ordering_supply.mappings_title')).'</h2>', false);
    }

    public function test_supply_tabs_hide_mutations_for_explicit_manage_deny_and_reject_foreign_or_disabled_site(): void
    {
        $f = $this->fixture();
        $actor = $f['actor'];
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $f['restaurant_store_id'],
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = route('admin.smart-ordering.supply.index', ['site' => $f['site_id'], 'tab' => 'mapping']);
        $this->actingAs($actor)->get($url)
            ->assertOk()
            ->assertSee('action="'.route('admin.smart-ordering.supply.mappings.store', $f['site']).'"', false);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => DB::table('permissions')->where('code', 'smart_ordering.manage')->value('id'),
            'scope_key' => 'store:'.$f['restaurant_store_id'],
            'store_id' => $f['restaurant_store_id'],
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->actingAs($actor)->get($url)
            ->assertOk()
            ->assertSee('<h2>'.e(__('smart_ordering_supply.mappings_title')).'</h2>', false)
            ->assertDontSee('action="'.route('admin.smart-ordering.supply.mappings.store', $f['site']).'"', false)
            ->assertDontSee('action="'.route('admin.smart-ordering.supply.mappings.deactivate', [
                $f['site'], 'unreal-mapping',
            ]).'"', false);

        $this->actingAs($actor)->get(route('admin.smart-ordering.supply.index', [
            'site' => $f['site_id'], 'tab' => 'reorder',
        ]))->assertOk()
            ->assertDontSee('action="'.route('admin.smart-ordering.supply.draft', $f['site']).'"', false)
            ->assertDontSee('name="quantities[', false);
        // The canonical tenant-scoped permission check intentionally masks
        // denied store mutations as 404 before request validation or writes.
        $this->actingAs($actor)->post(route('admin.smart-ordering.supply.mappings.store', $f['site']), [])
            ->assertNotFound();

        $otherStore = $this->store('B2C', 'SO-OTHER-'.Str::upper(Str::random(6)));
        $otherActor = User::query()->create([
            'name' => 'Other Restaurant Store Admin',
            'email' => 'other-supply-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $otherActor->id,
            'store_id' => $otherStore,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // A foreign store is deliberately obscured by OperationalTenantScope
        // before the site/entitlement can be enumerated to another retailer.
        $this->actingAs($otherActor)->get($url)->assertNotFound();

        $this->actingAs($actor);
        DB::table('smart_ordering_entitlements')
            ->where('site_id', $f['site_id'])
            ->where('module', 'foodex_supply_intelligence')
            ->update(['enabled' => false, 'updated_at' => now()]);
        $this->get($url)->assertForbidden();
    }

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        $actor = User::query()->create([
            'name' => 'Supply Owner',
            'email' => 'supply-owner-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'password123',
            'locale' => 'en',
            'is_active' => true,
        ]);

        $restaurantStoreId = $this->store('B2C', 'SO-SUPPLY-'.Str::upper(Str::random(6)));
        $restaurantProductId = $this->product(
            $restaurantStoreId,
            'b2c',
            'SO-MEAL-'.Str::upper(Str::random(6)),
            10,
            100,
        );

        $siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $restaurantStoreId,
            'slug' => 'supply-'.Str::lower(Str::random(10)),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = SmartOrderingSite::query()->findOrFail($siteId);

        DB::table('smart_ordering_entitlements')->insert([
            ['site_id' => $siteId, 'branch_id' => null, 'scope_key' => 'site', 'module' => 'ordering', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['site_id' => $siteId, 'branch_id' => null, 'scope_key' => 'site', 'module' => 'foodex_supply_intelligence', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $revisionPublicId = (string) Str::uuid();
        $payload = json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR);
        $revisionId = (int) DB::table('smart_ordering_revisions')->insertGetId([
            'public_id' => $revisionPublicId,
            'site_id' => $siteId,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $branchPublicId = (string) Str::uuid();
        $branchId = (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => $branchPublicId,
            'site_id' => $siteId,
            'code' => 'MAIN',
            'name_en' => 'Main Branch',
            'timezone' => 'Asia/Kuwait',
            'is_default' => true,
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $menuId = (int) DB::table('smart_ordering_menus')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Main',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('smart_ordering_menu_categories')->insertGetId([
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'menu_id' => $menuId,
            'public_id' => (string) Str::uuid(),
            'name_en' => 'Meals',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $menuItemPublicId = (string) Str::uuid();
        DB::table('smart_ordering_menu_items')->insert([
            'revision_id' => $revisionId,
            'site_id' => $siteId,
            'category_id' => $categoryId,
            'public_id' => $menuItemPublicId,
            'source_product_id' => $restaurantProductId,
            'name_en' => 'Burger Meal',
            'is_visible' => true,
            'sold_out' => false,
            'spicy_level' => 0,
            'allow_customer_notes' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $principal = app(WholesalePrincipal::class);
        $wholesaleStoreId = $principal->storeId();
        DB::table('settings')->updateOrInsert(
            ['store_id' => $wholesaleStoreId, 'key' => 'b2b.pricing_tiers_enabled'],
            ['value' => json_encode(false), 'is_secret' => false, 'created_at' => now(), 'updated_at' => now()],
        );
        $wholesaleProductId = $this->product(
            $wholesaleStoreId,
            'b2b',
            'FDX-ING-'.Str::upper(Str::random(6)),
            5,
            500,
        );

        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        if ($tierId <= 0) {
            $tierId = (int) DB::table('b2b_price_tiers')->insertGetId([
                'code' => 'STANDARD',
                'name' => 'Standard',
                'priority' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $customer = app(RetailWholesaleAccountService::class)->ensureForStore(
            Store::query()->findOrFail($restaurantStoreId),
            $tierId,
            $actor,
        );
        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->update(['credit_limit' => 10000, 'status' => 'active', 'updated_at' => now()]);

        return [
            'actor' => $actor,
            'site' => $site,
            'site_id' => $siteId,
            'branch_id' => $branchId,
            'branch_public_id' => $branchPublicId,
            'revision_public_id' => $revisionPublicId,
            'menu_item_public_id' => $menuItemPublicId,
            'restaurant_store_id' => $restaurantStoreId,
            'restaurant_product_id' => $restaurantProductId,
            'wholesale_store_id' => $wholesaleStoreId,
            'wholesale_product_id' => $wholesaleProductId,
            'b2b_customer_id' => (int) $customer->getKey(),
        ];
    }

    private function smartBranch(int $siteId, string $code): int
    {
        return (int) DB::table('smart_ordering_branches')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'code' => $code,
            'name_en' => $code,
            'timezone' => 'Asia/Kuwait',
            'is_default' => false,
            'is_active' => true,
            'accepting_orders' => true,
            'supports_dine_in' => true,
            'supports_pickup' => true,
            'supports_delivery' => true,
            'allow_scheduled' => true,
            'pickup_lead_minutes' => 0,
            'max_schedule_days' => 7,
            'service_charge' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function store(string $type, string $code): int
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($type === 'B2C') {
            $this->enableRestaurantSaasForTestStore($storeId);
        }

        return $storeId;
    }

    private function product(int $storeId, string $channel, string $sku, float $price, float $quantity): int
    {
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'CAT-'.$sku,
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => $price,
            'cost_price' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    /** @param list<array<string,mixed>> $items */
    private function smartOrder(
        array $f,
        string $orderStatus,
        string $lifecycleStatus,
        array $items,
        \DateTimeInterface $at,
    ): int {
        $customerId = (int) DB::table('customers')->insertGetId([
            'type' => 'b2c',
            'name' => 'Smart Ordering Guest',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'store_id' => $f['restaurant_store_id'],
            'customer_id' => $customerId,
            'order_number' => 'SO-'.Str::upper(Str::random(12)),
            'channel' => 'b2c',
            'status' => $orderStatus,
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $contextId = (int) DB::table('smart_ordering_order_contexts')->insertGetId([
            'order_id' => $orderId,
            'public_reference' => (string) Str::uuid(),
            'branch_id' => $f['branch_id'],
            'revision_public_id' => $f['revision_public_id'],
            'fulfillment_mode' => 'pickup',
            'lifecycle_status' => $lifecycleStatus,
            'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'cart_snapshot' => json_encode(['items' => $items], JSON_THROW_ON_ERROR),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('smart_ordering_order_events')->insert([
            'order_context_id' => $contextId,
            'from_status' => null,
            'to_status' => $lifecycleStatus,
            'event_key' => 'test-'.Str::lower(Str::random(10)),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $orderId;
    }
}
