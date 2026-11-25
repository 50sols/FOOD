<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\GoodsReceiptManager;
use App\Enterprise\Procurement\ProcurementCompletionManager;
use App\Enterprise\Procurement\PurchaseInvoiceHandoffManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\PurchaseReturnManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Models\Catalog;
use App\Models\EnterpriseGoodsReceipt;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ProcurementSecondApprover;
use Tests\TestCase;

final class EnterprisePurchaseInvoiceHandoffFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_procurement_performs_no_purchase_invoice_query_work(): void
    {
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PINV-OFF');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        try {
            app(PurchaseInvoiceHandoffManager::class)->paginate($actor, $context);
            $this->fail('Disabled Procurement must reject purchase invoice reads.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertFalse(
            collect($queries)->contains(
                static fn (string $sql): bool => str_contains($sql, 'enterprise_purchase_invoice'),
            ),
        );
    }

    public function test_purchase_invoice_late_view_deny_blocks_scoped_list_and_detail_in_both_locales(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-READ-RACE');
        [, $warehouse] = $this->stockTarget($store, 'PINV-READ-RACE-SKU');
        $receipt = $this->postedReceipt(
            $actor, $context, $warehouse, 'PINV-READ-RACE-SUP', 'PINV-READ-RACE-SKU', 2,
        );
        $manager = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'PINV-READ-RACE-001',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 1,
            ]],
        ]);
        $permissionId = (int) Permission::query()
            ->where('code', 'procurement.purchase_invoices.view')->value('id');
        $this->assertGreaterThan(0, $permissionId);
        $this->assertFalse((bool) $actor->has_permission_overrides);

        $armed = false;
        $injected = false;
        DB::listen(static function ($query) use (&$armed, &$injected, $actor, $permissionId): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected
                || ! str_contains($sql, 'enterprise_purchase_invoices')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'join')) {
                return;
            }

            // After the authorized invoice SQL: deterministic same-connection
            // race injection, not independent two-session MySQL certification.
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
            foreach (['list', 'detail'] as $read) {
                $readInvoice = $read === 'list'
                    ? fn () => $manager->paginate($actor, $context, ['status' => 'draft'])
                    : fn () => $manager->detail($actor, $context, $invoice->id);
                $this->assertNotNull($readInvoice());

                $auditBefore = DB::table('audit_logs')->count();
                $linesBefore = DB::table('enterprise_purchase_invoice_lines')->count();
                $outboxBefore = DB::table('enterprise_outbox_messages')->count();
                $injected = false;
                $armed = true;
                foreach ([1, 2] as $attempt) {
                    try {
                        $readInvoice();
                        $this->fail('An invoice read after persisted view DENY must fail closed.');
                    } catch (HttpExceptionInterface $exception) {
                        $this->assertSame(403, $exception->getStatusCode());
                    }
                    if ($attempt === 1) {
                        $this->assertTrue($injected, 'The DENY must occur during a scoped invoice SELECT.');
                        $this->assertFalse((bool) $actor->has_permission_overrides);
                        $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                    }
                }
                $this->assertDatabaseHas('enterprise_purchase_invoices', [
                    'id' => $invoice->id, 'status' => 'draft', 'outbox_event_id' => null,
                ]);
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame($linesBefore, DB::table('enterprise_purchase_invoice_lines')->count());
                $this->assertSame($outboxBefore, DB::table('enterprise_outbox_messages')->count());

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertSame($invoice->id, $manager->detail($actor, $context, $invoice->id)->id);
            }
        }
    }

    public function test_invoice_direct_service_denies_forged_actor_before_finance_writes_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$maker, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-ACTOR');
        [, $warehouse] = $this->stockTarget($store, 'PINV-ACTOR-SKU');
        $receipt = $this->postedReceipt(
            $maker, $context, $warehouse, 'PINV-ACTOR-SUP', 'PINV-ACTOR-SKU', 5,
        );
        $other = User::factory()->create(['is_active' => true]);
        $manager = app(PurchaseInvoiceHandoffManager::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $forged = $other->fresh();
            $this->assertNotNull($forged);
            $forged->forceFill(['id' => $maker->id]);
            $this->assertNotSame($forged->getRawOriginal('id'), $forged->getKey());
            $reference = 'PINV-FORGED-'.strtoupper($locale);
            $data = [
                'supplier_invoice_number' => $reference,
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ];
            $auditBefore = DB::table('audit_logs')->count();

            try {
                $manager->createFromPostedReceipt($forged, $context, $receipt->id, $data);
                $this->fail('Dirty actor ID must not create a purchase invoice.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertDatabaseMissing('enterprise_purchase_invoices', [
                'supplier_invoice_number' => $reference,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            $invoice = $manager->createFromPostedReceipt($maker, $context, $receipt->id, $data);
            $auditAfterCreate = DB::table('audit_logs')->count();

            try {
                $manager->handoff($forged, $context, $invoice->id);
                $this->fail('Dirty actor ID must not hand off a purchase invoice.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id, 'status' => 'draft',
            ]);
            $this->assertSame($auditAfterCreate, DB::table('audit_logs')->count());

            $posted = $manager->handoff($maker, $context, $invoice->id);
            $this->assertSame('handed_off', $posted->status);
            $this->assertNotNull($posted->outbox_event_id);
        }
    }

    public function test_invoice_direct_service_honors_persisted_denies_with_stale_actor_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-STALE-DENY');
        [, $warehouse] = $this->stockTarget($store, 'PINV-STALE-SKU');
        $receipt = $this->postedReceipt(
            $actor, $context, $warehouse, 'PINV-STALE-SUP', 'PINV-STALE-SKU', 5,
        );
        $manager = app(PurchaseInvoiceHandoffManager::class);
        $this->assertFalse((bool) $actor->has_permission_overrides);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'PINV-STALE-'.strtoupper($locale),
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ]);
            $this->assertSame($invoice->id, $manager->detail($actor, $context, $invoice->id)->id);

            foreach ([
                'procurement.purchase_invoices.view',
                'procurement.purchase_invoices.manage',
                'procurement.purchase_invoices.handoff',
            ] as $permissionCode) {
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

                // Simulate a legitimate long-lived direct service caller
                // loaded before an independent admin revoked this permission.
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                $auditBefore = DB::table('audit_logs')->count();
                $lineCount = DB::table('enterprise_purchase_invoice_lines')->count();

                $operations = match ($permissionCode) {
                    'procurement.purchase_invoices.view' => [
                        fn () => $manager->detail($actor, $context, $invoice->id),
                        fn () => $manager->paginate($actor, $context),
                    ],
                    'procurement.purchase_invoices.manage' => [
                        fn () => $manager->cancel($actor, $context, $invoice->id),
                    ],
                    default => [
                        fn () => $manager->handoff($actor, $context, $invoice->id),
                    ],
                };
                foreach ($operations as $operation) {
                    try {
                        $operation();
                        $this->fail('A persisted invoice permission DENY must block stale service actors.');
                    } catch (HttpExceptionInterface $exception) {
                        $this->assertSame(403, $exception->getStatusCode());
                    }
                }

                $this->assertDatabaseHas('enterprise_purchase_invoices', [
                    'id' => $invoice->id, 'status' => 'draft', 'outbox_event_id' => null,
                ]);
                $this->assertSame($lineCount, DB::table('enterprise_purchase_invoice_lines')->count());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
                $this->assertSame($invoice->id, $manager->detail($actor, $context, $invoice->id)->id);
            }

            $this->assertSame('handed_off', $manager->handoff($actor, $context, $invoice->id)->status);
        }
    }

    public function test_invoice_mutations_recheck_persisted_permission_revoked_mid_transaction_in_both_locales(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-MID-TX-DENY');
        [, $warehouse] = $this->stockTarget($store, 'PINV-MID-TX-SKU');
        $receipt = $this->postedReceipt(
            $actor, $context, $warehouse, 'PINV-MID-TX-SUP', 'PINV-MID-TX-SKU', 8,
        );
        $manager = app(PurchaseInvoiceHandoffManager::class);
        $receiptLineId = $receipt->lines()->firstOrFail()->id;
        $armed = false;
        $injected = false;
        $targetTable = '';
        $permissionId = 0;

        // Persist DENY immediately after an actual finance SQL read inside
        // the write transaction. Same-connection injection rolls back along
        // with the refused write; this is not two independent MySQL sessions.
        DB::listen(static function ($query) use ($actor, &$armed, &$injected, &$targetTable, &$permissionId): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected || ! str_contains($sql, 'select')
                || ! str_contains($sql, $targetTable)) {
                return;
            }

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
            $payload = static fn (string $reference): array => [
                'supplier_invoice_number' => $reference,
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLineId,
                    'quantity' => 1,
                ]],
            ];

            $permissionId = (int) Permission::query()
                ->where('code', 'procurement.purchase_invoices.manage')
                ->value('id');
            $this->assertGreaterThan(0, $permissionId);
            $targetTable = 'enterprise_purchase_invoices';
            $armed = true;
            $injected = false;
            $auditBefore = DB::table('audit_logs')->count();
            $outboxBefore = DB::table('enterprise_outbox_messages')->count();
            try {
                $manager->createFromPostedReceipt($actor, $context, $receipt->id, $payload('MID-TX-DENIED-'.strtoupper($locale)));
                $this->fail('A revoked actor must not create an invoice after preflight.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($injected, 'Invoice duplicate-check SQL must execute the DENY.');
            $this->assertDatabaseMissing('enterprise_purchase_invoices', [
                'supplier_invoice_number' => 'MID-TX-DENIED-'.strtoupper($locale),
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($outboxBefore, DB::table('enterprise_outbox_messages')->count());
            $this->assertDatabaseMissing('user_permission_overrides', [
                'user_id' => $actor->id, 'permission_id' => $permissionId, 'effect' => 'deny',
            ]);
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);

            $invoice = $manager->createFromPostedReceipt(
                $actor, $context, $receipt->id, $payload('MID-TX-VALID-'.strtoupper($locale)),
            );
            $this->assertSame('draft', $invoice->status);
            $permissionId = (int) Permission::query()
                ->where('code', 'procurement.purchase_invoices.handoff')
                ->value('id');
            $this->assertGreaterThan(0, $permissionId);
            $targetTable = 'enterprise_purchase_invoice_lines';
            $armed = true;
            $injected = false;
            $auditBefore = DB::table('audit_logs')->count();
            $outboxBefore = DB::table('enterprise_outbox_messages')->count();
            try {
                $manager->handoff($actor, $context, $invoice->id);
                $this->fail('A revoked actor must not publish a Finance invoice event.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($injected, 'Handoff invoice-line SQL must execute the DENY.');
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id, 'status' => 'draft', 'outbox_event_id' => null,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($outboxBefore, DB::table('enterprise_outbox_messages')->count());
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
            $this->assertSame('handed_off', $manager->handoff($actor, $context, $invoice->id)->status);

            $cancelDraft = $manager->createFromPostedReceipt(
                $actor, $context, $receipt->id, $payload('MID-TX-CANCEL-'.strtoupper($locale)),
            );
            $permissionId = (int) Permission::query()
                ->where('code', 'procurement.purchase_invoices.manage')
                ->value('id');
            $targetTable = 'enterprise_purchase_invoices';
            $armed = true;
            $injected = false;
            $auditBefore = DB::table('audit_logs')->count();
            $outboxBefore = DB::table('enterprise_outbox_messages')->count();
            try {
                $manager->cancel($actor, $context, $cancelDraft->id);
                $this->fail('A revoked actor must not cancel a locked invoice.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($injected, 'Cancellation row-lock SQL must execute the DENY.');
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $cancelDraft->id, 'status' => 'draft',
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($outboxBefore, DB::table('enterprise_outbox_messages')->count());
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
            $this->assertSame('cancelled', $manager->cancel($actor, $context, $cancelDraft->id)->status);
        }
    }

    public function test_grn_mutations_reauthorize_after_transactional_reads_in_both_locales(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-TX-RBAC');
        $manager = app(GoodsReceiptManager::class);
        $armed = false;
        $injected = false;
        $table = '';
        $permissionId = 0;

        // The same-connection injected DENY rolls back with the refused
        // nested transaction. It is not a two-session/MySQL HTTP race proof.
        DB::listen(static function ($query) use ($actor, &$armed, &$injected, &$table, &$permissionId): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected || ! str_contains($sql, 'select')
                || ! str_contains($sql, $table)) {
                return;
            }

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
            $sku = 'GRN-TX-'.strtoupper($locale);
            [, $warehouse] = $this->stockTarget($store, $sku);
            $supplier = app(SupplierDirectory::class)->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'GRN-TX-SUP-'.strtoupper($locale),
                'name_en' => 'GRN supplier '.$locale,
            ]);
            $requests = app(PurchaseRequestManager::class);
            $request = $requests->create($actor, $context, [
                'supplier_id' => $supplier->id,
                'currency_code' => 'KWD',
                'reason' => 'GRN late revoke',
                'lines' => [[
                    'item_code' => $sku,
                    'description' => $sku,
                    'quantity' => 4,
                    'unit' => 'each',
                    'estimated_unit_cost' => 1.250,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $orders = app(PurchaseOrderManager::class);
            $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
            $order = $orders->issue($actor, $context, $order->id);
            $data = ['lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => 1,
            ]]];
            $manageId = (int) Permission::query()->where('code', 'procurement.goods_receipts.manage')->value('id');
            $postId = (int) Permission::query()->where('code', 'procurement.goods_receipts.post')->value('id');
            $this->assertGreaterThan(0, $manageId);
            $this->assertGreaterThan(0, $postId);

            $permissionId = $manageId;
            $table = 'enterprise_purchase_orders';
            $armed = true;
            $injected = false;
            $auditBefore = DB::table('audit_logs')->count();
            try {
                $manager->createFromIssuedOrder($actor, $context, $order->id, $data);
                $this->fail('Late manage DENY must prevent GRN creation.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($injected, 'PO SELECT must inject DENY.');
            $this->assertDatabaseMissing('enterprise_goods_receipts', ['purchase_order_id' => $order->id]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);

            $receipt = $manager->createFromIssuedOrder($actor, $context, $order->id, $data);
            $permissionId = $postId;
            $table = 'enterprise_goods_receipt_lines';
            $armed = true;
            $injected = false;
            $auditBefore = DB::table('audit_logs')->count();
            $stockBefore = DB::table('stock_movements')->count();
            try {
                $manager->post($actor, $context, $receipt->id);
                $this->fail('Late post DENY must prevent receipt posting.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($injected, 'Receipt-lines SELECT must inject DENY.');
            $this->assertDatabaseHas('enterprise_goods_receipts', ['id' => $receipt->id, 'status' => 'draft']);
            $this->assertSame($stockBefore, DB::table('stock_movements')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
            $this->assertSame('posted', $manager->post($actor, $context, $receipt->id)->status);

            $draft = $manager->createFromIssuedOrder($actor, $context, $order->id, $data);
            $permissionId = $manageId;
            $table = 'enterprise_goods_receipts';
            $armed = true;
            $injected = false;
            $auditBefore = DB::table('audit_logs')->count();
            try {
                $manager->cancel($actor, $context, $draft->id);
                $this->fail('Late manage DENY must prevent GRN cancellation.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertTrue($injected, 'GRN row SELECT must inject DENY.');
            $this->assertDatabaseHas('enterprise_goods_receipts', ['id' => $draft->id, 'status' => 'draft']);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
            $this->assertSame('cancelled', $manager->cancel($actor, $context, $draft->id)->status);
        }
    }

    public function test_invoice_mutations_recheck_procurement_shutdown_before_writes_in_both_locales(): void
    {
        $this->enableProcurement();
        [$maker, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-SHUTDOWN');
        [, $warehouse] = $this->stockTarget($store, 'PINV-SHUTDOWN-SKU');
        $receipt = $this->postedReceipt(
            $maker, $context, $warehouse, 'PINV-SHUTDOWN-SUP', 'PINV-SHUTDOWN-SKU', 8,
        );
        $manager = app(PurchaseInvoiceHandoffManager::class);
        $receiptLineId = $receipt->lines()->firstOrFail()->id;

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $armed = false;
            $revocations = 0;

            // The preflight sees ENABLED, then an operator disables the
            // module before the invoice transaction obtains its row lock.
            DB::listen(static function ($query) use (&$armed, &$revocations): void {
                $sql = strtolower((string) $query->sql);
                if (! $armed || ! str_contains($sql, 'enterprise_modules')
                    || ! str_contains($sql, 'select')) {
                    return;
                }

                $armed = false;
                $revocations++;
                DB::table('enterprise_modules')->where('code', 'procurement')
                    ->update(['state' => ModuleState::DISABLED->value]);
            });

            $reference = 'PINV-SHUT-'.strtoupper($locale);
            $attributes = [
                'supplier_invoice_number' => $reference,
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLineId,
                    'quantity' => 1,
                ]],
            ];

            $auditBefore = DB::table('audit_logs')->count();
            $invoiceCountBefore = DB::table('enterprise_purchase_invoices')->count();
            $armed = true;

            try {
                $manager->createFromPostedReceipt($maker, $context, $receipt->id, $attributes);
                $this->fail('Disabled Procurement must not create an invoice after preflight.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertSame(1, $revocations);
            $this->assertSame($invoiceCountBefore, DB::table('enterprise_purchase_invoices')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);
            $invoice = $manager->createFromPostedReceipt($maker, $context, $receipt->id, $attributes);

            $auditBefore = DB::table('audit_logs')->count();
            $outboxBefore = DB::table('enterprise_outbox_messages')->count();
            $armed = true;

            try {
                $manager->handoff($maker, $context, $invoice->id);
                $this->fail('Disabled Procurement must not hand an invoice to Finance.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertSame(2, $revocations);
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id, 'status' => 'draft',
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($outboxBefore, DB::table('enterprise_outbox_messages')->count());

            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);
            $this->assertSame('handed_off', $manager->handoff($maker, $context, $invoice->id)->status);

            $draft = $manager->createFromPostedReceipt($maker, $context, $receipt->id, [
                ...$attributes, 'supplier_invoice_number' => $reference.'-CANCEL',
            ]);
            $auditBefore = DB::table('audit_logs')->count();
            $armed = true;

            try {
                $manager->cancel($maker, $context, $draft->id);
                $this->fail('Disabled Procurement must not cancel invoices after preflight.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertSame(3, $revocations);
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $draft->id, 'status' => 'draft',
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);
            $this->assertSame('cancelled', $manager->cancel($maker, $context, $draft->id)->status);
        }
    }

    public function test_handoff_is_transactional_retry_safe_and_emits_one_outbox_event(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-A');
        [, $warehouse] = $this->stockTarget($store, 'PINV-A-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-A-SUP', 'PINV-A-SKU', 5);

        $manager = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-A',
            'invoice_date' => '2026-10-16',
            'due_date' => '2026-11-15',
            'lines' => [[
                'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                'quantity' => 2,
            ]],
        ]);

        $handedOff = $manager->handoff($actor, $context, $invoice->id);
        $retry = $manager->handoff($actor, $context, $invoice->id);

        $this->assertSame('handed_off', $handedOff->status);
        $this->assertNotNull($handedOff->outbox_event_id);
        $this->assertSame($handedOff->outbox_event_id, $retry->outbox_event_id);
        $this->assertSame($handedOff->handed_off_at?->toISOString(), $retry->handed_off_at?->toISOString());
        $this->assertSame('2.500', $handedOff->total_amount);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_id', $handedOff->outbox_event_id)
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')
                ->where('event_version', 1)
                ->count(),
        );
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.purchase_invoice.handed_off')
                ->where('auditable_id', $invoice->id)
                ->count(),
        );
    }

    public function test_posted_returns_reduce_net_invoiceable_quantity(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-B');
        [, $warehouse] = $this->stockTarget($store, 'PINV-B-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-B-SUP', 'PINV-B-SKU', 5);
        $receiptLine = $receipt->lines()->firstOrFail();

        $returns = app(PurchaseReturnManager::class);
        $return = $returns->createFromPostedReceipt($actor, $context, $receipt->id, [
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 2,
            ]],
        ]);
        $returns->post($actor, $context, $return->id);

        $manager = app(PurchaseInvoiceHandoffManager::class);

        try {
            $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'SUP-INV-B-BAD',
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLine->id,
                    'quantity' => 4,
                ]],
            ]);
            $this->fail('Invoice quantity must not exceed received quantity net of posted returns.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-B',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 3,
            ]],
        ]);
        $manager->handoff($actor, $context, $invoice->id);

        $this->expectException(ValidationException::class);
        $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-B-SECOND',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 1,
            ]],
        ]);
    }

    public function test_finance_handoff_rechecks_locked_grn_source_after_preflight_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-GRN-RACE');
        [, $warehouse] = $this->stockTarget($store, 'PINV-GRN-RACE-SKU');
        $manager = app(PurchaseInvoiceHandoffManager::class);

        // Listen once: Laravel query listeners survive both locale iterations.
        $armed = false;
        $injected = false;
        $targetReceiptId = 0;
        $tamperedOrder = '';
        DB::listen(static function ($query) use (&$armed, &$injected, &$targetReceiptId, &$tamperedOrder): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_goods_receipts')
                || str_contains($sql, ' join ')) {
                return;
            }

            $armed = false;
            $injected = true;
            // The select that obtains the GRN row lock has returned; emulate
            // a source correction in the pre-existing time-of-check gap.
            DB::table('enterprise_goods_receipts')->where('id', $targetReceiptId)
                ->update(['source_order_number' => $tamperedOrder]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $receipt = $this->postedReceipt(
                $actor, $context, $warehouse, 'PINV-RACE-SUP-'.strtoupper($locale),
                'PINV-GRN-RACE-SKU', 3,
            );
            $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'PINV-GRN-RACE-'.strtoupper($locale),
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ]);
            $sourceOrderNumber = $receipt->source_order_number;
            $tamperedOrder = 'TAMPER-GRN-'.strtoupper($locale);
            $targetReceiptId = (int) $receipt->id;
            $auditBefore = DB::table('audit_logs')->count();
            $outboxBefore = DB::table('enterprise_outbox_messages')
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')->count();
            $injected = false;
            $armed = true;

            try {
                $manager->handoff($actor, $context, $invoice->id);
                $this->fail('A GRN source changed before the match must never reach Finance.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('three_way_match', $exception->errors());
            } finally {
                $armed = false;
            }

            $this->assertTrue($injected, 'Race injection did not reach the locked GRN query.');
            $this->assertDatabaseHas('enterprise_goods_receipts', [
                'id' => $receipt->id,
                'source_order_number' => $sourceOrderNumber,
            ]);
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id,
                'status' => 'draft',
                'outbox_event_id' => null,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($outboxBefore, DB::table('enterprise_outbox_messages')
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')->count());
            $this->assertSame('handed_off', $manager->handoff($actor, $context, $invoice->id)->status);
        }
    }

    public function test_stale_overlapping_draft_is_rechecked_at_handoff(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-C');
        [, $warehouse] = $this->stockTarget($store, 'PINV-C-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-C-SUP', 'PINV-C-SKU', 5);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $first = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-C-1',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 4,
            ]],
        ]);
        $stale = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-C-2',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 4,
            ]],
        ]);

        $manager->handoff($actor, $context, $first->id);

        try {
            $manager->handoff($actor, $context, $stale->id);
            $this->fail('Handoff must re-check invoiceable quantity under the source GRN lock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertDatabaseHas('enterprise_purchase_invoices', [
            'id' => $stale->id,
            'status' => 'draft',
            'outbox_event_id' => null,
        ]);
        $this->assertSame(
            1,
            DB::table('enterprise_outbox_messages')
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')
                ->count(),
        );
    }

    public function test_duplicate_invoice_grn_line_is_rejected_by_database_before_finance_handoff_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-DUP-GRN');
        [, $warehouse] = $this->stockTarget($store, 'PINV-DUP-SKU');
        $receipt = $this->postedReceipt(
            $actor, $context, $warehouse, 'PINV-DUP-SUP', 'PINV-DUP-SKU', 3,
        );
        $manager = app(PurchaseInvoiceHandoffManager::class);
        $receiptLineId = $receipt->lines()->firstOrFail()->id;

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'PINV-DUP-'.strtoupper($locale),
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLineId,
                    'quantity' => 1,
                ]],
            ]);

            // The unique (invoice, GRN line) index is the primary boundary.
            // Even a direct importer bypassing the creation validator cannot
            // persist another row with a distinct line number for this source.
            $original = (array) DB::table('enterprise_purchase_invoice_lines')
                ->where('purchase_invoice_id', $invoice->id)->first();
            $this->assertNotEmpty($original);
            $duplicate = $original;
            unset($duplicate['id']);
            $duplicate['line_no'] = 2;
            $auditBefore = DB::table('audit_logs')->count();
            $outboxBefore = DB::table('enterprise_outbox_messages')
                ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')->count();

            try {
                DB::table('enterprise_purchase_invoice_lines')->insert($duplicate);
                $this->fail('Database must prevent a duplicated GRN source on one invoice.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }

            $this->assertSame(1, DB::table('enterprise_purchase_invoice_lines')
                ->where('purchase_invoice_id', $invoice->id)->count());
            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id,
                'status' => 'draft',
                'outbox_event_id' => null,
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame(
                $outboxBefore,
                DB::table('enterprise_outbox_messages')
                    ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')->count(),
            );
            $this->assertSame('handed_off', $manager->handoff($actor, $context, $invoice->id)->status);
        }
    }

    public function test_supplier_invoice_number_is_unique_and_reads_are_scope_bounded(): void
    {
        $this->enableProcurement();
        [$actorA, $storeA, $contextA] = $this->scopedUser($this->allPermissions(), 'PINV-D-A');
        [$actorB, , $contextB] = $this->scopedUser($this->allPermissions(), 'PINV-D-B');
        [, $warehouseA] = $this->stockTarget($storeA, 'PINV-D-A-SKU');
        $receipt = $this->postedReceipt($actorA, $contextA, $warehouseA, 'PINV-D-SUP', 'PINV-D-A-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $invoice = $manager->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-D',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);

        try {
            $manager->createFromPostedReceipt($actorA, $contextA, $receipt->id, [
                'supplier_invoice_number' => 'SUP-INV-D',
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ]);
            $this->fail('Supplier invoice numbers must remain unique within company and supplier scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('supplier_invoice_number', $exception->errors());
        }

        $page = $manager->paginate($actorA, $contextA, ['status' => 'draft'], 500);
        $this->assertSame(100, $page->perPage());
        $this->assertSame(1, $page->total());
        $this->assertSame(0, $manager->paginate($actorB, $contextB)->total());

        try {
            $manager->detail($actorB, $contextB, $invoice->id);
            $this->fail('Cross-store purchase invoice detail must be invisible.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_invoice_page_denies_postquery_foreign_store_transfer_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-PAGE-LIVE');
        $foreignStore = $this->createRetailStore('PINV-PAGE-FOREIGN');
        [, $warehouse] = $this->stockTarget($store, 'PINV-PAGE-LIVE-SKU');
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $armed = false;
        $moved = false;
        $targetInvoiceId = null;
        DB::listen(static function ($query) use ($foreignStore, &$armed, &$moved, &$targetInvoiceId): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $moved || $targetInvoiceId === null
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_purchase_invoices')
                || ! str_contains($sql, 'order by')) {
                return;
            }

            // Fire after the ordered scoped page SELECT; the hydrated row
            // retains its old store ID, but persisted ownership has changed.
            $armed = false;
            $moved = true;
            DB::table('enterprise_purchase_invoices')
                ->where('id', $targetInvoiceId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $receipt = $this->postedReceipt(
                $actor, $context, $warehouse,
                'PINV-PAGE-SUP-'.strtoupper($locale), 'PINV-PAGE-LIVE-SKU', 2,
            );
            $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'PINV-PAGE-'.strtoupper($locale),
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ]);
            $filter = ['q' => $invoice->invoice_number];
            $this->assertSame(1, $manager->paginate($actor, $context, $filter)->total());

            $targetInvoiceId = (int) $invoice->id;
            $auditBefore = DB::table('audit_logs')->count();
            $linesBefore = DB::table('enterprise_purchase_invoice_lines')->count();
            $moved = false;
            $armed = true;

            try {
                $manager->paginate($actor, $context, $filter);
                $this->fail('An invoice transferred after page selection must not leak financial header data.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertTrue($moved, 'The transfer must follow the actual ordered invoice SELECT.');
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertDatabaseHas('enterprise_purchase_invoices', [
                'id' => $invoice->id,
                'store_id' => $foreignStore->id,
                'status' => 'draft',
            ]);
            $this->assertSame(0, $manager->paginate($actor, $context, $filter)->total());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($linesBefore, DB::table('enterprise_purchase_invoice_lines')->count());

            DB::table('enterprise_purchase_invoices')
                ->where('id', $invoice->id)
                ->update(['store_id' => $store->id]);
            $this->assertSame(1, $manager->paginate($actor, $context, $filter)->total());
            DB::table('enterprise_purchase_invoices')
                ->where('id', $invoice->id)
                ->update(['store_id' => $foreignStore->id]);
        }
    }

    public function test_invoice_detail_denies_stale_header_and_financial_lines_after_store_transfer_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-DETAIL-LIVE');
        $foreignStore = $this->createRetailStore('PINV-DETAIL-FOREIGN');
        [, $warehouse] = $this->stockTarget($store, 'PINV-DETAIL-LIVE-SKU');
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $armed = false;
        $moved = false;
        $targetInvoiceId = null;
        DB::listen(static function ($query) use (&$armed, &$moved, &$targetInvoiceId, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $moved || $targetInvoiceId === null
                || ! str_contains($sql, 'enterprise_purchase_invoices')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'join')) {
                return;
            }

            // Simulate a transfer immediately after the authorized invoice
            // query, before Eloquent loads its supplier/receipt/line relations.
            $armed = false;
            $moved = true;
            DB::table('enterprise_purchase_invoices')
                ->where('id', $targetInvoiceId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $receipt = $this->postedReceipt(
                $actor, $context, $warehouse, 'PINV-DETAIL-SUP-'.strtoupper($locale),
                'PINV-DETAIL-LIVE-SKU', 2,
            );
            $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                'supplier_invoice_number' => 'PINV-DETAIL-'.strtoupper($locale),
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $receipt->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ]);

            $visible = $manager->detail($actor, $context, $invoice->id);
            $this->assertSame($invoice->id, $visible->id);
            $this->assertCount(1, $visible->lines);

            $targetInvoiceId = (int) $invoice->id;
            $auditBefore = DB::table('audit_logs')->count();
            $linesBefore = DB::table('enterprise_purchase_invoice_lines')->count();
            $moved = false;
            $armed = true;
            try {
                $manager->detail($actor, $context, $invoice->id);
                $this->fail('A transferred invoice cannot reveal its former store header or line amounts.');
            } catch (ModelNotFoundException) {
                $this->assertTrue($moved, 'Transfer must occur after the scoped preflight.');
            }

            $this->assertSame($foreignStore->id, (int) DB::table('enterprise_purchase_invoices')
                ->where('id', $invoice->id)->value('store_id'));
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($linesBefore, DB::table('enterprise_purchase_invoice_lines')->count());

            try {
                $manager->detail($actor, $context, $invoice->id);
                $this->fail('A second read must also deny the former store.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_goods_receipt_detail_denies_stale_header_and_received_line_costs_after_store_transfer_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'GRN-DETAIL-LIVE');
        $foreignStore = $this->createRetailStore('GRN-DETAIL-FOREIGN');
        [, $warehouse] = $this->stockTarget($store, 'GRN-DETAIL-LIVE-SKU');
        $manager = app(GoodsReceiptManager::class);

        $armed = false;
        $moved = false;
        $targetReceiptId = null;
        DB::listen(static function ($query) use (&$armed, &$moved, &$targetReceiptId, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $moved || $targetReceiptId === null
                || ! str_contains($sql, 'enterprise_goods_receipts')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'join')) {
                return;
            }

            // Change store ownership immediately after the scoped receipt
            // preflight, before the supplier, order and line SELECTs execute.
            $armed = false;
            $moved = true;
            DB::table('enterprise_goods_receipts')
                ->where('id', $targetReceiptId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $receipt = $this->postedReceipt(
                $actor, $context, $warehouse, 'GRN-DETAIL-SUP-'.strtoupper($locale),
                'GRN-DETAIL-LIVE-SKU', 2,
            );

            $visible = $manager->detail($actor, $context, $receipt->id);
            $this->assertSame($receipt->id, $visible->id);
            $this->assertCount(1, $visible->lines);
            $this->assertSame('posted', $visible->status);

            $targetReceiptId = (int) $receipt->id;
            $auditBefore = DB::table('audit_logs')->count();
            $linesBefore = DB::table('enterprise_goods_receipt_lines')->count();
            $movementsBefore = DB::table('stock_movements')->count();
            $moved = false;
            $armed = true;
            try {
                $manager->detail($actor, $context, $receipt->id);
                $this->fail('Transferred goods receipt must not reveal stale supplier, stock or unit prices.');
            } catch (ModelNotFoundException) {
                $this->assertTrue($moved, 'Ownership must change after the original authorized SELECT.');
            }

            $this->assertDatabaseHas('enterprise_goods_receipts', [
                'id' => $receipt->id, 'store_id' => $foreignStore->id, 'status' => 'posted',
            ]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($linesBefore, DB::table('enterprise_goods_receipt_lines')->count());
            $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

            try {
                $manager->detail($actor, $context, $receipt->id);
                $this->fail('Repeated former-store access must remain denied.');
            } catch (ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_purchase_invoice_number_and_supplier_reference_prefix_search_is_literal_scoped_and_read_only(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-LIT-A');
        [$foreignActor, $foreignStore, $foreignContext] = $this->scopedUser(
            $this->allPermissions(),
            'PINV-LIT-B',
        );
        [, $warehouse] = $this->stockTarget($store, 'PINV-LIT-A-SKU');
        [, $foreignWarehouse] = $this->stockTarget($foreignStore, 'PINV-LIT-B-SKU');
        $receipt = $this->postedReceipt(
            $actor, $context, $warehouse, 'PINV-LIT-A-SUP', 'PINV-LIT-A-SKU', 12,
        );
        $foreignReceipt = $this->postedReceipt(
            $foreignActor, $foreignContext, $foreignWarehouse,
            'PINV-LIT-B-SUP', 'PINV-LIT-B-SKU', 2,
        );

        $manager = app(PurchaseInvoiceHandoffManager::class);
        $newInvoice = static function (
            User $owner, ResolvedTenantContext $scope, EnterpriseGoodsReceipt $source, string $supplierNumber,
        ) use ($manager): int {
            return (int) $manager->createFromPostedReceipt($owner, $scope, $source->id, [
                'supplier_invoice_number' => $supplierNumber,
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $source->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ])->id;
        };

        $target = $newInvoice($actor, $context, $receipt, 'VEND-TARGET');
        $decoy = $newInvoice($actor, $context, $receipt, 'VEND-DECOY');
        $supplierTarget = $newInvoice($actor, $context, $receipt, 'VEND_%!\\-Z');
        $leadingSymbols = [];
        foreach ([
            ['%', '%P-001'],
            ['_', '_U-001'],
            ['!', '!B-001'],
            ['\\', '\\S-001'],
        ] as [$symbol, $number]) {
            $leadingSymbols[$symbol] = $newInvoice(
                $actor, $context, $receipt, 'VEND-SYMBOL-'.bin2hex($symbol),
            );
            DB::table('enterprise_purchase_invoices')
                ->where('id', $leadingSymbols[$symbol])
                ->update(['invoice_number' => $number]);
        }
        $foreign = $newInvoice(
            $foreignActor, $foreignContext, $foreignReceipt, 'VEND-FOREIGN',
        );
        foreach ([
            [$target, 'PINV_%!\\-A'],
            [$decoy, 'PINVX-OTHER'],
            [$supplierTarget, 'ALT-ONLY'],
            [$foreign, 'PINV_%!\\-FOREIGN'],
        ] as [$id, $number]) {
            DB::table('enterprise_purchase_invoices')
                ->where('id', $id)
                ->update(['invoice_number' => $number]);
        }

        $invoiceCount = DB::table('enterprise_purchase_invoices')->count();
        $lineCount = DB::table('enterprise_purchase_invoice_lines')->count();
        $movementCount = DB::table('stock_movements')->count();
        $auditCount = DB::table('audit_logs')->count();
        $outboxCount = DB::table('enterprise_outbox_messages')->count();
        $search = fn (string $q): array => $manager->paginate($actor, $context, ['q' => $q])
            ->getCollection()->pluck('id')->all();

        foreach (['PINV_', 'PINV_%', 'PINV_%!', 'PINV_%!\\-'] as $q) {
            $this->assertSame([$target], $search($q), 'Incorrect literal invoice prefix: '.$q);
        }
        foreach (['VEND_', 'VEND_%', 'VEND_%!', 'VEND_%!\\-'] as $q) {
            $this->assertSame([$supplierTarget], $search($q), 'Incorrect supplier reference prefix: '.$q);
        }
        foreach ($leadingSymbols as $symbol => $id) {
            $this->assertSame([$id], $search($symbol), 'Symbol search broadened: '.$symbol);
        }
        $this->assertSame([$decoy, $target], $search('PINV'));
        $this->assertSame([], $search('PINV%'));
        $this->assertSame([], $search('VEND%'));
        $this->assertSame(1, $manager->paginate(
            $foreignActor, $foreignContext, ['q' => 'PINV_%!'],
        )->total());
        $this->assertSame(0, $manager->paginate(
            $actor, $context, ['q' => 'PINV_%!', 'status' => 'handed_off'],
        )->total());
        $this->assertSame(1, $manager->paginate(
            $actor, $context, [
                'q' => 'PINV_%!', 'status' => 'draft',
                'supplier_id' => $receipt->supplier_id,
                'goods_receipt_id' => $receipt->id,
            ],
        )->total());
        $this->assertSame(0, $manager->paginate(
            $actor, $context, ['q' => 'PINV_%!', 'goods_receipt_id' => $foreignReceipt->id],
        )->total());
        $this->assertSame(0, $manager->paginate(
            $actor, $context, ['q' => 'PINV_%!', 'supplier_id' => 999999],
        )->total());
        $this->assertSame(10, $manager->paginate($actor, $context, [], 1)->perPage());

        $roleless = User::factory()->create(['is_active' => true]);
        try {
            $manager->paginate($roleless, $context, ['q' => 'PINV_%']);
            $this->fail('Roleless actors may not read procurement purchase invoices.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($invoiceCount, DB::table('enterprise_purchase_invoices')->count());
        $this->assertSame($lineCount, DB::table('enterprise_purchase_invoice_lines')->count());
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($outboxCount, DB::table('enterprise_outbox_messages')->count());
    }

    public function test_three_way_match_list_uses_literal_invoice_and_supplier_prefixes_without_writes(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PMATCH-LIT-A');
        [$foreignActor, $foreignStore, $foreignContext] = $this->scopedUser(
            $this->allPermissions(),
            'PMATCH-LIT-B',
        );
        [$roleless, , $rolelessContext] = $this->scopedUser([], 'PMATCH-LIT-NO-READ');
        [, $warehouse] = $this->stockTarget($store, 'PMATCH-LIT-A-SKU');
        [, $foreignWarehouse] = $this->stockTarget($foreignStore, 'PMATCH-LIT-B-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PMATCH-LIT-A-SUP', 'PMATCH-LIT-A-SKU', 15);
        $foreignReceipt = $this->postedReceipt(
            $foreignActor, $foreignContext, $foreignWarehouse, 'PMATCH-LIT-B-SUP', 'PMATCH-LIT-B-SKU', 3,
        );
        $invoices = app(PurchaseInvoiceHandoffManager::class);
        $completion = app(ProcurementCompletionManager::class);

        // Create genuine draft purchase invoices from posted receipts. Replace
        // only synthetic external reference fields to model legacy imports.
        $make = function (
            User $owner,
            ResolvedTenantContext $scope,
            EnterpriseGoodsReceipt $source,
            string $number,
            string $supplierReference,
            string $supplierCode,
        ) use ($invoices, $completion): int {
            $invoice = $invoices->createFromPostedReceipt($owner, $scope, $source->id, [
                'supplier_invoice_number' => 'MATCH-TEMP-'.bin2hex(random_bytes(8)),
                'invoice_date' => '2026-10-16',
                'lines' => [[
                    'goods_receipt_line_id' => $source->lines()->firstOrFail()->id,
                    'quantity' => 1,
                ]],
            ]);
            DB::table('enterprise_purchase_invoices')->where('id', $invoice->id)->update([
                'invoice_number' => $number,
                'supplier_invoice_number' => $supplierReference,
                'supplier_code' => $supplierCode,
            ]);
            $completion->syncMatchForInvoice((int) $invoice->id);

            return (int) $invoice->id;
        };

        $numberId = $make($actor, $context, $receipt, '%!_MATCH-01', 'REF-01', 'SUP-01');
        $supplierId = $make($actor, $context, $receipt, 'MATCH-02', 'VEND_%!\\-02', 'SUP-02');
        $codeId = $make($actor, $context, $receipt, 'MATCH-03', 'REF-03', '_!%CODE-03');
        $bangId = $make($actor, $context, $receipt, '!MATCH-04', 'REF-04', 'SUP-04');
        $slashId = $make($actor, $context, $receipt, '\\MATCH-05', 'REF-05', 'SUP-05');
        $decoyId = $make($actor, $context, $receipt, 'MATCH-PLAIN-06', 'REF-06', 'SUP-06');
        $make($foreignActor, $foreignContext, $foreignReceipt, '%!_MATCH-FOREIGN', 'VEND_%!\\-FOREIGN', '_!%CODE-FOREIGN');
        DB::table('enterprise_procurement_match_reviews')
            ->where('purchase_invoice_id', $codeId)
            ->update(['status' => 'pending']);

        $before = [
            'invoices' => DB::table('enterprise_purchase_invoices')->count(),
            'lines' => DB::table('enterprise_purchase_invoice_lines')->count(),
            'matches' => DB::table('enterprise_procurement_match_reviews')->count(),
            'stock' => DB::table('stock_movements')->count(),
            'audit' => DB::table('audit_logs')->count(),
            'outbox' => DB::table('enterprise_outbox_messages')->count(),
        ];
        $matching = fn (string $q, array $extra = []): array => $completion
            ->paginateMatches($actor, $context, ['q' => $q] + $extra)
            ->getCollection()->pluck('purchase_invoice_id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame([$numberId], $matching('%!_'));
        $this->assertSame([$supplierId], $matching('VEND_%!\\'));
        $this->assertSame([$codeId], $matching('_!%CODE'));
        $this->assertSame([$numberId], $matching('%'));
        $this->assertSame([$codeId], $matching('_'));
        $this->assertSame([$bangId], $matching('!'));
        $this->assertSame([$slashId], $matching('\\'));
        $this->assertSame([], $matching('MATCH%'));
        $this->assertSame([$decoyId], $matching('MATCH-PLAIN'));
        $this->assertSame([$codeId], $matching('_!%CODE', ['status' => 'pending']));
        $this->assertSame([], $matching('_!%CODE', ['status' => 'auto_approved']));
        $this->assertSame([$numberId], $matching('%!_', ['status' => 'auto_approved']));
        $this->assertCount(6, $matching(''));
        $this->assertSame(10, $completion->paginateMatches($actor, $context, [], 1)->perPage());
        $this->assertSame(100, $completion->paginateMatches($actor, $context, [], 1000)->perPage());

        try {
            $completion->paginateMatches($roleless, $rolelessContext, ['q' => '%']);
            $this->fail('A roleless actor must not read three-way match reviews.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, [
            'invoices' => DB::table('enterprise_purchase_invoices')->count(),
            'lines' => DB::table('enterprise_purchase_invoice_lines')->count(),
            'matches' => DB::table('enterprise_procurement_match_reviews')->count(),
            'stock' => DB::table('stock_movements')->count(),
            'audit' => DB::table('audit_logs')->count(),
            'outbox' => DB::table('enterprise_outbox_messages')->count(),
        ]);
    }

    public function test_p244_invoice_rejections_remain_localized_and_do_not_change_financial_state(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P244-I18N');
        [, $warehouse] = $this->stockTarget($store, 'P244-I18N-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'P244-I18N-SUP', 'P244-I18N-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);
        $payload = [
            'supplier_invoice_number' => 'P244-SUP-INVOICE',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ];
        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, $payload);
        $manager->handoff($actor, $context, $invoice->id);
        $localizedFailures = [];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            try {
                $manager->createFromPostedReceipt($actor, $context, $receipt->id, $payload);
                $this->fail('A duplicate supplier invoice reference must be rejected.');
            } catch (ValidationException $exception) {
                $message = $exception->errors()['supplier_invoice_number'][0] ?? null;
                $this->assertSame(__('enterprise_procurement.errors.invoice_duplicate_supplier_number'), $message);
                $localizedFailures[$locale]['duplicate'] = $message;
            }

            try {
                $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
                    ...$payload,
                    'supplier_invoice_number' => 'P244-TOO-MANY-'.$locale,
                    'lines' => [[
                        'goods_receipt_line_id' => $lineId,
                        'quantity' => 3,
                    ]],
                ]);
                $this->fail('Invoiced quantities must not exceed the net receipt balance.');
            } catch (ValidationException $exception) {
                $message = $exception->errors()['lines'][0] ?? null;
                $this->assertSame(__('enterprise_procurement.errors.invoice_quantity_exceeds_available', [
                    'line' => $receipt->lines()->firstOrFail()->line_no,
                    'quantity' => '1.000',
                ]), $message);
                $localizedFailures[$locale]['quantity'] = $message;
            }

            try {
                $manager->cancel($actor, $context, $invoice->id);
                $this->fail('Finance handoff must make an invoice immutable.');
            } catch (ValidationException $exception) {
                $message = $exception->errors()['status'][0] ?? null;
                $this->assertSame(__('enterprise_procurement.errors.invoice_handed_off_immutable'), $message);
                $localizedFailures[$locale]['immutable'] = $message;
            }
        }

        foreach (['duplicate', 'quantity', 'immutable'] as $failure) {
            $this->assertNotSame($localizedFailures['ar'][$failure], $localizedFailures['en'][$failure]);
        }

        $this->assertSame(1, DB::table('enterprise_purchase_invoices')->count());
        $this->assertSame('handed_off', $invoice->fresh()->status);
        $this->assertSame(1, DB::table('enterprise_outbox_messages')
            ->where('event_type', 'ProcurementPurchaseInvoiceHandedOff')
            ->count());
        $this->assertSame(0, DB::table('audit_logs')
            ->where('event', 'enterprise.purchase_invoice.cancelled')
            ->count());
    }

    public function test_draft_cancel_is_retry_safe_and_handed_off_invoice_is_immutable(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-E');
        [, $warehouse] = $this->stockTarget($store, 'PINV-E-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-E-SUP', 'PINV-E-SKU', 2);
        $lineId = $receipt->lines()->firstOrFail()->id;
        $manager = app(PurchaseInvoiceHandoffManager::class);

        $draft = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-E-CANCEL',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $cancelled = $manager->cancel($actor, $context, $draft->id);
        $again = $manager->cancel($actor, $context, $draft->id);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($cancelled->cancelled_at?->toISOString(), $again->cancelled_at?->toISOString());

        $handed = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-E-HAND',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $lineId,
                'quantity' => 1,
            ]],
        ]);
        $manager->handoff($actor, $context, $handed->id);

        $this->expectException(ValidationException::class);
        $manager->cancel($actor, $context, $handed->id);
    }

    public function test_value_variance_requires_explicit_three_way_match_review_before_finance_handoff(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PINV-MATCH');
        [, $warehouse] = $this->stockTarget($store, 'PINV-MATCH-SKU');
        $receipt = $this->postedReceipt($actor, $context, $warehouse, 'PINV-MATCH-SUP', 'PINV-MATCH-SKU', 2);
        $receiptLine = $receipt->lines()->firstOrFail();

        $manager = app(PurchaseInvoiceHandoffManager::class);
        $invoice = $manager->createFromPostedReceipt($actor, $context, $receipt->id, [
            'supplier_invoice_number' => 'SUP-INV-MATCH',
            'invoice_date' => '2026-10-16',
            'lines' => [[
                'goods_receipt_line_id' => $receiptLine->id,
                'quantity' => 2,
                'unit_price' => 1.500,
            ]],
        ]);

        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
            'status' => 'pending',
            'quantity_variance' => 0,
            'value_variance' => 0.5,
        ]);

        try {
            $manager->handoff($actor, $context, $invoice->id);
            $this->fail('A value mismatch must block Finance handoff until explicitly reviewed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('three_way_match', $exception->errors());
        }

        app(ProcurementCompletionManager::class)->reviewMatch(
            $actor,
            $context,
            $invoice->id,
            ['decision' => 'approve', 'note' => 'Supplier invoice price accepted.'],
        );

        $handedOff = $manager->handoff($actor, $context, $invoice->id);
        $this->assertSame('handed_off', $handedOff->status);
        $this->assertDatabaseHas('enterprise_procurement_match_reviews', [
            'purchase_invoice_id' => $invoice->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'enterprise.procurement.three_way_match.reviewed',
        ]);
    }

    public function test_purchase_invoice_migration_is_reversible(): void
    {
        $migration = require database_path(
            'migrations/2026_10_16_080500_create_enterprise_purchase_invoice_handoff_foundation.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_purchase_invoice_lines'));
        $this->assertFalse(Schema::hasTable('enterprise_purchase_invoices'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_invoices', [
            'goods_receipt_id',
            'supplier_invoice_number',
            'invoice_date',
            'due_date',
            'total_amount',
            'status',
            'outbox_event_id',
        ]));
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_invoice_lines', [
            'goods_receipt_line_id',
            'invoice_quantity',
            'unit_price',
            'line_total',
        ]));
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    private function postedReceipt(
        User $actor,
        ResolvedTenantContext $context,
        Warehouse $warehouse,
        string $supplierCode,
        string $sku,
        float $quantity,
    ): EnterpriseGoodsReceipt {
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => $supplierCode,
            'name_en' => $supplierCode,
        ]);

        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplier->id,
            'currency_code' => 'KWD',
            'reason' => 'Purchase invoice source receipt',
            'lines' => [[
                'item_code' => $sku,
                'description' => 'Stock item '.$sku,
                'quantity' => $quantity,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);

        $orders = app(PurchaseOrderManager::class);
        $order = $orders->createFromApprovedRequest($actor, $context, $request->id);
        $order = $orders->issue($actor, $context, $order->id);

        $receipts = app(GoodsReceiptManager::class);
        $receipt = $receipts->createFromIssuedOrder($actor, $context, $order->id, [
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
            ]],
        ]);

        return $receipts->post($actor, $context, $receipt->id);
    }

    /** @return array{Product,Warehouse} */
    private function stockTarget(Store $store, string $sku): array
    {
        $catalog = Catalog::query()->create([
            'store_id' => $store->id,
            'channel' => 'b2c',
            'code' => 'pinv-'.strtolower($sku),
            'name' => $sku.' Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
        ]);

        $unitCode = 'EA-'.substr(md5($sku), 0, 10);
        $unitId = (int) DB::table('units')->insertGetId([
            'store_id' => null,
            'scope' => 'global',
            'scope_key' => 'global',
            'code' => $unitCode,
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
            'code' => 'WH-'.substr(md5($sku), 0, 12),
            'name' => $sku.' Invoice',
            'is_active' => true,
        ]);

        return [$product, $warehouse];
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
            'procurement.goods_receipts.view',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
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
