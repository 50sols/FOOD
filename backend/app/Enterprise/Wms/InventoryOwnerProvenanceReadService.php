<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A conservative read boundary for supplier/FOODEX legal stock title.
 *
 * Never use an aggregate inventory balance, legacy SKU association or standard
 * WMS unit cost as proof of legal ownership. An unapproved or stale partition
 * must not become a quote-time profitability/valuation authority.
 */
final class InventoryOwnerProvenanceReadService
{
    public function __construct(
        private readonly WarehouseScope $warehouses,
    ) {}

    /**
     * @return array{
     *   inventory_id:int,store_id:int,warehouse_id:int,product_id:int,
     *   quantity:string,reserved_quantity:string,state:string,
     *   certified:bool,positions:list<array{
     *     owner_kind:string,supplier_id:?int,quantity:string,
     *     reserved_quantity:string,certified:bool
     *   }>
     * }
     */
    public function snapshot(User $actor, int $inventoryId): array
    {
        $inventory = DB::table('inventories')
            ->where('id', $inventoryId)
            ->first();

        abort_if($inventory === null, 404);

        $warehouse = $this->warehouses->assertWarehouse(
            $actor,
            (int) $inventory->warehouse_id,
            'inventory.view',
        );

        $rows = DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $inventoryId)
            ->orderBy('owner_key')
            ->get();

        $quantity = self::millis($inventory->quantity);
        $reserved = self::millis($inventory->reserved_quantity);

        // New stock rows created by existing writers have no partition event.
        // Expose the entire balance as UNKNOWN rather than a misleading zero
        // or a certified FOODEX amount.
        if ($rows->isEmpty()) {
            return [
                'inventory_id' => $inventoryId,
                'store_id' => (int) $warehouse->store_id,
                'warehouse_id' => (int) $inventory->warehouse_id,
                'product_id' => (int) $inventory->product_id,
                'quantity' => self::decimal($quantity),
                'reserved_quantity' => self::decimal($reserved),
                'state' => 'UNKNOWN',
                'certified' => false,
                'positions' => [[
                    'owner_kind' => 'UNKNOWN',
                    'supplier_id' => null,
                    'quantity' => self::decimal($quantity),
                    'reserved_quantity' => self::decimal($reserved),
                    'certified' => false,
                ]],
            ];
        }

        $total = 0;
        $totalReserved = 0;
        $allCertifiable = $rows->isNotEmpty();
        $unknown = $rows->isEmpty();
        $positions = [];

        foreach ($rows as $row) {
            $kind = (string) $row->owner_kind;
            $supplierId = $row->supplier_id === null ? null : (int) $row->supplier_id;
            $validKey = match ($kind) {
                'UNKNOWN' => $row->owner_key === 'UNKNOWN' && $supplierId === null,
                'FOODEX' => $row->owner_key === 'FOODEX' && $supplierId === null,
                'SUPPLIER' => $supplierId !== null && $supplierId > 0
                    && $row->owner_key === 'SUPPLIER:'.$supplierId
                    && DB::table('enterprise_suppliers')
                        ->where('id', $supplierId)
                        ->where('store_id', (int) $warehouse->store_id)
                        ->exists(),
                default => false,
            };

            $proof = $validKey
                && $kind !== 'UNKNOWN'
                && $row->proposed_by !== null
                && $row->certified_by !== null
                && (int) $row->proposed_by !== (int) $row->certified_by
                && $row->certified_at !== null
                && trim((string) $row->evidence_type) !== ''
                && trim((string) $row->evidence_reference) !== '';

            // The source balance must still be the balance that was certified.
            // Ordinary inventory writers do not yet update ownership partitions.
            $sameVersion = $row->source_inventory_updated_at !== null
                && $inventory->updated_at !== null
                && (string) $row->source_inventory_updated_at === (string) $inventory->updated_at;

            $lineQuantity = self::millis($row->quantity);
            $lineReserved = self::millis($row->reserved_quantity);
            $total += $lineQuantity;
            $totalReserved += $lineReserved;
            $allCertifiable = $allCertifiable
                && $proof && $sameVersion
                && $lineQuantity >= 0 && $lineReserved >= 0
                && $lineReserved <= $lineQuantity;
            $unknown = $unknown || $kind === 'UNKNOWN' || ! $validKey;

            $positions[] = [
                'owner_kind' => $validKey ? $kind : 'UNKNOWN',
                'supplier_id' => $validKey ? $supplierId : null,
                'quantity' => self::decimal($lineQuantity),
                'reserved_quantity' => self::decimal($lineReserved),
                'certified' => $proof && $sameVersion,
            ];
        }

        $consistent = $total === $quantity
            && $totalReserved === $reserved
            && $quantity >= 0 && $reserved >= 0 && $reserved <= $quantity;

        $certified = $consistent && $allCertifiable && ! $unknown;
        $state = ! $consistent ? 'STALE'
            : ($unknown ? 'UNKNOWN'
                : ($certified ? 'CERTIFIED' : 'UNVERIFIED'));

        // Never expose a per-owner positive certification when the aggregate
        // inventory is stale, incomplete or includes unproven legal title.
        if (! $certified) {
            foreach ($positions as &$position) {
                $position['certified'] = false;
            }
            unset($position);
        }

        return [
            'inventory_id' => $inventoryId,
            'store_id' => (int) $warehouse->store_id,
            'warehouse_id' => (int) $inventory->warehouse_id,
            'product_id' => (int) $inventory->product_id,
            'quantity' => self::decimal($quantity),
            'reserved_quantity' => self::decimal($reserved),
            'state' => $state,
            'certified' => $certified,
            'positions' => $positions,
        ];
    }

    private static function millis(mixed $value): int
    {
        return (int) round(((float) $value) * 1000);
    }

    private static function decimal(int $millis): string
    {
        return number_format($millis / 1000, 3, '.', '');
    }
}
