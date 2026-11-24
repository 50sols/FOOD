<?php

namespace App\Domain\SmartOrdering;

use App\Enterprise\Wms\UnknownInventoryOwnerProjection;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class InventoryService
{
    public function __construct(
        private readonly UnknownInventoryOwnerProjection $ownerProjection,
    ) {}

    /** @param list<array{product_id:int,quantity:float}> $items */
    public function reserve(Order $order, array $items): void
    {
        DB::transaction(function () use ($order, $items): void {
            $this->reserveWithinTransaction($order, $items);
        }, attempts: 3);
    }

    /** @param list<array{product_id:int,quantity:float}> $items */
    private function reserveWithinTransaction(Order $order, array $items): void
    {
        foreach ($this->aggregate($items) as $productId => $quantity) {
            $rows = DB::table('inventories')
                ->select('inventories.id', 'inventories.quantity', 'inventories.reserved_quantity')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('warehouses.store_id', (int) $order->store_id)
                ->where('warehouses.is_active', true)
                ->where('inventories.product_id', $productId)
                ->orderBy('inventories.id')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $available = (float) $rows->sum(
                static fn (object $row): float => max(0.0, (float) $row->quantity - (float) $row->reserved_quantity),
            );
            abort_if($quantity > $available + 0.0001, 409, 'A selected product does not have enough stock.');

            $remaining = $quantity;
            foreach ($rows as $row) {
                $rowAvailable = max(0.0, (float) $row->quantity - (float) $row->reserved_quantity);
                $reserved = min($remaining, $rowAvailable);
                if ($reserved <= 0) {
                    continue;
                }

                $this->ownerProjection->assertUnattributedBeforeMutation((int) $row->id);

                DB::table('inventories')->where('id', $row->id)->increment('reserved_quantity', $reserved);
                $this->ownerProjection->afterUnattributedInventoryMutation((int) $row->id);
                DB::table('stock_movements')->insert([
                    'inventory_id' => (int) $row->id,
                    'store_id' => (int) $order->store_id,
                    'user_id' => null,
                    'type' => 'reserve',
                    'quantity' => $reserved,
                    'reference_type' => 'order',
                    'reference_id' => $order->getKey(),
                    'reason' => 'smart_ordering_checkout',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $remaining -= $reserved;
                if ($remaining <= 0.0001) {
                    break;
                }
            }
        }
    }

    public function release(Order $order, ?int $userId = null): void
    {
        DB::transaction(function () use ($order, $userId): void {
            $this->lockOrderForInventoryLifecycle($order);
            $this->releaseWithinTransaction($order, $userId);
        }, attempts: 3);
    }

    private function releaseWithinTransaction(Order $order, ?int $userId): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);
            if ($inventory === null) {
                continue;
            }

            abort_if($quantity > (float) $inventory->reserved_quantity + 0.0001,
                409, 'Order release exceeds canonical inventory reservations.');
            $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventoryId);

            DB::table('inventories')->where('id', $inventoryId)->update([
                'reserved_quantity' => (float) $inventory->reserved_quantity - $quantity,
                'updated_at' => now(),
            ]);
            $this->ownerProjection->afterUnattributedInventoryMutation((int) $inventoryId);
            $this->movement($order, $inventoryId, $userId, 'release', $quantity, 'smart_ordering_cancelled');
        }
    }

    public function consume(Order $order, ?int $userId = null): void
    {
        DB::transaction(function () use ($order, $userId): void {
            $this->lockOrderForInventoryLifecycle($order);
            $this->consumeWithinTransaction($order, $userId);
        }, attempts: 3);
    }

    private function consumeWithinTransaction(Order $order, ?int $userId): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);
            abort_unless($inventory !== null, 409, 'Reserved inventory no longer exists.');
            abort_if(
                $quantity > (float) $inventory->quantity + 0.0001
                || $quantity > (float) $inventory->reserved_quantity + 0.0001,
                409,
                'Reserved inventory is inconsistent with the order.',
            );

            $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventoryId);

            DB::table('inventories')->where('id', $inventoryId)->update([
                'quantity' => (float) $inventory->quantity - $quantity,
                'reserved_quantity' => (float) $inventory->reserved_quantity - $quantity,
                'updated_at' => now(),
            ]);
            $this->ownerProjection->afterUnattributedInventoryMutation((int) $inventoryId);
            $this->movement($order, $inventoryId, $userId, 'sale', -$quantity, 'smart_ordering_completed');
        }
    }

    private function lockOrderForInventoryLifecycle(Order $order): void
    {
        if ($order->getKey() === null) {
            throw new \LogicException('Smart ordering inventory lifecycle requires a persisted order key.');
        }

        DB::table('orders')->where('id', (int) $order->getKey())->lockForUpdate()->first();
    }

    /** @param list<array{product_id:int,quantity:float}> $items
     * @return array<int,float>
     */
    private function aggregate(array $items): array
    {
        $totals = [];
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $totals[$productId] = round(($totals[$productId] ?? 0.0) + (float) $item['quantity'], 3);
        }

        return $totals;
    }

    /** @return array<int,float> */
    private function activeReservations(Order $order): array
    {
        $balances = [];
        $rows = DB::table('stock_movements')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->whereIn('type', ['reserve', 'release', 'sale'])
            ->orderBy('id')
            ->get(['inventory_id', 'type', 'quantity']);

        foreach ($rows as $row) {
            $id = (int) $row->inventory_id;
            $balances[$id] ??= 0.0;
            $quantity = abs((float) $row->quantity);
            $balances[$id] += $row->type === 'reserve' ? $quantity : -$quantity;
        }

        return collect($balances)
            ->map(static fn (float $quantity): float => round(max(0.0, $quantity), 3))
            ->filter(static fn (float $quantity): bool => $quantity > 0)
            ->all();
    }

    private function movement(
        Order $order,
        int $inventoryId,
        ?int $userId,
        string $type,
        float $quantity,
        string $reason,
    ): void {
        DB::table('stock_movements')->insert([
            'inventory_id' => $inventoryId,
            'store_id' => (int) $order->store_id,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $quantity,
            'reference_type' => 'order',
            'reference_id' => $order->getKey(),
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
