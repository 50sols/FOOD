<?php

namespace App\Services;

use App\Enterprise\Wms\UnknownInventoryOwnerProjection;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderInventoryReservationService
{
    public function __construct(
        private readonly UnknownInventoryOwnerProjection $ownerProjection,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: float}>  $items
     */
    public function reserve(Order $order, User $user, array $items, string $reason = 'dashboard_order'): void
    {
        // This service can also be called directly by internal order workflows,
        // bypassing HTTP request rules. Never treat NaN, infinity, zero, a
        // negative or sub-milli-unit value as a successfully reserved order.
        // Validate the WHOLE batch before any canonical/owner stock mutation.
        foreach ($items as $item) {
            $validProduct = filter_var(
                $item['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]],
            ) !== false;
            $quantity = (float) $item['quantity'];

            if (! $validProduct
                || ! is_finite($quantity)
                || $quantity <= 0
                || $quantity > 99999999999.999
                || round($quantity, 3) <= 0
                || abs($quantity - round($quantity, 3)) > 0.000000001) {
                throw ValidationException::withMessages([
                    'items' => ['Order inventory requires a positive product ID and a finite positive quantity with at most three decimal places.'],
                ]);
            }
        }

        DB::transaction(function () use ($order, $user, $items, $reason): void {
            $this->lockOrderForInventoryLifecycle($order);
            $this->reserveWithinTransaction($order, $user, $items, $reason);
        }, attempts: 3);
    }

    /** @param list<array{product_id: int, quantity: float}> $items */
    private function reserveWithinTransaction(Order $order, User $user, array $items, string $reason): void
    {
        foreach ($items as $item) {
            $inventoryQuery = DB::table('inventories')
                ->select('inventories.id', 'inventories.quantity', 'inventories.reserved_quantity')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('warehouses.store_id', (int) $order->store_id)
                ->where('warehouses.is_active', true)
                ->where('inventories.product_id', $item['product_id']);

            if (strtolower((string) $order->channel) === 'b2b') {
                if ($order->warehouse_id === null) {
                    throw new HttpException(409, 'A Wholesale warehouse is required for this order.');
                }
                $inventoryQuery->where('warehouses.id', (int) $order->warehouse_id);
            }

            $inventoryRows = $inventoryQuery
                ->orderBy('inventories.id')
                ->lockForUpdate()
                ->get();

            if ($inventoryRows->isEmpty()) {
                if (strtolower((string) $order->channel) === 'b2b') {
                    throw new HttpException(409, 'A selected product is not stocked in the selected Wholesale warehouse.');
                }

                // Retail checkout keeps its existing non-stock-managed product behavior.
                continue;
            }

            $available = (float) $inventoryRows->sum(
                static fn (object $row): float => max(
                    0.0,
                    (float) $row->quantity - (float) $row->reserved_quantity,
                ),
            );

            if ($item['quantity'] > $available) {
                throw new HttpException(409, 'A selected product does not have enough stock.');
            }

            $remaining = $item['quantity'];
            foreach ($inventoryRows as $inventoryRow) {
                $rowAvailable = max(
                    0.0,
                    (float) $inventoryRow->quantity - (float) $inventoryRow->reserved_quantity,
                );
                $reserve = min($remaining, $rowAvailable);

                if ($reserve > 0) {
                    $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventoryRow->id);

                    DB::table('inventories')
                        ->where('id', $inventoryRow->id)
                        ->increment('reserved_quantity', $reserve);

                    // Same SQL transaction as the canonical reservation and
                    // immutable stock-movement event trigger.
                    $this->ownerProjection->afterUnattributedInventoryMutation((int) $inventoryRow->id);

                    StockMovement::query()->create([
                        'inventory_id' => (int) $inventoryRow->id,
                        'store_id' => (int) $order->store_id,
                        'user_id' => $user->getKey(),
                        'type' => 'reserve',
                        'quantity' => $reserve,
                        'reference_type' => 'order',
                        'reference_id' => $order->getKey(),
                        'reason' => $reason,
                    ]);

                    $remaining -= $reserve;
                }

                if ($remaining <= 0) {
                    break;
                }
            }
        }
    }

    public function release(Order $order, User $user, string $reason = 'order_cancelled'): void
    {
        DB::transaction(function () use ($order, $user, $reason): void {
            $this->lockOrderForInventoryLifecycle($order);
            $this->releaseWithinTransaction($order, $user, $reason);
        }, attempts: 3);
    }

    private function releaseWithinTransaction(Order $order, User $user, string $reason): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);

            if ($inventory === null) {
                // Never report a successful cancellation while an order's
                // reserved inventory has disappeared or crossed store scope.
                // The immutable reservation ledger must be reconciled first.
                throw new HttpException(409, 'Reserved inventory is missing from the order store.');
            }

            if ($quantity > (float) $inventory->reserved_quantity) {
                throw new HttpException(409, 'Order release exceeds canonical inventory reservations.');
            }
            $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventoryId);

            DB::table('inventories')
                ->where('id', $inventoryId)
                ->update([
                    'reserved_quantity' => (float) $inventory->reserved_quantity - $quantity,
                    'updated_at' => now(),
                ]);

            $this->ownerProjection->afterUnattributedInventoryMutation((int) $inventoryId);

            StockMovement::query()->create([
                'inventory_id' => $inventoryId,
                'store_id' => (int) $order->store_id,
                'user_id' => $user->getKey(),
                'type' => 'release',
                'quantity' => $quantity,
                'reference_type' => 'order',
                'reference_id' => $order->getKey(),
                'reason' => $reason,
            ]);
        }
    }

    public function consume(Order $order, User $user): void
    {
        DB::transaction(function () use ($order, $user): void {
            $this->lockOrderForInventoryLifecycle($order);
            $this->consumeWithinTransaction($order, $user);
        }, attempts: 3);
    }

    private function consumeWithinTransaction(Order $order, User $user): void
    {
        foreach ($this->activeReservations($order) as $inventoryId => $quantity) {
            $inventory = DB::table('inventories')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('inventories.id', $inventoryId)
                ->where('warehouses.store_id', (int) $order->store_id)
                ->lockForUpdate()
                ->first(['inventories.*']);

            if ($inventory === null) {
                throw new HttpException(409, 'Reserved inventory no longer exists.');
            }

            $onHand = (float) $inventory->quantity;
            $reserved = (float) $inventory->reserved_quantity;

            if ($quantity > $onHand || $quantity > $reserved) {
                throw new HttpException(409, 'Reserved inventory is inconsistent with the order.');
            }

            $this->ownerProjection->assertUnattributedBeforeMutation((int) $inventoryId);

            DB::table('inventories')
                ->where('id', $inventoryId)
                ->update([
                    'quantity' => $onHand - $quantity,
                    'reserved_quantity' => $reserved - $quantity,
                    'updated_at' => now(),
                ]);

            $this->ownerProjection->afterUnattributedInventoryMutation((int) $inventoryId);

            StockMovement::query()->create([
                'inventory_id' => $inventoryId,
                'store_id' => (int) $order->store_id,
                'user_id' => $user->getKey(),
                'type' => 'sale',
                'quantity' => -$quantity,
                'reference_type' => 'order',
                'reference_id' => $order->getKey(),
                'reason' => 'order_delivered',
            ]);
        }
    }

    /**
     * Release and consume must serialize on the actual order row before
     * reading reference-scoped stock movement balances. A second concurrent
     * delivery/cancellation cannot consume the same reservation twice.
     */
    private function lockOrderForInventoryLifecycle(Order $order): void
    {
        if ($order->getKey() === null) {
            throw new \LogicException('Order inventory lifecycle requires a persisted order key.');
        }

        $exists = DB::table('orders')->where('id', (int) $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->lockForUpdate()->first(['id']);
        if ($exists === null) {
            throw new HttpException(409, 'Order is not persisted in the requested store.');
        }
    }

    /** @return array<int, float> inventory_id => active reserved quantity */
    private function activeReservations(Order $order): array
    {
        $balances = [];

        $movements = StockMovement::query()
            ->where('reference_type', 'order')
            ->where('reference_id', $order->getKey())
            ->where('store_id', (int) $order->store_id)
            ->whereIn('type', ['reserve', 'release', 'sale'])
            ->orderBy('id')
            ->get(['inventory_id', 'type', 'quantity']);

        foreach ($movements as $movement) {
            $inventoryId = (int) $movement->inventory_id;
            $balances[$inventoryId] ??= 0.0;
            $quantity = abs((float) $movement->quantity);

            if ($movement->type === 'reserve') {
                $balances[$inventoryId] += $quantity;
            } else {
                $balances[$inventoryId] -= $quantity;
                // A release or sale with no matching reserved units is a
                // corrupted ledger, not a zero reservation. Do not conceal
                // it by clamping negative balances to zero.
                if ($balances[$inventoryId] < -0.0005) {
                    throw new HttpException(409, 'Order inventory movements exceed the original reservation.');
                }
            }
        }

        return collect($balances)
            ->map(static fn (float $quantity): float => round(max(0.0, $quantity), 3))
            ->filter(static fn (float $quantity): bool => $quantity > 0)
            ->all();
    }
}
