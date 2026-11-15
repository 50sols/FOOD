<?php

namespace App\Enterprise\Wms;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reconcile legally UNATTRIBUTED stock with the canonical inventory row. This is not a legal-title attribution, valuation source, or second ledger.
 *
 * Called only while the canonical inventory is locked by the surrounding
 * procurement or WMS stock-posting transaction. Known-owner positions are
 * deliberately left stale: a transfer/receipt/return cannot infer legal title.
 */
final class UnknownInventoryOwnerProjection
{
    public function afterProcurementMutation(Inventory $inventory): void
    {
        $this->afterUnattributedInventoryMutation((int) $inventory->getKey());
    }

    /**
     * Refresh ONLY a wholly UNKNOWN stock position from already-posted canonical
     * inventory. The caller must hold the inventory row lock in this transaction.
     * This is a read-model projection, never a second stock posting or title grant.
     */
    public function afterUnattributedInventoryMutation(int $inventoryId): void
    {
        if ($inventoryId < 1 || DB::transactionLevel() < 1) {
            throw new LogicException('Inventory owner projection requires the canonical stock transaction.');
        }

        $rows = DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventoryId)
            ->lockForUpdate()
            ->get(['id', 'owner_key', 'owner_kind', 'supplier_id']);

        // Never rewrite, redistribute, or implicitly refresh a legal-owner
        // assertion. Without authenticated title evidence the entire set must
        // remain fail-closed/stale after the aggregate inventory mutation.
        if ($rows->count() > 1) {
            return;
        }

        if ($rows->count() === 1) {
            $row = $rows->first();
            if ($row->owner_key !== 'UNKNOWN'
                || $row->owner_kind !== 'UNKNOWN'
                || $row->supplier_id !== null) {
                return;
            }
        }

        // Read the already locked canonical row, not client-supplied quantity.
        $canonical = DB::table('inventories')
            ->where('id', $inventoryId)
            ->first(['quantity', 'reserved_quantity', 'updated_at']);

        if ($canonical === null) {
            throw new LogicException('Canonical inventory disappeared before owner projection.');
        }

        $data = [
            'quantity' => $canonical->quantity,
            'reserved_quantity' => $canonical->reserved_quantity,
            'source_inventory_updated_at' => $canonical->updated_at,
            'updated_at' => now(),
        ];

        if ($rows->isNotEmpty()) {
            DB::table('enterprise_inventory_owner_positions')
                ->where('id', $rows->first()->id)
                ->update($data);

            return;
        }

        DB::table('enterprise_inventory_owner_positions')->insert([
            'inventory_id' => $inventoryId,
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            ...$data,
            'created_at' => now(),
        ]);
    }
}
