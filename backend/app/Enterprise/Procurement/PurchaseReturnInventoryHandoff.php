<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Wms\UnknownInventoryOwnerProjection;
use App\Models\EnterpriseGoodsReceiptLine;
use App\Models\EnterprisePurchaseReturn;
use App\Models\EnterprisePurchaseReturnLine;
use App\Models\Inventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnInventoryHandoff
{
    public function __construct(
        private readonly UnknownInventoryOwnerProjection $ownerProjection,
    ) {}

    public function reverse(
        User $actor,
        int $storeId,
        EnterprisePurchaseReturn $return,
        EnterprisePurchaseReturnLine $line,
        EnterpriseGoodsReceiptLine $sourceLine,
    ): void {
        if ($line->stock_movement_id !== null || $line->inventory_id !== null) {
            throw ValidationException::withMessages([
                'status' => ['Purchase return inventory reversal is already recorded for this line.'],
            ]);
        }

        if ($sourceLine->inventory_id === null || $sourceLine->stock_movement_id === null) {
            throw ValidationException::withMessages([
                'goods_receipt_line_id' => [
                    sprintf(
                        'Goods receipt line %d has no completed inventory handoff.',
                        $sourceLine->line_no,
                    ),
                ],
            ]);
        }

        // Protect the canonical stock boundary even if an internal caller
        // bypasses PurchaseReturnManager's request and saved-draft checks.
        // Negative returns must not mint on-hand stock; zero returns must not
        // create spurious movement/owner events. Validate before any writes.
        $rawQuantity = (float) $line->returned_quantity;
        if (! is_finite($rawQuantity)
            || $rawQuantity <= 0
            || $rawQuantity > 99999999999.999
            || round($rawQuantity, 3) <= 0
            || abs($rawQuantity - round($rawQuantity, 3)) > 0.000000001) {
            throw ValidationException::withMessages([
                'returned_quantity' => ['Purchase return stock reversal requires a finite positive quantity with at most three decimal places.'],
            ]);
        }

        if ((int) $sourceLine->warehouse_id !== (int) $line->warehouse_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['Purchase return warehouse must match the posted goods receipt line.'],
            ]);
        }

        $warehouse = Warehouse::query()
            ->whereKey((int) $sourceLine->warehouse_id)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse_id' => ['The source warehouse is not active in the authorized store.'],
            ]);
        }

        $inventory = Inventory::query()
            ->whereKey((int) $sourceLine->inventory_id)
            ->where('warehouse_id', $warehouse->getKey())
            ->lockForUpdate()
            ->first();

        if ($inventory === null) {
            throw ValidationException::withMessages([
                'inventory_id' => ['The received inventory is not available in the authorized store.'],
            ]);
        }

        $quantity = round($rawQuantity, 3);
        $current = round((float) $inventory->quantity, 3);
        $reserved = round((float) $inventory->reserved_quantity, 3);
        $next = round($current - $quantity, 3);

        if ($next < $reserved || $next < 0) {
            throw ValidationException::withMessages([
                'returned_quantity' => [
                    sprintf(
                        'Returning %s would reduce stock below reserved quantity %s.',
                        $line->returned_quantity,
                        $inventory->reserved_quantity,
                    ),
                ],
            ]);
        }

        // Preserve the existing business validation priority: an invalid
        // return below reservations is rejected with returned_quantity before
        // asking whether a separate legal-owner allocation is permitted.
        // Both guards still precede every canonical stock mutation.
        $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventory->getKey());

        $inventory->forceFill(['quantity' => $next])->save();

        // Preserve legal title uncertainty during partial return; do not
        // borrow quantities from a supplier/FOODEX owner's unverified bucket.
        $this->ownerProjection->afterProcurementMutation($inventory);

        $movement = StockMovement::query()->create([
            'inventory_id' => $inventory->getKey(),
            'store_id' => $storeId,
            'user_id' => $actor->getKey(),
            'type' => 'purchase_return',
            'quantity' => -$quantity,
            'reference_type' => 'enterprise_purchase_return_line',
            'reference_id' => $line->getKey(),
            'reason' => sprintf(
                'Purchase return %s / GRN %s',
                $return->return_number,
                $return->source_receipt_number,
            ),
        ]);

        $line->forceFill([
            'inventory_id' => $inventory->getKey(),
            'stock_movement_id' => $movement->getKey(),
        ])->save();
    }
}
