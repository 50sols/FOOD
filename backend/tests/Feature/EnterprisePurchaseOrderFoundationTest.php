<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Catalog;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ProcurementSecondApprover;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class EnterprisePurchaseOrderFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_request_creates_one_scoped_snapshot_purchase_order(): void
    {
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PO-A');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PO-SUP-1',
            'name_en' => 'Purchase Order Supplier',
            'name_ar' => 'مورد أمر الشراء',
        ]);

        $request = app(PurchaseRequestManager::class)->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'Approved PO source',
            'lines' => [
                [
                    'item_code' => 'PO-ITEM-1',
                    'description' => 'Packaging material',
                    'quantity' => 4,
                    'unit' => 'case',
                    'estimated_unit_cost' => 12.500,
                ],
                [
                    'description' => 'Cleaning material',
                    'quantity' => 2,
                    'unit' => 'pack',
                ],
            ],
        ]);
        $requests = app(PurchaseRequestManager::class);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id, 'Approved for PO');

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
        $retry = $orders->createFromApprovedRequest($actor, $context, $request->id);

        $this->assertSame($order->id, $retry->id);
        $this->assertSame('draft', $order->status);
        $this->assertSame($store->id, $order->store_id);
        $this->assertSame($request->request_number, $order->source_request_number);
        $this->assertSame('PO-SUP-1', $order->supplier_code);
        $this->assertSame('Purchase Order Supplier', $order->supplier_name_en);
        $this->assertSame('مورد أمر الشراء', $order->supplier_name_ar);
        $this->assertCount(2, $order->lines);
        $this->assertSame('12.500', $order->lines[0]->unit_price);
        $this->assertNull($order->lines[1]->unit_price);
        $this->assertDatabaseCount('enterprise_purchase_orders', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.purchase_order.created',
            'auditable_id' => $order->id,
        ]);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.created')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    public function test_purchase_order_requires_an_approved_request_with_supplier(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-B');
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'currency_code' => 'KWD',
            'reason' => 'Missing supplier',
            'lines' => [[
                'description' => 'Operational item',
                'quantity' => 1,
                'unit' => 'each',
            ]],
        ]);

        try {
            app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
            $this->fail('A draft purchase request must not create a purchase order.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_request_id', $exception->errors());
        }

        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);

        $this->expectException(ValidationException::class);
        app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
    }

    public function test_direct_purchase_order_issue_and_cancel_reject_forged_actor_id_in_both_locales(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-ACTOR-KEY');
        $other = User::factory()->create(['is_active' => true]);
        $orders = app(PurchaseOrderManager::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $order = $this->approvedOrder($actor, $context, 'PO-KEY-SUP-'.$locale);

            $forged = $other->fresh();
            $this->assertNotNull($forged);
            $forged->forceFill(['id' => $actor->id]);
            $this->assertNotSame($forged->getRawOriginal('id'), $forged->getKey());

            $auditBefore = DB::table('audit_logs')->count();
            try {
                $orders->issue($forged, $context, $order->id);
                $this->fail('Dirty actor key must never issue a purchase order as a second user.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id, 'status' => 'draft', 'issued_by' => null,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            $this->assertSame('issued', $orders->issue($actor, $context, $order->id)->status);
            $auditAfterIssue = DB::table('audit_logs')->count();
            try {
                $orders->cancel($forged, $context, $order->id);
                $this->fail('Dirty actor key must never cancel an issued purchase order.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id, 'status' => 'issued', 'issued_by' => $actor->id,
                'cancelled_by' => null,
            ]);
            $this->assertSame($auditAfterIssue, DB::table('audit_logs')->count());

            $this->assertSame('cancelled', $orders->cancel($actor, $context, $order->id)->status);
            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id, 'status' => 'cancelled',
                'cancelled_by' => $actor->id,
            ]);
        }
    }

    public function test_issue_and_cancel_are_retry_safe_and_audited(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-C');
        $order = $this->approvedOrder($actor, $context, 'PO-C-SUP');

        $orders = app(PurchaseOrderManager::class);
        $issued = $orders->issue($actor, $context, $order->id);
        $issuedAgain = $orders->issue($actor, $context, $order->id);

        $this->assertSame('issued', $issued->status);
        $this->assertSame($issued->issued_at?->toISOString(), $issuedAgain->issued_at?->toISOString());
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.issued')
                ->where('auditable_id', $order->id)
                ->count(),
        );

        $cancelled = $orders->cancel($actor, $context, $order->id);
        $cancelledAgain = $orders->cancel($actor, $context, $order->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(
            $cancelled->cancelled_at?->toISOString(),
            $cancelledAgain->cancelled_at?->toISOString(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.cancelled')
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    public function test_issue_revalidates_active_qualified_supplier_after_draft(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-ELIG');
        $order = $this->approvedOrder($actor, $context, 'PO-ELIG-SUP');
        $orders = app(PurchaseOrderManager::class);

        foreach ([
            ['status' => 'inactive'],
            ['status' => 'active', 'qualification_status' => 'unqualified'],
        ] as $supplierState) {
            DB::table('enterprise_suppliers')
                ->where('id', $order->supplier_id)
                ->update($supplierState);

            try {
                $orders->issue($actor, $context, $order->id);
                $this->fail('An ineligible supplier must not be used to issue a draft PO.');
            } catch (ModelNotFoundException $exception) {
                $this->assertNotNull($exception);
            }

            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id,
                'status' => 'draft',
                'issued_by' => null,
            ]);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.issued')
                ->where('auditable_id', $order->id)
                ->count());
        }
    }

    public function test_issue_rejects_invalid_persisted_order_lines_before_any_issue_audit(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-NEG');
        $order = $this->approvedOrder($actor, $context, 'PO-NEG-SUP');
        $orders = app(PurchaseOrderManager::class);
        $lineId = $order->lines()->firstOrFail()->id;

        foreach ([
            ['quantity' => '-1.000'],
            ['quantity' => '0.000'],
            ['unit_price' => '-0.250'],
        ] as $invalidLine) {
            DB::table('enterprise_purchase_order_lines')->where('id', $lineId)->update([
                'quantity' => '1.000',
                'unit_price' => '1.250',
                ...$invalidLine,
            ]);

            try {
                $orders->issue($actor, $context, $order->id);
                $this->fail('A forged negative/zero PO line must not be issued.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lines', $exception->errors());
            }

            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id,
                'status' => 'draft',
                'issued_by' => null,
            ]);
            $this->assertSame(0, DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.issued')
                ->where('auditable_id', $order->id)
                ->count());
        }

        DB::table('enterprise_purchase_order_lines')->where('id', $lineId)->update([
            'quantity' => '1.000',
            'unit_price' => '1.250',
        ]);
        $this->assertSame('issued', $orders->issue($actor, $context, $order->id)->status);
        $this->assertSame('issued', $orders->issue($actor, $context, $order->id)->status);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.issued')
            ->where('auditable_id', $order->id)
            ->count());
    }

    public function test_posted_receipt_blocks_po_cancellation_without_reversing_stock(): void
    {
        SyntheticErpWorkflowEvidence::install();
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);

        [$actor, $store, $context] = $this->scopedUser([
            ...$this->allPermissions(),
            'procurement.goods_receipts.view',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
        ], 'PO-POSTED');
        app(ModuleEntitlementManager::class)->set(
            'procurement',
            new EntitlementScope(EntitlementScope::STORE, (int) $store->id),
            true,
            actor: $actor,
        );
        $sku = 'PO-POSTED-SKU';
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2c',
            'code' => 'po-posted-catalog',
            'name' => 'PO Receiving Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => 'PO-POSTED-EA',
            'name' => 'Each',
            'name_ar' => 'قطعة',
            'name_en' => 'Each',
            'decimal_places' => 3,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'catalog_id' => $catalog->id,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'store_id' => $store->id,
            'code' => 'PO-POSTED-WH',
            'name' => 'PO Receiving Warehouse',
            'is_active' => true,
        ]);

        $order = $this->approvedOrder($actor, $context, 'PO-POSTED-SUP', $sku);
        $orders = app(PurchaseOrderManager::class);
        $orders->issue($actor, $context, $order->id);
        $receipts = app(GoodsReceiptManager::class);
        $receipt = $receipts->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
            ]],
        ]);
        $this->assertSame('posted', $receipts->post($actor, $context, $receipt->id)->status);

        try {
            $orders->cancel($actor, $context, $order->id);
            $this->fail('A PO with posted inventory receipt must not be cancelled silently.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_orders', ['id' => $order->id, 'status' => 'issued']);
        $this->assertDatabaseHas('enterprise_goods_receipts', ['id' => $receipt->id, 'status' => 'posted']);
        $this->assertSame(1.0, (float) DB::table('inventories')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->value('quantity'));
        $this->assertSame(1, DB::table('stock_movements')
            ->where('type', 'purchase_receipt')
            ->where('store_id', $store->id)
            ->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_order.cancelled')
            ->where('auditable_id', $order->id)
            ->count());

        // Simulate a legacy importer/retry that changed only the GRN header
        // back to draft after the canonical stock movement was committed.
        // Actual inventory provenance must still block PO cancellation.
        DB::table('enterprise_goods_receipts')->where('id', $receipt->id)
            ->update(['status' => 'draft']);
        $this->assertDatabaseHas('enterprise_goods_receipts', [
            'id' => $receipt->id, 'status' => 'draft',
        ]);
        $this->assertNotNull($receipt->lines()->firstOrFail()->stock_movement_id);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            try {
                $orders->cancel($actor, $context, $order->id);
                $this->fail('Committed GRN stock cannot be bypassed by a stale draft receipt header.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id, 'status' => 'issued',
            ]);
            $this->assertSame(1.0, (float) DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'));
            $this->assertSame(1, DB::table('stock_movements')
                ->where('type', 'purchase_receipt')
                ->where('store_id', $store->id)
                ->count());
            $this->assertSame(0, DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.cancelled')
                ->where('auditable_id', $order->id)
                ->count());
        }

        // A migrated GRN may also have lost its line's cached movement FK.
        // The stock movement itself is the durable evidence of receipt and
        // must remain sufficient to forbid PO cancellation independently.
        $receivedLine = $receipt->lines()->firstOrFail();
        DB::table('enterprise_goods_receipt_lines')->where('id', $receivedLine->id)
            ->update(['stock_movement_id' => null]);
        $this->assertNull($receivedLine->fresh()->stock_movement_id);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'enterprise_goods_receipt_line',
            'reference_id' => $receivedLine->id,
            'type' => 'purchase_receipt',
            'store_id' => $store->id,
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            try {
                $orders->cancel($actor, $context, $order->id);
                $this->fail('Existing purchase receipt movement must block PO cancellation even with missing GRN pointer.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
            $this->assertDatabaseHas('enterprise_purchase_orders', [
                'id' => $order->id, 'status' => 'issued',
            ]);
            $this->assertSame(1.0, (float) DB::table('inventories')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->value('quantity'));
            $this->assertSame(1, DB::table('stock_movements')
                ->where('type', 'purchase_receipt')
                ->where('store_id', $store->id)
                ->count());
            $this->assertSame(0, DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_order.cancelled')
                ->where('auditable_id', $order->id)
                ->count());
        }
    }

    public function test_po_number_is_unique_per_company_even_for_imported_duplicate(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-DUP');
        $first = $this->approvedOrder($actor, $context, 'PO-DUP-SUP-1');
        $second = $this->approvedOrder($actor, $context, 'PO-DUP-SUP-2');

        try {
            DB::table('enterprise_purchase_orders')->where('id', $second->id)
                ->update(['order_number' => $first->order_number]);
            $this->fail('An imported PO number must not duplicate another PO in the company.');
        } catch (QueryException $exception) {
            $this->assertNotNull($exception);
        }

        $this->assertDatabaseHas('enterprise_purchase_orders', [
            'id' => $second->id,
            'order_number' => $second->order_number,
            'status' => 'draft',
        ]);
    }

    public function test_purchase_order_number_search_preserves_literal_symbols_and_store_scope(): void
    {
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'PO-LITERAL-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PO-LITERAL-B');
        $target = $this->approvedOrder($actorA, $contextA, 'PO-LITERAL-SUP-A');
        $decoy = $this->approvedOrder($actorA, $contextA, 'PO-LITERAL-SUP-DEC');
        $foreign = $this->approvedOrder($actorB, $contextB, 'PO-LITERAL-SUP-B');

        DB::table('enterprise_purchase_orders')->where('id', $target->id)->update([
            'order_number' => 'PO_%!\\-A',
        ]);
        DB::table('enterprise_purchase_orders')->where('id', $decoy->id)->update([
            'order_number' => 'PO-LOOKALIKE',
        ]);
        DB::table('enterprise_purchase_orders')->where('id', $foreign->id)->update([
            'order_number' => 'PO_%!\\-F',
        ]);

        $deniedRole = Role::query()->create([
            'code' => 'ROLE_PO_LITERAL_DENIED',
            'name' => 'PO no view',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $deniedUser = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $deniedUser->id,
            'store_id' => $storeA->id,
            'role_id' => $deniedRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deniedContext = app(TenantContextResolver::class)->retail($deniedUser, $storeA->id);

        $orderCount = DB::table('enterprise_purchase_orders')->count();
        $auditCount = DB::table('audit_logs')->count();
        $orders = app(PurchaseOrderManager::class);
        $find = static function (User $actor, ResolvedTenantContext $context, string $q, array $filters = []) use ($orders): array {
            return array_map(
                static fn ($order): string => (string) $order->order_number,
                $orders->paginate($actor, $context, ['q' => $q, ...$filters])->items(),
            );
        };

        $this->assertSame(['PO_%!\\-A'], $find($actorA, $contextA, 'PO_'));
        $this->assertSame(['PO_%!\\-A'], $find($actorA, $contextA, 'PO_%'));
        $this->assertSame(['PO_%!\\-A'], $find($actorA, $contextA, 'PO_%!'));
        $this->assertSame(['PO_%!\\-A'], $find($actorA, $contextA, 'PO_%!\\'));
        $this->assertSame(['PO_%!\\-A'], $find($actorA, $contextA, 'PO_%!\\-A'));
        $this->assertSame(['PO-LOOKALIKE'], $find($actorA, $contextA, 'PO-'));
        $this->assertSame([], $find($actorA, $contextA, '%'));
        $this->assertSame([], $find($actorA, $contextA, '_'));
        $this->assertSame([], $find($actorA, $contextA, 'PO_%!\\-F'));
        $this->assertSame(['PO_%!\\-F'], $find($actorB, $contextB, 'PO_%!\\'));
        $this->assertSame(['PO_%!\\-A'], $find($actorA, $contextA, 'PO_%', ['status' => 'draft']));
        $this->assertSame([], $find($actorA, $contextA, 'PO_%', ['status' => 'issued']));

        try {
            $orders->paginate($deniedUser, $deniedContext, ['q' => 'PO_%!']);
            $this->fail('A user without procurement.purchase_orders.view must not access the PO list.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($orderCount, DB::table('enterprise_purchase_orders')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_purchase_order_reads_are_bounded_and_cross_store_isolated(): void
    {
        [$actorA, , $contextA] = $this->scopedUser($this->allPermissions(), 'PO-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PO-D-B');
        $order = $this->approvedOrder($actorA, $contextA, 'PO-D-SUP');

        $orders = app(PurchaseOrderManager::class);
        $page = $orders->paginate($actorA, $contextA, ['status' => 'draft'], 500);

        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $orders->paginate($actorB, $contextB)->total());

        $this->expectException(ModelNotFoundException::class);
        $orders->detail($actorB, $contextB, $order->id);
    }

    public function test_purchase_order_service_refuses_persisted_permission_denies_on_stale_user_in_ar_and_en(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-STALE-DENY');
        $orders = app(PurchaseOrderManager::class);
        $this->assertFalse((bool) $actor->has_permission_overrides);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $order = $this->approvedOrder($actor, $context, 'PO-DENY-SUP-'.strtoupper($locale));
            $this->assertSame($order->id, $orders->detail($actor, $context, $order->id)->id);

            foreach (['procurement.purchase_orders.view', 'procurement.purchase_orders.issue'] as $permissionCode) {
                $permissionId = (int) Permission::query()->where('code', $permissionCode)->value('id');
                $this->assertGreaterThan(0, $permissionId);
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $actor->id,
                    'permission_id' => $permissionId,
                    'scope_key' => 'global',
                    'store_id' => null,
                    'effect' => 'deny',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => true]);
                // The caller deliberately retains the pre-DENY user object.
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                $auditBefore = DB::table('audit_logs')->count();
                $linesBefore = DB::table('enterprise_purchase_order_lines')->count();

                $actions = $permissionCode === 'procurement.purchase_orders.view'
                    ? [
                        fn () => $orders->detail($actor, $context, $order->id),
                        fn () => $orders->paginate($actor, $context),
                    ]
                    : [fn () => $orders->issue($actor, $context, $order->id)];

                foreach ($actions as $action) {
                    try {
                        $action();
                        $this->fail('Persisted purchase-order DENY must block stale direct-service access.');
                    } catch (HttpException $exception) {
                        $this->assertSame(403, $exception->getStatusCode());
                    }
                }

                $this->assertDatabaseHas('enterprise_purchase_orders', [
                    'id' => $order->id,
                    'status' => 'draft',
                ]);
                $this->assertSame($linesBefore, DB::table('enterprise_purchase_order_lines')->count());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
                $this->assertSame($order->id, $orders->detail($actor, $context, $order->id)->id);
            }

            // Restored permissions allow the real authorized draft transition.
            $this->assertSame('issued', $orders->issue($actor, $context, $order->id)->status);
        }
    }

    public function test_purchase_order_detail_denies_stale_supplier_and_financial_lines_after_store_transfer_in_ar_and_en(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-DETAIL-LIVE');
        $foreignStore = $this->createRetailStore('PO-DETAIL-FOREIGN');
        $orders = app(PurchaseOrderManager::class);

        $armed = false;
        $moved = false;
        $targetOrderId = null;
        DB::listen(static function ($query) use (&$armed, &$moved, &$targetOrderId, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $moved || $targetOrderId === null
                || ! str_contains($sql, 'enterprise_purchase_orders')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'join')) {
                return;
            }

            // Transfer just after the authorized header SELECT, before
            // Eloquent loads supplier, request and priced order lines.
            $armed = false;
            $moved = true;
            DB::table('enterprise_purchase_orders')
                ->where('id', $targetOrderId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $order = $this->approvedOrder($actor, $context, 'PO-DETAIL-SUP-'.strtoupper($locale));
            $visible = $orders->detail($actor, $context, $order->id);
            $this->assertSame($order->id, $visible->id);
            $this->assertCount(1, $visible->lines);

            $targetOrderId = (int) $order->id;
            $auditBefore = DB::table('audit_logs')->count();
            $linesBefore = DB::table('enterprise_purchase_order_lines')->count();
            $armed = true;
            $moved = false;
            try {
                $orders->detail($actor, $context, $order->id);
                $this->fail('Former store must not read a transferred order header, supplier, or prices.');
            } catch (ModelNotFoundException) {
                $this->assertTrue($moved, 'Transfer must occur after the scoped first SELECT.');
            }

            $this->assertSame((int) $foreignStore->id, (int) DB::table('enterprise_purchase_orders')
                ->where('id', $order->id)->value('store_id'));
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($linesBefore, DB::table('enterprise_purchase_order_lines')->count());

            try {
                $orders->detail($actor, $context, $order->id);
                $this->fail('Repeated former-store detail must be denied.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_purchase_order_pagination_rechecks_permission_after_rows_when_revoked_midquery_in_ar_and_en(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-MIDQUERY-DENY');
        $orders = app(PurchaseOrderManager::class);
        $permissionId = Permission::query()
            ->where('code', 'procurement.purchase_orders.view')
            ->firstOrFail()->id;

        $armed = false;
        $injected = false;
        DB::listen(static function ($query) use ($actor, $permissionId, &$armed, &$injected): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_purchase_orders')
                || str_contains($sql, 'count(')) {
                return;
            }

            // Simulate a committed revoke after the scoped page SELECT, before
            // the eager supplier read and the service response. This is a
            // deterministic same-connection interleaving, not a two-DB race.
            $armed = false;
            $injected = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $order = $this->approvedOrder($actor, $context, 'PO-MIDQUERY-'.strtoupper($locale));
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertContains(
                $order->id,
                array_map(static fn ($item): int => (int) $item->id, $orders->paginate($actor, $context)->items()),
            );

            $ordersBefore = DB::table('enterprise_purchase_orders')->count();
            $linesBefore = DB::table('enterprise_purchase_order_lines')->count();
            $auditBefore = DB::table('audit_logs')->count();
            $injected = false;
            $armed = true;

            try {
                $orders->paginate($actor, $context);
                $this->fail('A mid-query persisted DENY must block the already-selected supplier and PO rows.');
            } catch (HttpException $exception) {
                $this->assertTrue($injected, 'Revoke must happen after the scoped purchase-order page SELECT.');
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            try {
                $orders->paginate($actor, $context);
                $this->fail('A second request with a revoked view permission must also be denied.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertSame($ordersBefore, DB::table('enterprise_purchase_orders')->count());
            $this->assertSame($linesBefore, DB::table('enterprise_purchase_order_lines')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)
                ->where('permission_id', $permissionId)
                ->delete();
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => false]);
            $this->assertContains(
                $order->id,
                array_map(static fn ($item): int => (int) $item->id, $orders->paginate($actor, $context)->items()),
            );
        }
    }

    public function test_purchase_order_detail_rechecks_view_permission_after_priced_lines_when_revoked_midquery_in_ar_and_en(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-DETAIL-MIDQUERY-DENY');
        $orders = app(PurchaseOrderManager::class);
        $permissionId = Permission::query()
            ->where('code', 'procurement.purchase_orders.view')
            ->firstOrFail()->id;

        $armed = false;
        $injected = false;
        DB::listen(static function ($query) use ($actor, $permissionId, &$armed, &$injected): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_purchase_order_lines')) {
                return;
            }

            // Revoke after the authorized order header and priced-line SELECT.
            // This is a deterministic same-connection interleaving, not proof
            // of a separate committed MySQL transaction or serializability.
            $armed = false;
            $injected = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $order = $this->approvedOrder($actor, $context, 'PO-DETAIL-DENY-'.strtoupper($locale));
            $visible = $orders->detail($actor, $context, $order->id);
            $this->assertSame($order->id, $visible->id);
            $this->assertCount(1, $visible->lines);
            $this->assertFalse((bool) $actor->has_permission_overrides);

            $orderCount = DB::table('enterprise_purchase_orders')->count();
            $linesCount = DB::table('enterprise_purchase_order_lines')->count();
            $auditCount = DB::table('audit_logs')->count();
            $armed = true;
            $injected = false;

            try {
                $orders->detail($actor, $context, $order->id);
                $this->fail('Revocation during priced-line hydration must block the PO detail response.');
            } catch (HttpException $exception) {
                $this->assertTrue($injected, 'Persisted DENY must be injected after loading priced PO lines.');
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            try {
                $orders->detail($actor, $context, $order->id);
                $this->fail('A repeated PO detail read after effective DENY must also fail.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }

            $this->assertSame($orderCount, DB::table('enterprise_purchase_orders')->count());
            $this->assertSame($linesCount, DB::table('enterprise_purchase_order_lines')->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());

            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)
                ->where('permission_id', $permissionId)
                ->delete();
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => false]);
            $restored = $orders->detail($actor, $context, $order->id);
            $this->assertSame($order->id, $restored->id);
            $this->assertCount(1, $restored->lines);
        }
    }

    public function test_purchase_order_mutations_reauthorize_after_transactional_reads_in_ar_and_en(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PO-WRITE-MIDQUERY');
        $orders = app(PurchaseOrderManager::class);
        $requests = app(PurchaseRequestManager::class);
        $permissionIds = [
            'manage' => (int) Permission::query()->where('code', 'procurement.purchase_orders.manage')->value('id'),
            'issue' => (int) Permission::query()->where('code', 'procurement.purchase_orders.issue')->value('id'),
        ];
        $this->assertGreaterThan(0, $permissionIds['manage']);
        $this->assertGreaterThan(0, $permissionIds['issue']);

        $armed = false;
        $injected = false;
        $table = '';
        $permissionId = 0;
        DB::listen(static function ($query) use ($actor, &$armed, &$injected, &$table, &$permissionId): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected || ! str_contains($sql, 'select')
                || ! str_contains($sql, $table)) {
                return;
            }

            // Interleave a same-connection persisted DENY after an actual
            // transactional prewrite SELECT. The rejected transaction must
            // roll back the injected DENY along with every business change.
            $armed = false;
            $injected = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = app(SupplierDirectory::class)->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'PO-WRITE-'.strtoupper($locale),
                'name_en' => 'PO transaction authorization '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'supplier_id' => $supplier->id,
                'currency_code' => 'KWD',
                'reason' => 'Transaction authorization regression',
                'lines' => [[
                    'description' => 'Bounded transactional item',
                    'quantity' => 1,
                    'unit' => 'each',
                    'estimated_unit_cost' => 1.250,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $order = null;

            foreach ([
                ['create', 'manage', 'enterprise_purchase_request_lines', null, 'draft'],
                ['issue', 'issue', 'enterprise_purchase_order_lines', 'draft', 'issued'],
                ['cancel', 'manage', 'enterprise_goods_receipts', 'issued', 'cancelled'],
            ] as [$operation, $permission, $readTable, $beforeStatus, $afterStatus]) {
                $permissionId = $permissionIds[$permission];
                $table = $readTable;
                // Do not arm before baseline counts: the line-count SELECT
                // itself matches the issued-PO race trigger, outside the
                // transaction that is supposed to roll back the injected DENY.
                $armed = false;
                $injected = false;
                $run = static function () use ($orders, $actor, $context, $request, &$order, $operation) {
                    return match ($operation) {
                        'create' => $orders->createFromApprovedRequest($actor, $context, $request->id),
                        'issue' => $orders->issue($actor, $context, $order->id),
                        'cancel' => $orders->cancel($actor, $context, $order->id),
                    };
                };
                $countBefore = DB::table('enterprise_purchase_orders')->count();
                $auditBefore = DB::table('audit_logs')->count();
                $linesBefore = DB::table('enterprise_purchase_order_lines')->count();

                // The injected permission revoke must occur during the real
                // create/issue/cancel transaction, never during test setup.
                $armed = true;
                try {
                    $run();
                    $this->fail('A mid-transaction permission revoke must stop '.$operation.' before mutation.');
                } catch (HttpException $exception) {
                    $this->assertTrue($injected, $operation.' must execute its prewrite query before DENY.');
                    $this->assertSame(403, $exception->getStatusCode());
                }

                $this->assertSame($countBefore, DB::table('enterprise_purchase_orders')->count());
                $this->assertSame($linesBefore, DB::table('enterprise_purchase_order_lines')->count());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                if ($order !== null) {
                    $this->assertDatabaseHas('enterprise_purchase_orders', [
                        'id' => $order->id,
                        'status' => $beforeStatus,
                    ]);
                } else {
                    $this->assertDatabaseMissing('enterprise_purchase_orders', [
                        'purchase_request_id' => $request->id,
                    ]);
                }
                $this->assertDatabaseMissing('user_permission_overrides', [
                    'user_id' => $actor->id,
                    'permission_id' => $permissionId,
                    'scope_key' => 'global',
                ]);
                $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);

                // Persist the DENY outside the refused transaction: a repeated
                // direct-service operation must reject even with stale actor.
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $actor->id,
                    'permission_id' => $permissionId,
                    'scope_key' => 'global',
                    'store_id' => null,
                    'effect' => 'deny',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => true]);
                try {
                    $run();
                    $this->fail('Repeated '.$operation.' with committed DENY must be forbidden.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $result = $run();
                $this->assertSame($afterStatus, $result->status);
                if ($operation === 'create') {
                    $order = $result;
                    $this->assertSame($request->id, $order->purchase_request_id);
                }
            }
        }
    }

    private function approvedOrder(
        User $actor,
        ResolvedTenantContext $context,
        string $supplierCode,
        ?string $itemCode = null,
    ) {
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => $supplierCode,
        ]);
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'PO lifecycle',
            'lines' => [[
                'item_code' => $itemCode,
                'description' => 'Operational item',
                'quantity' => 1,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);

        return app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        return [
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.manage',
            'procurement.purchase_orders.issue',
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.str_replace('-', '_', $storeCode),
            'name' => $storeCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore($storeCode);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
