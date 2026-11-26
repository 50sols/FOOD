<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Wms\UnknownInventoryOwnerProjection;
use App\Models\EnterpriseGoodsReceipt;
use App\Models\EnterpriseGoodsReceiptLine;
use App\Models\EnterprisePurchaseOrder;
use App\Models\EnterprisePurchaseOrderLine;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class GoodsReceiptInventoryHandoff
{
    public function __construct(
        private readonly UnknownInventoryOwnerProjection $ownerProjection,
    ) {}

    public function receive(
        User $actor,
        int $storeId,
        EnterpriseGoodsReceipt $receipt,
        EnterpriseGoodsReceiptLine $line,
    ): void {
        // Creating a canonical inventory row before the owner projection's
        // transaction guard would leave partial stock writes on direct calls.
        // The manager already transacts; enforce this at the write boundary.
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Goods receipt stock handoff requires an active database transaction.');
        }

        // The manager has its own authorization checks, but this public stock
        // writer must not trust a queued/stale Eloquent actor snapshot. The
        // caller can alter a user's primary key in memory to attribute a real
        // canonical stock movement to a different identity. Reload only the
        // originally persisted actor, and fail closed after offboarding.
        $actorId = $actor->getRawOriginal($actor->getKeyName());
        abort_unless(
            $actor->exists
                && $actorId !== null
                && (string) $actor->getKey() === (string) $actorId,
            403,
        );
        $actor = User::query()->whereKey($actorId)->first();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        // This public posting boundary must not accept a line from another
        // draft receipt on the same issued purchase order. The PO-line check
        // below is not enough: both drafts can share the exact same PO line.
        // Bind the line and receiving store before touching stock or title.
        // A public handoff can be called with Eloquent models whose primary
        // keys were modified in memory. Never follow a caller-selected ID to
        // a different legitimate draft in the same store and purchase order:
        // that would silently redirect stock movement provenance and title.
        // Fail closed before any canonical row lock or inventory mutation.
        if ($receipt->getKey() === null
            || $line->getKey() === null
            || ! $receipt->exists
            || ! $line->exists
            || (string) $receipt->getKey() !== (string) $receipt->getRawOriginal($receipt->getKeyName())
            || (string) $line->getKey() !== (string) $line->getRawOriginal($line->getKeyName())
            || (int) $line->goods_receipt_id !== (int) $receipt->getKey()
            || (int) $receipt->store_id !== $storeId) {
            throw ValidationException::withMessages([
                'goods_receipt_id' => [
                    'Goods receipt line must belong to the authorized receipt and receiving store.',
                ],
            ]);
        }

        // Direct handoff callers can retain a draft receipt header after
        // GoodsReceiptManager has cancelled or posted that receipt. Lock and
        // re-read the persisted header BEFORE the line, in the same lock order
        // as the manager (receipt, purchase order, receipt lines).
        $persistedReceipt = EnterpriseGoodsReceipt::query()
            ->whereKey((int) $receipt->getKey())
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        if ($persistedReceipt === null
            || (int) $persistedReceipt->purchase_order_id !== (int) $receipt->purchase_order_id) {
            throw ValidationException::withMessages([
                'goods_receipt_id' => ['Goods receipt no longer matches its authorized source order and store.'],
            ]);
        }

        if ($persistedReceipt->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['A goods receipt that is no longer draft cannot receive stock.'],
            ]);
        }

        $receipt = $persistedReceipt;

        // An issued PO can be revoked independently while a caller holds the
        // old GRN draft. A direct stock writer must enforce the source PO
        // lifecycle too, not just the manager-only HTTP posting path.
        $persistedOrder = EnterprisePurchaseOrder::query()
            ->whereKey((int) $receipt->purchase_order_id)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first();

        if ($persistedOrder === null || $persistedOrder->status !== 'issued') {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['Source purchase order is no longer issued for stock receipt.'],
            ]);
        }

        // The caller may hold an Eloquent snapshot from before another
        // transaction posted this GRN line. Serialize on the persisted line
        // and read its current handoff references before any stock mutation.
        // Use the locked canonical values for quantity, product and warehouse
        // too: caller-owned snapshots must not override committed draft data.
        $persistedLine = EnterpriseGoodsReceiptLine::query()
            ->whereKey((int) $line->getKey())
            ->where('goods_receipt_id', (int) $receipt->getKey())
            ->lockForUpdate()
            ->first();

        if ($persistedLine === null) {
            throw ValidationException::withMessages([
                'goods_receipt_id' => ['Goods receipt line no longer belongs to the authorized receipt.'],
            ]);
        }

        $line = $persistedLine;

        if ($line->stock_movement_id !== null || $line->inventory_id !== null) {
            throw ValidationException::withMessages([
                'status' => ['Goods receipt inventory handoff is already recorded for this line.'],
            ]);
        }

        // Draft line quantities can change after initial HTTP validation.
        // Validate again at the actual inventory posting boundary so an
        // adulterated zero/negative receipt cannot create an empty stock row
        // or debit stock under the guise of a purchase receipt.
        $quantity = (float) $line->received_quantity;
        if (! is_finite($quantity)
            || $quantity <= 0
            || $quantity > 99999999999.999
            || round($quantity, 3) <= 0
            || abs($quantity - round($quantity, 3)) > 0.000000001) {
            throw ValidationException::withMessages([
                'received_quantity' => ['A goods receipt must contain a finite positive quantity with at most three decimal places.'],
            ]);
        }

        $itemCode = trim((string) $line->item_code);
        if ($itemCode === '') {
            throw ValidationException::withMessages([
                'item_code' => [
                    sprintf(
                        'Goods receipt line %d has no item code and cannot be handed to inventory.',
                        $line->line_no,
                    ),
                ],
            ]);
        }

        // The purchase order line is the authoritative item/cost/unit
        // identity. A stored GRN draft is mutable until posting; its copied
        // snapshot cannot choose another active product in the same store.
        // Block a stale/forged snapshot BEFORE any inventory insert or debit.
        $orderLine = EnterprisePurchaseOrderLine::query()
            ->whereKey((int) $line->purchase_order_line_id)
            ->where('purchase_order_id', (int) $receipt->purchase_order_id)
            ->first(['item_code', 'unit', 'unit_price', 'quantity']);
        if ($orderLine === null
            || (string) $orderLine->item_code !== $itemCode
            || (string) $orderLine->unit !== (string) $line->unit
            || (string) $orderLine->unit_price !== (string) $line->unit_price
            || (string) $orderLine->quantity !== (string) $line->ordered_quantity) {
            throw ValidationException::withMessages([
                'purchase_order_line_id' => ['Goods receipt source item, unit, price and ordered quantity must match the issued purchase order.'],
            ]);
        }

        // Receipt creation and manager posting both check the PO remainder,
        // but a direct handoff call must enforce this stock boundary too.
        // The parent PO is locked above, serializing all GRN writers for
        // this order. Count real persisted handoffs, not only posted header
        // statuses: a direct caller may have written a movement while the
        // draft receipt header remains unposted.
        $previouslyHandedOff = DB::table('enterprise_goods_receipt_lines')
            ->where('purchase_order_line_id', (int) $line->purchase_order_line_id)
            ->whereNotNull('stock_movement_id')
            ->sum('received_quantity');

        $alreadyReceivedUnits = (int) round((float) $previouslyHandedOff * 1000);
        $requestedUnits = (int) round($quantity * 1000);
        $orderedUnits = (int) round((float) $orderLine->quantity * 1000);

        if ($alreadyReceivedUnits + $requestedUnits > $orderedUnits) {
            throw ValidationException::withMessages([
                'received_quantity' => [
                    'Goods receipt would exceed the outstanding quantity of its issued purchase order line.',
                ],
            ]);
        }

        $product = Product::query()
            ->forStore($storeId)
            ->where('sku', $itemCode)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            throw ValidationException::withMessages([
                'item_code' => [
                    sprintf(
                        'Item code %s is not an active product in the receiving store.',
                        $itemCode,
                    ),
                ],
            ]);
        }

        $warehouse = Warehouse::query()
            ->whereKey((int) $line->warehouse_id)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The receiving warehouse is not active in the authorized store.'],
            ]);
        }

        $inventory = Inventory::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            $inventory = Inventory::query()->create([
                'warehouse_id' => $warehouse->getKey(),
                'product_id' => $product->getKey(),
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]);
        }

        // The source supplier on a PO/GRN is commercial identity, not
        // independently checked legal title. Block any already-claimed title
        // until a legitimate per-owner receipt allocation writer exists.
        $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventory->getKey());

        $inventory->forceFill([
            'quantity' => round((float) $inventory->quantity + $quantity, 3),
        ])->save();

        // Unknown is not supplier or FOODEX title; maintain only the wholly
        // unattributed projection in the surrounding GRN posting transaction.
        $this->ownerProjection->afterProcurementMutation($inventory);

        $movement = StockMovement::query()->create([
            'inventory_id' => $inventory->getKey(),
            'store_id' => $storeId,
            'user_id' => $actor->getKey(),
            'type' => 'purchase_receipt',
            'quantity' => $quantity,
            'reference_type' => 'enterprise_goods_receipt_line',
            'reference_id' => $line->getKey(),
            'reason' => sprintf(
                'GRN %s / PO %s',
                $receipt->receipt_number,
                $receipt->source_order_number,
            ),
        ]);

        $line->forceFill([
            'inventory_id' => $inventory->getKey(),
            'stock_movement_id' => $movement->getKey(),
        ])->save();
    }
}
