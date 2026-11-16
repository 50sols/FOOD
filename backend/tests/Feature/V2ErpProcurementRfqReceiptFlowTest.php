<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Catalog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

/**
 * P135: one authentic maker -> supplier RFQ -> checker -> PO -> receipt chain.
 * Version/signature evidence is synthetic *only* inside isolated PHPUnit.
 */
final class V2ErpProcurementRfqReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticErpWorkflowEvidence::install();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
        app(ModuleEntitlementManager::class)->set('procurement', EntitlementScope::platform(), true);
    }

    public function test_real_http_maker_rfq_quote_checker_po_and_posted_receipt_keep_verified_supplier_scope_and_money(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        $this->assertNotSame($maker->id, $checker->id);
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'store_id' => $store->id, 'supplier_id' => null,
            'status' => 'submitted', 'created_by' => $maker->id, 'submitted_by' => $maker->id,
        ]);
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'purchase_request_id' => $requestId, 'store_id' => $store->id,
            'created_by' => $maker->id, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_procurement_quotes', [
            'id' => $quoteId, 'rfq_id' => $rfqId, 'supplier_id' => $supplierId, 'status' => 'submitted',
            'currency_code' => 'KWD', 'total_amount' => '8.500',
        ]);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertForbidden();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertForbidden();

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'approved', 'decided_by' => $checker->id,
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();
        $order = DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->first();
        $this->assertNotNull($order);
        $this->assertSame($requestId, (int) $order->purchase_request_id);
        $this->assertSame($supplierId, (int) $order->supplier_id);
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('KWD', $order->currency_code);
        $this->assertSame($quoteId, (int) $order->awarded_quote_id);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id,
            'company_id' => DB::table('enterprise_purchase_requests')->where('id', $requestId)->value('company_id'),
            'status' => 'draft',
        ]);
        $line = DB::table('enterprise_purchase_order_lines')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('4.250', number_format((float) $line->unit_price, 3, '.', ''));
        $this->assertSame('2.000', number_format((float) $line->quantity, 3, '.', ''));

        // An authenticated second award reuses the same order, never charges twice.
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])->assertRedirect();
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->count());

        // A different quote ID is not a replay of the awarded quote.
        // It must be rejected without changing the existing PO or audit trail.
        $awardAuditCount = DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count();
        $this->actingAs($checker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId + 100000,
        ]), ['store_id' => $store->id])->assertRedirect()->assertSessionHasErrors('quote_id');
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'awarded', 'awarded_quote_id' => $quoteId,
        ]);
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'procurement_rfq_id' => $rfqId, 'awarded_quote_id' => $quoteId,
        ]);
        $this->assertSame(1, DB::table('enterprise_purchase_orders')
            ->where('procurement_rfq_id', $rfqId)->count());
        $this->assertSame($awardAuditCount, DB::table('audit_logs')
            ->where('event', 'enterprise.procurement.rfq.awarded')->count());

        $this->actingAs($checker)->post(route('admin.erp.procurement.orders.issue', $order->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $order->id, 'status' => 'issued', 'issued_by' => $checker->id,
        ]);

        [$product, $warehouse] = $this->stockTarget($store);
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.store', $order->id), [
            'store_id' => $store->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 2]],
        ])->assertRedirect();

        $receipt = DB::table('enterprise_goods_receipts')->where('purchase_order_id', $order->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame($supplierId, (int) $receipt->supplier_id);
        $this->assertSame($store->id, (int) $receipt->store_id);
        $this->assertSame($order->company_id, $receipt->company_id);
        $this->assertSame('draft', $receipt->status);
        $this->assertDatabaseHas('enterprise_goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $line->id, 'warehouse_id' => $warehouse->id,
            'received_quantity' => '2.000',
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post(route('admin.erp.procurement.receipts.post', $receipt->id), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('enterprise_goods_receipts', [
            'id' => $receipt->id, 'status' => 'posted', 'posted_by' => $checker->id,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_goods_receipt_line')
            ->whereIn('reference_id', DB::table('enterprise_goods_receipt_lines')
                ->where('goods_receipt_id', $receipt->id)->pluck('id'))->count());
        $this->assertSame(2.0, (float) DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
            ->value('quantity'));
        foreach ([
            'enterprise.purchase_request.approved',
            'enterprise.procurement.rfq.awarded',
            'enterprise.purchase_order.issued',
            'enterprise.goods_receipt.posted',
        ] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event]);
        }
    }

    public function test_expired_or_forged_quote_fails_http_before_award_mutates_request_supplier_or_amount(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_BUYER', 'en');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'ar');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $url = route('admin.erp.procurement.rfqs.award', ['rfq' => $rfqId, 'quote' => $quoteId]);
        $beforePrice = DB::table('enterprise_purchase_request_lines')
            ->where('purchase_request_id', $requestId)->value('estimated_unit_cost');

        DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
            ->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->actingAs($checker)->post($url, ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('quote_id');
        $this->assertUnawarded($requestId, $rfqId, $beforePrice);

        DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
            ->update(['valid_until' => now()->addDays(5)->toDateString(), 'total_amount' => 999999]);
        $this->actingAs($checker)->post($url, ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('quote_id');
        $this->assertUnawarded($requestId, $rfqId, $beforePrice);

        DB::table('enterprise_procurement_quotes')->where('id', $quoteId)
            ->update(['total_amount' => '8.500']);
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.archive', $supplierId), [
            'store_id' => $store->id,
        ])->assertRedirect();
        $this->actingAs($checker)->post($url, ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('supplier_id');
        $this->assertUnawarded($requestId, $rfqId, $beforePrice);
    }

    public function test_self_approval_and_cross_store_supplier_or_negative_amount_never_persist_forged_procurement(): void
    {
        $store = $this->store();
        $maker = $this->actor('PROCUREMENT_MANAGER', 'ar');
        $checker = $this->actor('PROCUREMENT_MANAGER', 'en');
        [$supplierId, $requestId, $rfqId, $quoteId] = $this->quotedRequest($maker, $store);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect()->assertSessionHasErrors('decision');
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'status' => 'submitted',
        ]);

        $this->actingAs($checker)->post(route('admin.erp.procurement.requests.decision', $requestId), [
            'store_id' => $store->id, 'decision' => 'approve',
        ])->assertRedirect();
        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.award', [
            'rfq' => $rfqId, 'quote' => $quoteId,
        ]), ['store_id' => $store->id])
            ->assertRedirect()->assertSessionHasErrors('decision');
        $this->assertUnawarded($requestId, $rfqId, '5.000');

        $other = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'P135-RFQ-OTHER', 'name' => 'Other Wholesale scope', 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($other);
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $other->id, 'scope_type' => 'store',
            'code' => 'P135-RFQ-FOREIGN', 'name_en' => 'Foreign supplier',
            'name_ar' => 'مورد نطاق آخر',
        ])->assertRedirect();
        $foreignSupplierId = (int) DB::table('enterprise_suppliers')
            ->where('code', 'P135-RFQ-FOREIGN')->value('id');
        $this->assertGreaterThan(0, $foreignSupplierId);
        $before = DB::table('enterprise_purchase_requests')->count();
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'supplier_id' => $foreignSupplierId,
            'currency_code' => 'KWD', 'reason' => 'Forged scope',
            'item_code' => 'P135-RFQ-SKU', 'description' => 'Foreign supplier',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => 5,
        ])->assertNotFound();
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id, 'supplier_id' => $supplierId,
            'currency_code' => 'KWD', 'reason' => 'Forged negative amount',
            'item_code' => 'P135-RFQ-SKU', 'description' => 'Negative price',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => -10,
        ])->assertRedirect()->assertSessionHasErrors('estimated_unit_cost');
        $this->assertSame($before, DB::table('enterprise_purchase_requests')->count());
    }

    /** @return array{int,int,int,int} */
    private function quotedRequest(User $maker, Store $store): array
    {
        $code = 'P135-RFQ-SUP-'.$maker->id;
        $this->actingAs($maker)->post(route('admin.erp.procurement.suppliers.store'), [
            'store_id' => $store->id, 'scope_type' => 'store', 'code' => $code,
            'name_en' => 'P135 RFQ supplier', 'name_ar' => 'مورد عرض سعر موثق',
        ])->assertRedirect();
        $supplierId = (int) DB::table('enterprise_suppliers')->where('code', $code)->value('id');
        $this->assertGreaterThan(0, $supplierId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.store'), [
            'store_id' => $store->id,
            'currency_code' => 'KWD', 'reason' => 'P135 source chain '.$maker->id,
            'item_code' => 'P135-RFQ-SKU', 'description' => 'P135 traceable item',
            'quantity' => 2, 'unit' => 'each', 'estimated_unit_cost' => '5.000',
        ])->assertRedirect();
        $requestId = (int) DB::table('enterprise_purchase_requests')
            ->where('reason', 'P135 source chain '.$maker->id)->value('id');
        $this->assertGreaterThan(0, $requestId);
        $this->actingAs($maker)->post(route('admin.erp.procurement.requests.submit', $requestId), [
            'store_id' => $store->id,
        ])->assertRedirect();

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.store'), [
            'store_id' => $store->id, 'purchase_request_id' => $requestId,
            'title' => 'P135 independent supplier quote',
            'response_due_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        ])->assertRedirect();
        $rfqId = (int) DB::table('enterprise_procurement_rfqs')
            ->where('purchase_request_id', $requestId)->value('id');
        $this->assertGreaterThan(0, $rfqId);

        $this->actingAs($maker)->post(route('admin.erp.procurement.rfqs.publish', $rfqId), [
            'store_id' => $store->id, 'supplier_ids' => [$supplierId],
        ])->assertRedirect()->assertSessionHas('procurement_portal_links');
        $links = session('procurement_portal_links');
        $this->assertCount(1, $links);
        $token = basename((string) parse_url((string) $links[0]['url'], PHP_URL_PATH));
        $this->assertSame(64, strlen($token));
        $rfqLineId = (int) DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfqId)->value('id');

        // Forged supplier and total_amount in HTTP body must never override
        // the invitation-bound supplier or service-computed quote total.
        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'supplier_id' => 999999, 'total_amount' => 999999999,
            'valid_until' => now()->addDays(10)->toDateString(),
            'lines' => [[
                'rfq_line_id' => $rfqLineId, 'unit_price' => '4.250',
                'lead_time_days' => 3,
            ]],
        ])->assertRedirect();
        $quoteId = (int) DB::table('enterprise_procurement_quotes')
            ->where('rfq_id', $rfqId)->value('id');
        $this->assertGreaterThan(0, $quoteId);

        return [$supplierId, $requestId, $rfqId, $quoteId];
    }

    private function assertUnawarded(int $requestId, int $rfqId, mixed $beforePrice): void
    {
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfqId, 'status' => 'published',
        ]);
        $this->assertDatabaseHas('enterprise_purchase_requests', [
            'id' => $requestId, 'supplier_id' => null, 'status' => 'approved',
        ]);
        $this->assertSame(
            number_format((float) $beforePrice, 3, '.', ''),
            number_format((float) DB::table('enterprise_purchase_request_lines')
                ->where('purchase_request_id', $requestId)->value('estimated_unit_cost'), 3, '.', ''),
        );
        $this->assertDatabaseMissing('enterprise_purchase_orders', ['procurement_rfq_id' => $rfqId]);
        $this->assertDatabaseCount('enterprise_purchase_orders', 0);
    }

    private function actor(string $code, string $locale): User
    {
        $actor = User::factory()->create(['locale' => $locale, 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail());

        return $actor->fresh();
    }

    private function store(): Store
    {
        $store = Store::query()->findOrFail(app(WholesalePrincipal::class)->storeId());
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    /** @return array{Product,Warehouse} */
    private function stockTarget(Store $store): array
    {
        $sku = 'P135-RFQ-SKU';
        $catalog = Catalog::query()->create([
            'store_id' => $store->id, 'channel' => 'b2c',
            'code' => 'p135-rfq-catalog', 'name' => 'P135 RFQ stock',
            'is_active' => true, 'is_migration_quarantine' => false,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null, 'scope' => 'global', 'scope_key' => 'global',
            'code' => 'P135-RFQ-EACH', 'name' => 'Each', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'catalog_id' => $catalog->id, 'unit_id' => $unitId,
            'sku' => $sku, 'name' => $sku, 'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id, 'code' => 'P135-RFQ-WAREHOUSE',
            'name' => 'P135 RFQ warehouse', 'is_active' => true,
        ]);

        return [$product, $warehouse];
    }
}
