<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Models\VanVisit;
use App\Services\CustomerDomainResolver;
use App\Services\PlatformCustomerService;
use App\Services\VanRegistryService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthoritativePricingQuoteTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesale;

    private int $retailA;

    private int $retailB;

    private int $wholesaleProduct;

    private int $retailAProduct;

    private int $retailBProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config(['foodex.platform_wholesale_store_code' => 'WHOLESALE-MAIN']);

        $this->wholesale = $this->store('B2B', 'WHOLESALE-MAIN', 'Main Wholesale');
        DB::table('settings')->updateOrInsert(
            [
                'store_id' => $this->wholesale,
                'key' => 'b2b.pricing_tiers_enabled',
            ],
            [
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $this->retailA = $this->store('B2C', 'RETAIL-A', 'Retail A');
        $this->retailB = $this->store('B2C', 'RETAIL-B', 'Retail B');

        $this->wholesaleProduct = $this->product($this->wholesale, 'b2b', 'WHOLESALE-QUOTE', 12.000);
        $this->retailAProduct = $this->product($this->retailA, 'b2c', 'RETAIL-A-QUOTE', 5.000);
        $this->retailBProduct = $this->product($this->retailB, 'b2c', 'RETAIL-B-QUOTE', 9.000);
    }

    public function test_retail_quotes_are_store_authoritative_and_cross_store_products_are_rejected(): void
    {
        $user = $this->platformCustomer('retail-pricing@example.test');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->retailA,
            'product_id' => $this->retailAProduct,
            'quantity' => 2,
        ])->assertCreated();

        $quoteA = $this->postJson('/api/v1/quote', [
            'store_id' => $this->retailA,
        ])->assertOk();

        $quoteA
            ->assertJsonPath('data.channel', 'b2c')
            ->assertJsonPath('data.store_id', $this->retailA)
            ->assertJsonPath('data.items.0.unit_price', 5)
            ->assertJsonPath('data.subtotal', 10)
            ->assertJsonPath('data.currency', 'EGP')
            ->assertJsonPath('data.pricing_source', 'backend_authoritative_quote_v1');

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->retailB,
            'product_id' => $this->retailBProduct,
            'quantity' => 2,
        ])->assertCreated();

        $this->postJson('/api/v1/quote', [
            'store_id' => $this->retailB,
        ])
            ->assertOk()
            ->assertJsonPath('data.store_id', $this->retailB)
            ->assertJsonPath('data.items.0.unit_price', 9)
            ->assertJsonPath('data.subtotal', 18);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->retailA,
            'product_id' => $this->retailBProduct,
            'quantity' => 1,
        ])->assertNotFound();
    }

    public function test_wholesale_quote_uses_assigned_tier_moq_increment_and_pack_rules(): void
    {
        $user = $this->platformCustomer('wholesale-pricing@example.test');
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'unit_price' => 7.250,
            'minimum_quantity' => 5,
            'ordering_increment' => 5,
            'pack_size' => 12,
            'case_size' => 24,
            'pack_label' => 'Case 12',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 5,
        ])->assertCreated();

        $this->postJson('/api/v1/quote', [
            'store_id' => $this->wholesale,
        ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'b2b')
            ->assertJsonPath('data.items.0.unit_price', 7.25)
            ->assertJsonPath('data.items.0.minimum_order_quantity', 5)
            ->assertJsonPath('data.items.0.ordering_increment', 5)
            ->assertJsonPath('data.items.0.pack_size', 12)
            ->assertJsonPath('data.items.0.case_size', 24)
            ->assertJsonPath('data.customer_context.price_tier_id', $tierId)
            ->assertJsonPath('data.customer_context.price_tier_code', 'STANDARD')
            ->assertJsonPath('data.subtotal', 36.25);

        $this->patchJson('/api/v1/cart/items/1', ['quantity' => 6])
            ->assertConflict();
    }

    public function test_wholesale_account_credit_checkout_rejects_total_above_authoritative_purchasing_power(): void
    {
        $user = $this->platformCustomer('wholesale-credit-cap@example.test');
        $resolver = app(CustomerDomainResolver::class);
        $b2b = $resolver->b2b($user);
        $legacyId = (int) DB::table('platform_customers')
            ->where('user_id', $user->id)
            ->value('legacy_customer_id');

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $b2b->id)
            ->update([
                'credit_limit' => 10.000,
                'updated_at' => now(),
            ]);

        $address = Address::query()->create([
            'customer_id' => $legacyId,
            'b2b_customer_id' => $b2b->id,
            'label' => 'Warehouse',
            'line1' => 'Wholesale credit street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 1,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'pricing-credit-cap-000001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->wholesale,
                'address_id' => $address->id,
                'payment_method' => 'account_credit',
            ])
            ->assertConflict();

        $this->assertDatabaseMissing('orders', [
            'b2b_customer_id' => $b2b->id,
            'store_id' => $this->wholesale,
        ]);
    }

    public function test_checkout_reprices_live_price_and_historical_snapshot_does_not_drift(): void
    {
        $user = $this->platformCustomer('live-reprice@example.test');
        $resolver = app(CustomerDomainResolver::class);
        $b2c = $resolver->b2c($user, $this->retailA);
        $legacyId = (int) DB::table('platform_customers')->where('user_id', $user->id)->value('legacy_customer_id');

        $address = Address::query()->create([
            'customer_id' => $legacyId,
            'b2c_customer_id' => $b2c->id,
            'label' => 'Home',
            'recipient_name' => 'Live Reprice Customer',
            'delivery_phone' => '+201111111111',
            'line1' => 'Street 1',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'landmark' => 'Original landmark',
            'delivery_notes' => 'Original delivery note',
            'latitude' => 30.0444200,
            'longitude' => 31.2357120,
            'location_source' => 'map_pin',
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->retailA,
            'product_id' => $this->retailAProduct,
            'quantity' => 2,
        ])->assertCreated()->assertJsonPath('subtotal', 10);

        $this->postJson('/api/v1/quote', ['store_id' => $this->retailA])
            ->assertOk()
            ->assertJsonPath('data.subtotal', 10);

        DB::table('store_products')
            ->where('store_id', $this->retailA)
            ->where('product_id', $this->retailAProduct)
            ->update(['price' => 6.500, 'updated_at' => now()]);

        $checkout = $this->withHeader('Idempotency-Key', 'pricing-reprice-000001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->retailA,
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated()
            ->assertJsonPath('items.0.unit_price', 6.5)
            ->assertJsonPath('subtotal', 13)
            ->assertJsonPath('grand_total', 13);

        $orderId = (int) $checkout->json('id');
        $quoteId = (string) $checkout->json('quote_id');
        $this->assertNotSame('', $quoteId);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame('Street 1', $order->delivery_address_snapshot['line1']);
        $this->assertSame('Original landmark', $order->delivery_address_snapshot['landmark']);
        $this->assertSame(30.04442, (float) $order->delivery_latitude);
        $this->assertSame(31.235712, (float) $order->delivery_longitude);
        $checkout
            ->assertJsonPath('delivery_address.line1', 'Street 1')
            ->assertJsonPath('delivery_address.landmark', 'Original landmark')
            ->assertJsonPath('delivery_address.has_coordinates', true);

        $address->update([
            'line1' => 'Changed after checkout',
            'landmark' => 'Changed landmark',
            'latitude' => 29.0000000,
            'longitude' => 30.0000000,
        ]);
        $address->delete();

        $order->refresh();
        $this->assertSame('Street 1', $order->delivery_address_snapshot['line1']);
        $this->assertSame('Original landmark', $order->delivery_address_snapshot['landmark']);
        $this->assertSame(30.04442, (float) $order->delivery_latitude);
        $this->assertSame(31.235712, (float) $order->delivery_longitude);

        DB::table('store_products')
            ->where('store_id', $this->retailA)
            ->where('product_id', $this->retailAProduct)
            ->update(['price' => 99.000, 'updated_at' => now()]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'quote_id' => $quoteId,
            'subtotal' => 13.000,
            'grand_total' => 13.000,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $this->retailAProduct,
            'unit_price' => 6.500,
            'line_total' => 13.000,
            'currency' => 'EGP',
        ]);
    }

    public function test_wholesale_checkout_replay_is_bound_to_server_lines_not_forged_price_or_credit(): void
    {
        $user = $this->platformCustomer('wholesale-cart-bound@example.test');
        $resolver = app(CustomerDomainResolver::class);
        $b2b = $resolver->b2b($user);
        $legacyId = (int) DB::table('platform_customers')
            ->where('user_id', $user->id)
            ->value('legacy_customer_id');
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $tierId,
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'unit_price' => 7.250,
            'minimum_quantity' => 5,
            'ordering_increment' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $address = Address::query()->create([
            'customer_id' => $legacyId,
            'b2b_customer_id' => $b2b->id,
            'label' => 'Warehouse',
            'line1' => 'Wholesale cart replay street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 5,
        ])->assertCreated();

        $checkout = [
            'store_id' => $this->wholesale,
            'address_id' => $address->id,
            'payment_method' => 'cash_on_delivery',
            'unit_price' => 0.001,
            'price_tier_id' => 99999,
            'credit_limit' => 1000000,
            'discount_total' => 99999,
        ];

        $first = $this->withHeader('Idempotency-Key', 'wholesale-cart-bound-0001')
            ->postJson('/api/v1/checkout', $checkout)
            ->assertCreated()
            ->assertJsonPath('channel', 'b2b')
            ->assertJsonPath('items.0.unit_price', 7.25)
            ->assertJsonPath('price_tier_id', $tierId);

        $orderId = (int) $first->json('id');

        // Lost-response replay with a consumed cart returns the same
        // authoritative wholesale order, never a second invoice/payment.
        $this->withHeader('Idempotency-Key', 'wholesale-cart-bound-0001')
            ->postJson('/api/v1/checkout', $checkout)
            ->assertOk()
            ->assertJsonPath('id', $orderId);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 10,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'wholesale-cart-bound-0001')
            ->postJson('/api/v1/checkout', $checkout)
            ->assertConflict();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 5,
            'unit_price' => 7.25,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'product_id' => $this->wholesaleProduct,
            'quantity' => 10,
        ]);
    }

    public function test_wholesale_credit_checkout_replay_survives_credit_policy_change_but_new_purchase_does_not(): void
    {
        $user = $this->platformCustomer('wholesale-credit-replay@example.test');
        $b2b = app(CustomerDomainResolver::class)->b2b($user);
        $legacyId = (int) DB::table('platform_customers')
            ->where('user_id', $user->id)
            ->value('legacy_customer_id');

        DB::table('b2b_accounts')->where('b2b_customer_id', $b2b->id)->update([
            'credit_limit' => 1000,
            'updated_at' => now(),
        ]);

        $address = Address::query()->create([
            'customer_id' => $legacyId,
            'b2b_customer_id' => $b2b->id,
            'label' => 'Wholesale',
            'line1' => 'Credit replay street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 1,
        ])->assertCreated();

        $payload = [
            'store_id' => $this->wholesale,
            'address_id' => $address->id,
            'payment_method' => 'account_credit',
        ];

        $first = $this->withHeader('Idempotency-Key', 'wholesale-credit-replay-0001')
            ->postJson('/api/v1/checkout', $payload)
            ->assertCreated();
        $orderId = (int) $first->json('id');

        // Credit eligibility can change after a successful invoice. The old
        // key is readback only and must not be checked as a *new* credit sale.
        DB::table('b2b_accounts')->where('b2b_customer_id', $b2b->id)->update([
            'credit_limit' => 0,
            'updated_at' => now(),
        ]);

        $this->withHeader('Idempotency-Key', 'wholesale-credit-replay-0001')
            ->postJson('/api/v1/checkout', $payload)
            ->assertOk()
            ->assertJsonPath('id', $orderId);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoices', 1);

        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 2,
        ])->assertCreated();

        // Changed cart + old key is not a replay and has no side effects.
        $this->withHeader('Idempotency-Key', 'wholesale-credit-replay-0001')
            ->postJson('/api/v1/checkout', $payload)
            ->assertConflict();

        // Fresh credit request remains forbidden after withdrawal.
        $this->withHeader('Idempotency-Key', 'wholesale-credit-replay-0002')
            ->postJson('/api/v1/checkout', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_wholesale_order_keeps_tier_and_price_snapshot_after_tier_changes(): void
    {
        $user = $this->platformCustomer('tier-snapshot@example.test');
        $resolver = app(CustomerDomainResolver::class);
        $b2b = $resolver->b2b($user);
        $legacyId = (int) DB::table('platform_customers')->where('user_id', $user->id)->value('legacy_customer_id');
        $standardTierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');

        DB::table('b2b_price_rules')->insert([
            'price_tier_id' => $standardTierId,
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'unit_price' => 7.250,
            'minimum_quantity' => 5,
            'ordering_increment' => 5,
            'pack_size' => 12,
            'case_size' => 24,
            'pack_label' => 'Case 12',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $address = Address::query()->create([
            'customer_id' => $legacyId,
            'b2b_customer_id' => $b2b->id,
            'label' => 'Warehouse',
            'line1' => 'Wholesale street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 5,
        ])->assertCreated();

        $checkout = $this->withHeader('Idempotency-Key', 'pricing-tier-000001')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->wholesale,
                'address_id' => $address->id,
                'payment_method' => 'cash_on_delivery',
            ])
            ->assertCreated()
            ->assertJsonPath('price_tier_id', $standardTierId)
            ->assertJsonPath('price_tier_code', 'STANDARD')
            ->assertJsonPath('items.0.unit_price', 7.25)
            ->assertJsonPath('items.0.minimum_quantity', 5)
            ->assertJsonPath('items.0.pack_size', 12);

        $orderId = (int) $checkout->json('id');
        $premiumTierId = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'PREMIUM',
            'name' => 'Premium',
            'priority' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $b2b->id)
            ->update(['price_tier_id' => $premiumTierId, 'updated_at' => now()]);
        DB::table('b2b_price_rules')
            ->where('price_tier_id', $standardTierId)
            ->where('store_id', $this->wholesale)
            ->where('product_id', $this->wholesaleProduct)
            ->update(['unit_price' => 1.000, 'updated_at' => now()]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'price_tier_id_snapshot' => $standardTierId,
            'price_tier_code_snapshot' => 'STANDARD',
            'subtotal' => 36.250,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'unit_price' => 7.250,
            'price_tier_id_snapshot' => $standardTierId,
            'price_tier_code_snapshot' => 'STANDARD',
            'minimum_quantity_snapshot' => 5.000,
            'ordering_increment_snapshot' => 5.000,
            'pack_size_snapshot' => 12.000,
            'case_size_snapshot' => 24.000,
        ]);
    }

    public function test_wholesale_checkout_to_authorized_van_partial_receipt_reconciles_customer_invoice_and_statement(): void
    {
        $buyer = $this->platformCustomer('c03-checkout-van-ledger@example.test');
        $customer = app(CustomerDomainResolver::class)->b2b($buyer);
        $legacyId = (int) DB::table('platform_customers')
            ->where('user_id', $buyer->getKey())
            ->value('legacy_customer_id');

        DB::table('b2b_accounts')
            ->where('b2b_customer_id', $customer->getKey())
            ->update(['credit_limit' => 500, 'updated_at' => now()]);

        $address = Address::query()->create([
            'customer_id' => $legacyId,
            'b2b_customer_id' => $customer->getKey(),
            'label' => 'Wholesale',
            'line1' => 'C03 invoice ledger street',
            'city' => 'Cairo',
            'country_code' => 'EG',
            'is_default' => true,
        ]);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/cart/items', [
            'store_id' => $this->wholesale,
            'product_id' => $this->wholesaleProduct,
            'quantity' => 5,
        ])->assertCreated();

        $this->postJson('/api/v1/quote', ['store_id' => $this->wholesale])
            ->assertOk()
            ->assertJsonPath('data.channel', 'b2b')
            ->assertJsonPath('data.store_id', $this->wholesale)
            ->assertJsonPath('data.subtotal', 60);

        $checkout = $this->withHeader('Idempotency-Key', 'c03-b2b-partial-van-ledger-01')
            ->postJson('/api/v1/checkout', [
                'store_id' => $this->wholesale,
                'address_id' => $address->getKey(),
                'payment_method' => 'account_credit',
                'unit_price' => 0.001,
                'credit_limit' => 999999,
                'discount_total' => 999999,
            ])
            ->assertCreated()
            ->assertJsonPath('store_id', $this->wholesale)
            ->assertJsonPath('channel', 'b2b')
            ->assertJsonPath('grand_total', 60)
            ->assertJsonPath('items.0.unit_price', 12);

        $invoiceId = (int) $checkout->json('invoice.id');
        $this->assertGreaterThan(0, $invoiceId);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'order_id' => (int) $checkout->json('id'),
            'store_id' => $this->wholesale,
            'b2b_customer_id' => $customer->getKey(),
            'channel' => 'b2b',
            'total' => 60,
        ]);

        $this->getJson('/api/v1/b2b/invoices/'.$invoiceId.'?store_id='.$this->retailA)
            ->assertNotFound();
        $this->getJson('/api/v1/b2b/invoices/'.$invoiceId.'?store_id='.$this->wholesale)
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 0)
            ->assertJsonPath('data.outstanding_amount', 60);

        $representative = User::factory()->create(['is_active' => true]);
        $representative->roles()->attach(
            Role::query()->where('code', 'VAN_OPERATOR')->firstOrFail(),
        );
        $registry = app(VanRegistryService::class);
        $van = $registry->createVan(['code' => 'C03-CHECKOUT-COLLECT-VAN']);
        $registry->assign($representative, $van, [
            'representative_user_id' => $representative->getKey(),
            'assignment_type' => 'primary',
            'effective_from' => now()->subMinute(),
        ]);
        VanVisit::query()->create([
            'actor_user_id' => $representative->getKey(),
            'customer_type' => 'b2b',
            'customer_id' => $customer->getKey(),
            'store_id' => $this->wholesale,
            'status' => 'started',
            'started_at' => now()->subMinute(),
        ]);

        $vanUrl = '/api/v1/van/customers/b2b/'.$customer->getKey();
        $payment = [
            'store_id' => $this->wholesale,
            'invoice_id' => $invoiceId,
            'amount' => 20.125,
        ];

        Sanctum::actingAs($representative, ['app:driver']);
        $this->getJson($vanUrl.'/collection-context?store_id='.$this->wholesale)
            ->assertForbidden();

        Sanctum::actingAs($representative, ['app:van']);
        $this->getJson($vanUrl.'/collection-context?store_id='.$this->retailA)
            ->assertNotFound();
        $this->getJson($vanUrl.'/collection-context?store_id='.$this->wholesale)
            ->assertOk()
            ->assertJsonPath('data.invoices.0.id', $invoiceId)
            ->assertJsonPath('data.invoices.0.outstanding_amount', 60);

        $receipt = $this->withHeader('Idempotency-Key', 'c03-b2b-van-partial-001')
            ->postJson($vanUrl.'/collect', $payment)
            ->assertCreated()
            ->assertJsonPath('data.invoice_id', $invoiceId)
            ->assertJsonPath('data.remaining_outstanding', 39.875)
            ->assertJsonPath('data.receipt.amount', 20.125)
            ->assertJsonPath('data.receipt.currency', 'EGP');

        $paymentId = (int) $receipt->json('data.receipt.payment_id');
        $this->assertGreaterThan(0, $paymentId);
        $receiptId = (int) $receipt->json('data.receipt.id');

        $this->withHeader('Idempotency-Key', 'c03-b2b-van-partial-001')
            ->postJson($vanUrl.'/collect', $payment)
            ->assertOk()
            ->assertJsonPath('data.receipt.id', $receiptId)
            ->assertJsonPath('data.receipt.payment_id', $paymentId)
            ->assertJsonPath('data.remaining_outstanding', 39.875);

        $this->assertDatabaseCount('collection_transactions', 1);
        $this->assertDatabaseCount('custody_ledger_entries', 1);
        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'invoice_id' => $invoiceId,
            'provider' => 'field_collection',
            'status' => 'paid',
            'amount' => 20.125,
            'currency' => 'EGP',
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/b2b/invoices/'.$invoiceId.'?store_id='.$this->wholesale)
            ->assertOk()
            ->assertJsonPath('data.display_status', 'partially_paid')
            ->assertJsonPath('data.total', 60)
            ->assertJsonPath('data.paid_amount', 20.125)
            ->assertJsonPath('data.outstanding_amount', 39.875)
            ->assertJsonPath('data.payments.0.id', $paymentId)
            ->assertJsonPath('data.payments.0.amount', 20.125);

        $this->getJson('/api/v1/b2b/account-statement?store_id='.$this->wholesale)
            ->assertOk()
            ->assertJsonPath('data.total_debits', 60)
            ->assertJsonPath('data.total_credits', 20.125)
            ->assertJsonPath('data.balance', -39.875)
            ->assertJsonPath('data.closing_balance', -39.875);

        $this->getJson('/api/v1/b2b/account-summary?store_id='.$this->wholesale)
            ->assertOk()
            ->assertJsonPath('data.outstanding_receivable', 39.875);
    }

    private function platformCustomer(string $email): User
    {
        return app(PlatformCustomerService::class)->register([
            'name' => 'Pricing Customer',
            'email' => $email,
            'phone' => '+201000000555',
            'password' => 'Password123!',
            'locale' => 'en',
            'store_id' => $this->retailA,
        ]);
    }

    private function store(string $type, string $code, string $name): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $type)->value('id'),
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $storeId, string $channel, string $sku, float $price): int
    {
        $catalog = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => 'default',
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unit = (int) DB::table('units')->insertGetId([
            'code' => 'EA-'.$sku,
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalog,
            'unit_id' => $unit,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $product,
            'price' => $price,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouse = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-'.$sku,
            'name' => 'Warehouse '.$sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventories')->insert([
            'warehouse_id' => $warehouse,
            'product_id' => $product,
            'quantity' => 100,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $product;
    }
}
