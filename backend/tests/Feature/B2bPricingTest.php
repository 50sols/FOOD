<?php

namespace Tests\Feature;

use App\Domain\Pricing\B2bPriceResolver;
use App\Models\B2bAccount;
use App\Models\B2bCustomer;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_rules_and_active_account_receives_account_price(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->setPricingTiers($storeId, true);
        $admin = $this->userWithRole('B2B_ADMIN', 'pricing-admin@example.test');
        Sanctum::actingAs($admin);

        // Valid Wholesale operators must receive structured 422 input errors
        // for nested page-size selectors, not PHP coercion or a 500 response.
        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            Sanctum::actingAs($admin->fresh());
            $this->getJson('/api/v1/admin/b2b/prices?per_page%5B0%5D=2')
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['per_page']);
        }

        $this->putJson('/api/v1/admin/b2b/prices', ['price_tier_id' => $tierId, 'store_id' => $storeId, 'product_id' => $productId, 'unit_price' => 7.250, 'minimum_quantity' => 5, 'ordering_increment' => 5, 'pack_size' => 12, 'case_size' => 24, 'pack_label' => 'Case 12', 'retail_reference_price' => 9.500])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.price_rule.saved']);

        $buyer = User::query()->create(['name' => 'Buyer', 'email' => 'price-buyer@example.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $buyer->id, 'type' => 'b2b', 'name' => 'Buyer', 'email' => $buyer->email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'price_tier_id' => $tierId, 'company_name' => 'Buyer Co', 'status' => 'active']);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}")
            ->assertOk()
            ->assertJsonPath('data.0.unit_price', 7.25)
            ->assertJsonPath('data.0.minimum_quantity', 5)
            ->assertJsonPath('data.0.available_quantity', 8)
            ->assertJsonPath('data.0.is_available', true)
            ->assertJsonPath('data.0.availability_state', 'AVAILABLE');

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}&q=B2B-BAR-1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $productId);
        $this->getJson("/api/v1/b2b/products?store_id={$storeId}&q=DOES-NOT-EXIST")
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/b2b/products/{$productId}?store_id={$storeId}")
            ->assertOk()
            ->assertJsonPath('id', $productId)
            ->assertJsonPath('store_id', $storeId)
            ->assertJsonPath('sku', 'B2B-P-1')
            ->assertJsonPath('name', 'Wholesale Product')
            ->assertJsonPath('account_price', 7.25)
            ->assertJsonPath('minimum_order_quantity', 5)
            ->assertJsonPath('ordering_increment', 5)
            ->assertJsonPath('pack_size', 12)
            ->assertJsonPath('case_size', 24)
            ->assertJsonPath('pack_label', 'Case 12')
            ->assertJsonPath('retail_reference_price', 9.5)
            ->assertJsonPath('price_tier', 'GOLD')
            ->assertJsonPath('available_quantity', 8)
            ->assertJsonPath('is_available', true)
            ->assertJsonPath('availability_state', 'AVAILABLE')
            ->assertJsonPath('currency', 'EGP');

        DB::table('inventories')
            ->where('product_id', $productId)
            ->update(['reserved_quantity' => 10, 'updated_at' => now()]);

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}")
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.availability_state', 'OUT_OF_STOCK');

        $this->getJson("/api/v1/b2b/products/{$productId}?store_id={$storeId}")
            ->assertOk()
            ->assertJsonPath('available_quantity', 0)
            ->assertJsonPath('is_available', false)
            ->assertJsonPath('availability_state', 'OUT_OF_STOCK');

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'quantity' => 5,
        ])->assertConflict();

        DB::table('inventories')
            ->where('product_id', $productId)
            ->update(['reserved_quantity' => 2, 'updated_at' => now()]);
        $this->postJson('/api/v1/cart/items', ['store_id' => $storeId, 'product_id' => $productId, 'quantity' => 1])->assertConflict();
        $this->postJson('/api/v1/cart/items', ['store_id' => $storeId, 'product_id' => $productId, 'quantity' => 6])->assertConflict();
        $this->postJson('/api/v1/cart/items', ['store_id' => $storeId, 'product_id' => $productId, 'quantity' => 5])
            ->assertCreated()
            ->assertJsonPath('items.0.unit_price_snapshot', 7.25)
            ->assertJsonPath('items.0.minimum_order_quantity', 5)
            ->assertJsonPath('items.0.ordering_increment', 5)
            ->assertJsonPath('items.0.pack_size', 12)
            ->assertJsonPath('subtotal', 36.25);
    }

    public function test_direct_pricing_rejects_dirty_or_unsaved_customer_identity_in_ar_and_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->setPricingTiers($storeId, true);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 7.250,
            'minimum_quantity' => 1,
            'ordering_increment' => 1,
            'pack_size' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $victimUser = User::query()->create([
            'name' => 'Private Tier Owner',
            'email' => 'pricing-identity-owner@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $victimLegacy = Customer::query()->create([
            'user_id' => $victimUser->id,
            'type' => 'b2b',
            'name' => 'Private Tier Owner',
            'email' => $victimUser->email,
        ]);
        $victim = B2bCustomer::query()->firstOrCreate(
            ['legacy_customer_id' => $victimLegacy->id],
            ['user_id' => $victimUser->id, 'name' => 'Private Tier Owner', 'email' => $victimUser->email],
        );
        B2bAccount::query()->create([
            'customer_id' => $victimLegacy->id,
            'b2b_customer_id' => $victim->id,
            'price_tier_id' => $tierId,
            'company_name' => 'Private Tier Company',
            'status' => 'active',
        ]);

        $otherUser = User::query()->create([
            'name' => 'Unrelated Pricing Caller',
            'email' => 'pricing-identity-other@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $other = B2bCustomer::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Unrelated Pricing Caller',
            'email' => $otherUser->email,
        ]);
        $originalOtherId = $other->getRawOriginal('id');
        $this->assertNotSame((int) $victim->id, (int) $originalOtherId);
        $other->setAttribute('id', $victim->id);
        $unsaved = new B2bCustomer(['name' => 'Unpersisted Pricing Caller']);
        $unsaved->setAttribute('id', $victim->id);

        $pricing = app(B2bPriceResolver::class);
        $beforeAudit = DB::table('audit_logs')->count();
        $beforeOrders = DB::table('orders')->count();
        $beforeMovements = DB::table('stock_movements')->count();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(7.25, $pricing->resolve($victim, $storeId, $productId)['price']);

            foreach ([$other, $unsaved] as $untrusted) {
                try {
                    $pricing->resolve($untrusted, $storeId, $productId);
                    $this->fail('An untrusted B2B customer identity must not resolve a foreign tier price.');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }
        }

        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        $this->assertSame($beforeOrders, DB::table('orders')->count());
        $this->assertSame($beforeMovements, DB::table('stock_movements')->count());
        $this->assertSame((int) $originalOtherId, (int) $other->getRawOriginal('id'));
        $this->assertSame(7.25, $pricing->resolve($victim, $storeId, $productId)['price']);
    }

    public function test_revoked_wholesale_price_tier_cannot_price_customer_catalog_or_cart(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->setPricingTiers($storeId, true);
        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 7.25,
            'minimum_quantity' => 1,
            'ordering_increment' => 1,
            'pack_size' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $buyer = User::query()->create([
            'name' => 'Revoked Tier Buyer',
            'email' => 'revoked-tier-buyer@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'Revoked Tier Buyer',
            'email' => $buyer->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->id,
            'price_tier_id' => $tierId,
            'company_name' => 'Revoked Tier Buyer Co',
            'status' => 'active',
        ]);
        Sanctum::actingAs($buyer);

        $list = "/api/v1/b2b/products?store_id={$storeId}";
        $detail = "/api/v1/b2b/products/{$productId}?store_id={$storeId}";
        $this->getJson($list)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_price', 7.25);
        $this->getJson($detail)->assertOk()->assertJsonPath('account_price', 7.25);

        $auditBefore = DB::table('audit_logs')->count();
        $ordersBefore = DB::table('orders')->count();
        $movementsBefore = DB::table('stock_movements')->count();

        // Revocation of the parent tier must invalidate existing active
        // rules without deleting the customer, mutating its account or
        // accidentally falling back to the undiscounted wholesale price.
        DB::table('b2b_price_tiers')->where('id', $tierId)
            ->update(['is_active' => false]);
        $this->getJson($list)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($detail)->assertNotFound();
        $this->postJson('/api/v1/cart/items', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'quantity' => 1,
        ])->assertConflict();

        $this->assertDatabaseHas('b2b_price_rules', [
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'is_active' => true,
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertSame($ordersBefore, DB::table('orders')->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        DB::table('b2b_price_tiers')->where('id', $tierId)
            ->update(['is_active' => true]);
        $this->getJson($list)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_price', 7.25);
        $this->getJson($detail)->assertOk()->assertJsonPath('account_price', 7.25);
    }

    public function test_pricing_tiers_are_off_by_default_and_base_price_is_authoritative(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 4.000,
            'minimum_quantity' => 5,
            'ordering_increment' => 5,
            'pack_size' => 12,
            'case_size' => 24,
            'pack_label' => 'Legacy case',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->userWithRole(
            'B2B_ADMIN',
            'pricing-off-admin@example.test',
        );
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/b2b/prices')
            ->assertConflict();

        $this->putJson('/api/v1/admin/b2b/prices', [
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 3.000,
            'minimum_quantity' => 2,
        ])->assertConflict();

        // The existing deny path is invariant under locale; neither list nor
        // write may mutate rules, audit, orders or inventory while tiers are off.
        $persistedAdminLocale = (string) DB::table('users')
            ->where('id', $admin->getKey())->value('locale');
        $beforeDisabled = [];
        foreach (['b2b_price_rules', 'settings', 'audit_logs', 'orders', 'payments', 'stock_movements'] as $table) {
            $beforeDisabled[$table] = DB::table($table)->count();
        }
        $beforeReserved = (float) DB::table('inventories')->sum('reserved_quantity');
        $mutation = [
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 3.000,
            'minimum_quantity' => 2,
        ];

        foreach ([
            ['ar', 'en-US', 'شرائح تسعير الجملة غير مفعّلة.'],
            ['en', 'ar-KW', 'B2B pricing tiers are disabled.'],
            ['fr', 'ar-KW', 'شرائح تسعير الجملة غير مفعّلة.'],
            ['fr', 'en-US', 'B2B pricing tiers are disabled.'],
        ] as [$actorLocale, $headerLocale, $message]) {
            $admin->forceFill(['locale' => $actorLocale])->save();

            $this->withHeader('Accept-Language', $headerLocale)
                ->getJson('/api/v1/admin/b2b/prices')
                ->assertConflict()
                ->assertJsonPath('message', $message);
            $this->withHeader('Accept-Language', $headerLocale)
                ->putJson('/api/v1/admin/b2b/prices', $mutation)
                ->assertConflict()
                ->assertJsonPath('message', $message);

            foreach ($beforeDisabled as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), $table.' must be unchanged while tiers are disabled');
            }
            $this->assertSame(
                $beforeReserved,
                (float) DB::table('inventories')->sum('reserved_quantity'),
            );
            $this->assertDatabaseHas('b2b_price_rules', [
                'price_tier_id' => $tierId,
                'store_id' => $storeId,
                'product_id' => $productId,
                'unit_price' => 4.000,
            ]);
        }
        $admin->forceFill(['locale' => $persistedAdminLocale])->save();

        $buyer = User::query()->create([
            'name' => 'Base Buyer',
            'email' => 'base-price-buyer@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'Base Buyer',
            'email' => $buyer->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->id,
            'price_tier_id' => $tierId,
            'company_name' => 'Base Buyer Co',
            'status' => 'active',
        ]);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}")
            ->assertOk()
            ->assertJsonPath('pricing_tiers_enabled', false)
            ->assertJsonPath('data.0.unit_price', 10)
            ->assertJsonPath('data.0.account_price', 10)
            ->assertJsonPath('data.0.minimum_quantity', 1)
            ->assertJsonPath('data.0.ordering_increment', 1)
            ->assertJsonPath('data.0.pack_size', 1);

        $this->getJson(
            "/api/v1/b2b/products/{$productId}?store_id={$storeId}",
        )
            ->assertOk()
            ->assertJsonPath('pricing_tiers_enabled', false)
            ->assertJsonPath('account_price', 10)
            ->assertJsonPath('minimum_order_quantity', 1)
            ->assertJsonPath('price_tier', null);

        $this->assertDatabaseHas('b2b_price_rules', [
            'price_tier_id' => $tierId,
            'store_id' => $storeId,
            'product_id' => $productId,
            'unit_price' => 4.000,
            'minimum_quantity' => 5.000,
        ]);
    }

    public function test_unapproved_account_and_unauthorized_admin_are_denied(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();

        DB::table('b2b_price_rules')->insert(['price_tier_id' => $tierId, 'store_id' => $storeId, 'product_id' => $productId, 'unit_price' => 4, 'minimum_quantity' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $finance = $this->userWithRole('FINANCE', 'pricing-finance@example.test');
        Sanctum::actingAs($finance);

        $this->putJson('/api/v1/admin/b2b/prices', ['price_tier_id' => $tierId, 'store_id' => $storeId, 'product_id' => $productId, 'unit_price' => 1, 'minimum_quantity' => 1])->assertForbidden();

        $buyer = User::query()->create(['name' => 'Pending', 'email' => 'pending-price@example.test', 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $buyer->id, 'type' => 'b2b', 'name' => 'Pending', 'email' => $buyer->email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'price_tier_id' => $tierId, 'company_name' => 'Pending Co', 'status' => 'pending']);
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}")->assertForbidden();
        $this->getJson("/api/v1/b2b/products/{$productId}?store_id={$storeId}")->assertForbidden();
    }

    public function test_wholesale_price_rule_api_checks_store_deny_before_pricing_field_validation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $productId, $tierId] = $this->catalog();
        $this->setPricingTiers($storeId, true);
        $admin = $this->userWithRole('B2B_ADMIN', 'pricing-store-deny@example.test');

        $candidate = [
            'store_id' => $storeId,
            'price_tier_id' => $tierId,
            'product_id' => $productId,
            'unit_price' => 7.250,
            'minimum_quantity' => 1,
        ];
        Sanctum::actingAs($admin);
        // An in-scope operator still receives ordinary field validation.
        $this->putJson('/api/v1/admin/b2b/prices', array_replace($candidate, [
            'price_tier_id' => 'invalid-tier',
            'unit_price' => 'not-a-price',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['price_tier_id', 'unit_price']);

        $permissionId = (int) DB::table('permissions')->where('code', 'b2b.pricing.manage')->value('id');
        $this->assertGreaterThan(0, $permissionId);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $admin->id,
            'permission_id' => $permissionId,
            'scope_key' => 'store:'.$storeId,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin->forceFill(['has_permission_overrides' => true])->save();

        $this->assertTrue($admin->fresh()->hasPermission('b2b.pricing.manage'));
        $this->assertFalse($admin->fresh()->hasPermission('b2b.pricing.manage', $storeId));
        $beforeRules = DB::table('b2b_price_rules')->count();
        $beforeAudit = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            Sanctum::actingAs($admin->fresh());

            // A forbidden store must not expose its tier/product/price errors.
            $this->putJson('/api/v1/admin/b2b/prices', array_replace($candidate, [
                'price_tier_id' => 'invalid-tier',
                'product_id' => 'invalid-product',
                'unit_price' => 'not-a-price',
            ]))->assertNotFound();
            $this->putJson('/api/v1/admin/b2b/prices', $candidate)->assertNotFound();
            // A denied store must remain concealed even when nested schedule
            // input would otherwise fail Laravel's array validation.
            $this->putJson('/api/v1/admin/b2b/prices', array_replace($candidate, [
                'quantity_price_breaks' => ['invalid-nested-break'],
                'replace_quantity_price_breaks' => 'not-a-boolean',
            ]))->assertNotFound();

            $this->assertSame($beforeRules, DB::table('b2b_price_rules')->count());
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        }
    }

    public function test_wholesale_app_order_and_stock_only_setting_control_product_feed(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $firstId, $tierId] = $this->catalog();
        $this->setPricingTiers($storeId, false);

        $first = DB::table('products')->where('id', $firstId)->firstOrFail();
        $secondId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $first->catalog_id,
            'unit_id' => $first->unit_id,
            'sku' => 'B2B-SECOND-ORDER',
            'name' => 'Second Wholesale Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $secondId,
            'price' => 12,
            'is_active' => true,
            'app_display_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->where('store_id', $storeId)->where('product_id', $firstId)
            ->update(['app_display_order' => 2]);
        $warehouseId = (int) DB::table('warehouses')->where('store_id', $storeId)->value('id');
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouseId,
            'product_id' => $secondId,
            'quantity' => 3,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $buyer = User::query()->create([
            'name' => 'Order Buyer',
            'email' => 'app-order-buyer@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'Order Buyer',
            'email' => $buyer->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->id,
            'price_tier_id' => $tierId,
            'company_name' => 'Order Buyer Co',
            'status' => 'active',
        ]);
        Sanctum::actingAs($buyer);

        $endpoint = "/api/v1/b2b/products?store_id={$storeId}";
        $this->getJson($endpoint)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondId)
            ->assertJsonPath('data.1.id', $firstId);

        // With filtering off, an active out-of-stock product remains browsable.
        DB::table('inventories')->where('product_id', $firstId)->update(['reserved_quantity' => 10]);
        $this->getJson($endpoint)->assertOk()->assertJsonCount(2, 'data');

        DB::table('storefront_settings')->insert([
            'store_id' => $storeId,
            'theme_code' => 'wholesale_b2b',
            'branding' => json_encode(['show_in_stock_only' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->getJson($endpoint)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondId);

        $this->getJson("/api/v1/wholesale/stores/{$storeId}/storefront")
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $secondId);

        DB::table('storefront_settings')->where('store_id', $storeId)->update([
            'branding' => json_encode(['show_in_stock_only' => false], JSON_THROW_ON_ERROR),
        ]);
        $this->getJson($endpoint)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_wholesale_product_search_treats_name_sku_and_barcode_wildcards_as_literals(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$storeId, $firstId, $tierId] = $this->catalog();
        $this->setPricingTiers($storeId, false);

        $first = DB::table('products')->where('id', $firstId)->firstOrFail();
        DB::table('products')->where('id', $firstId)->update([
            'name' => 'Wholesale %_ Item',
            'sku' => 'B2B-%_SKU',
            'barcode' => 'B2B-%_BAR',
        ]);

        $lookalikeId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $first->catalog_id,
            'unit_id' => $first->unit_id,
            'sku' => 'B2B-ABSKU',
            'barcode' => 'B2B-ABBAR',
            'name' => 'Wholesale AB Item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $escapedId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $first->catalog_id,
            'unit_id' => $first->unit_id,
            'sku' => 'B2B-!_SKU',
            'barcode' => 'B2B-!_BAR',
            'name' => 'Wholesale !_ Item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$lookalikeId, $escapedId] as $id) {
            DB::table('store_products')->insert([
                'store_id' => $storeId,
                'product_id' => $id,
                'price' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $buyer = User::query()->create([
            'name' => 'Literal Wholesale Buyer',
            'email' => 'b2b-literal-buyer@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'Literal Wholesale Buyer',
            'email' => $buyer->email,
        ]);
        B2bAccount::query()->create([
            'customer_id' => $customer->id,
            'price_tier_id' => $tierId,
            'company_name' => 'Literal Buyer Co',
            'status' => 'active',
        ]);
        Sanctum::actingAs($buyer);

        foreach (['Wholesale %_', 'B2B-%_SKU', 'B2B-%_BAR'] as $literal) {
            $this->getJson("/api/v1/b2b/products?store_id={$storeId}&q=".rawurlencode($literal))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $firstId);
        }

        foreach (['Wholesale !_', 'B2B-!_SKU', 'B2B-!_BAR'] as $literal) {
            $this->getJson("/api/v1/b2b/products?store_id={$storeId}&q=".rawurlencode($literal))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $escapedId);
        }

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}&q=".rawurlencode('Wholesale _%'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/v1/b2b/products?store_id={$storeId}&q=Wholesale")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertSame(3, DB::table('products')
            ->whereIn('id', [$firstId, $lookalikeId, $escapedId])
            ->count());
    }

    private function catalog(): array
    {
        $type = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $store = (int) DB::table('stores')->insertGetId(['store_type_id' => $type, 'code' => 'B2B-PRICE', 'name' => 'Wholesale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $catalog = (int) DB::table('catalogs')->insertGetId(['store_id' => $store, 'channel' => 'b2b', 'code' => 'default', 'name' => 'Wholesale Catalog', 'is_active' => true, 'is_migration_quarantine' => false, 'created_at' => now(), 'updated_at' => now()]);
        $unit = (int) DB::table('units')->insertGetId(['code' => 'EA-PRICE', 'name' => 'Each', 'decimal_places' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $product = (int) DB::table('products')->insertGetId(['catalog_id' => $catalog, 'unit_id' => $unit, 'sku' => 'B2B-P-1', 'barcode' => 'B2B-BAR-1', 'name' => 'Wholesale Product', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('store_products')->insert(['store_id' => $store, 'product_id' => $product, 'price' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $warehouse = (int) DB::table('warehouses')->insertGetId(['store_id' => $store, 'code' => 'B2B-WH', 'name' => 'B2B Warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventories')->insert(['warehouse_id' => $warehouse, 'product_id' => $product, 'quantity' => 10, 'reserved_quantity' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $tier = (int) DB::table('b2b_price_tiers')->insertGetId(['code' => 'GOLD', 'name' => 'Gold', 'priority' => 10, 'created_at' => now(), 'updated_at' => now()]);

        return [$store, $product, $tier];
    }

    private function setPricingTiers(int $storeId, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert(
            [
                'store_id' => $storeId,
                'key' => 'b2b.pricing_tiers_enabled',
            ],
            [
                'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::query()->create(['name' => $role, 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
