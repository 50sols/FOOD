<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryAdjustmentService;
use App\Enterprise\Wms\InventoryCountService;
use App\Enterprise\Wms\InventoryOwnerProvenanceReadService;
use App\Enterprise\Wms\InventoryOwnershipRuleService;
use App\Enterprise\Wms\StockReservationService;
use App\Enterprise\Wms\UnknownInventoryOwnerProjection;
use App\Enterprise\Wms\WarehouseTransferService;
use App\Models\Inventory;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Enterprise\Concerns\EnablesEnterpriseWms;
use Tests\TestCase;

final class V2InventoryOwnerProvenanceTest extends TestCase
{
    use EnablesEnterpriseWms;
    use RefreshDatabase;

    public function test_upgrade_marks_existing_inventory_unknown_without_changing_stock_or_reservations(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('LEGACY');
        $migration = require database_path('migrations/2026_11_15_000100_create_inventory_owner_provenance_positions.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('enterprise_inventory_owner_positions'));
        $migration->up();

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'quantity' => 10,
            'reserved_quantity' => 3,
            'certified_by' => null,
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);

        $actor = $this->inventoryActor($stock['store_id']);
        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('10.000', $snapshot['quantity']);
        $this->assertSame('3.000', $snapshot['reserved_quantity']);
    }

    public function test_new_inventory_without_ownership_writers_is_unclassified_not_free_foodex_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('NEW');
        $actor = $this->inventoryActor($stock['store_id']);

        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('UNKNOWN', $snapshot['positions'][0]['owner_kind']);
        $this->assertSame('10.000', $snapshot['positions'][0]['quantity']);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
    }

    public function test_missing_owner_positions_never_mask_invalid_canonical_balances_as_merely_unknown(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('INVALID-CANONICAL-NO-OWNER');
        $actor = $this->inventoryActor($stock['store_id']);
        $service = app(InventoryOwnerProvenanceReadService::class);

        foreach ([
            ['quantity' => 10, 'reserved_quantity' => 11, 'label' => 'over-reserved'],
            ['quantity' => -1, 'reserved_quantity' => 0, 'label' => 'negative stock'],
            ['quantity' => 10, 'reserved_quantity' => -1, 'label' => 'negative reservation'],
        ] as $invalid) {
            DB::table('inventories')->where('id', $stock['inventory_id'])->update([
                'quantity' => $invalid['quantity'],
                'reserved_quantity' => $invalid['reserved_quantity'],
                'updated_at' => now()->addMinute(),
            ]);

            $result = $service->snapshot($actor, $stock['inventory_id']);
            $this->assertSame('STALE', $result['state'], $invalid['label']);
            $this->assertFalse($result['certified']);
            $this->assertSame('UNKNOWN', $result['positions'][0]['owner_kind']);
            $this->assertFalse($result['positions'][0]['certified']);
            $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
                ->where('inventory_id', $stock['inventory_id'])->count());
        }

        DB::table('inventories')->where('id', $stock['inventory_id'])->update([
            'quantity' => 10,
            'reserved_quantity' => 3,
            'updated_at' => now()->addMinutes(2),
        ]);
        $restored = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $restored['state']);
        $this->assertFalse($restored['certified']);
        $this->assertSame('10.000', $restored['quantity']);
        $this->assertSame('3.000', $restored['reserved_quantity']);
    }

    public function test_self_approval_and_missing_provenance_cannot_certify_foodex_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('SOD');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Independent Stock Checker',
            'email' => 'checker-stock@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $actor->id, false);

        $service = app(InventoryOwnerProvenanceReadService::class);
        $this->assertSame('UNVERIFIED', $service->snapshot($actor, $stock['inventory_id'])['state']);

        DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->update(['certified_by' => $checker->id]);
        $this->assertSame('UNVERIFIED', $service->snapshot($actor, $stock['inventory_id'])['state']);

        DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->update([
                'evidence_type' => 'goods_receipt_legal_title',
                'evidence_reference' => 'TEST-TITLE-001',
                'certified_at' => now(),
            ]);
        // A self-described document and a second user are NOT independent
        // verification of legal ownership. This must remain fail-closed.
        $untrusted = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $untrusted['state']);
        $this->assertFalse($untrusted['certified']);
        $this->assertFalse($untrusted['positions'][0]['certified']);
    }

    public function test_supplier_title_cannot_be_certified_from_forged_evidence_and_distinct_checker_ids(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('FORGED-TITLE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Separate But Unverified Checker',
            'email' => 'checker-unverified@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $supplierId = $this->supplier($stock['store_id']);

        // All database fields can look internally consistent while evidence
        // is nothing more than attacker-controlled free-text metadata.
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 10, 3, $actor->id, $checker->id, true);
        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $stock['inventory_id']);

        $this->assertSame('UNVERIFIED', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('SUPPLIER', $snapshot['positions'][0]['owner_kind']);
        $this->assertSame($supplierId, $snapshot['positions'][0]['supplier_id']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
        $this->assertSame('10.000', $snapshot['quantity']);
        $this->assertSame('3.000', $snapshot['reserved_quantity']);
    }

    public function test_mixed_supplier_and_foodex_positions_are_stale_after_any_unaccounted_stock_change(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('MIXED');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Independent Position Checker',
            'email' => 'checker-mixed@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $supplierId = $this->supplier($stock['store_id']);
        $this->position($stock['inventory_id'], 'FOODEX', null, 6, 2, $actor->id, $checker->id, true);
        $this->position($stock['inventory_id'], 'SUPPLIER', $supplierId, 4, 1, $actor->id, $checker->id, true);

        $service = app(InventoryOwnerProvenanceReadService::class);
        $snapshot = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNVERIFIED', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame(2, count($snapshot['positions']));
        $this->assertSame($supplierId, $snapshot['positions'][1]['supplier_id']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
        $this->assertFalse($snapshot['positions'][1]['certified']);

        DB::table('inventories')->where('id', $stock['inventory_id'])
            ->update(['quantity' => 11, 'updated_at' => now()->addMinute()]);
        $changed = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $changed['state']);
        $this->assertFalse($changed['certified']);
        $this->assertSame('11.000', $changed['quantity']);
        $this->assertFalse($changed['positions'][0]['certified']);
        $this->assertFalse($changed['positions'][1]['certified']);
    }

    public function test_foreign_store_supplier_cannot_be_certified_as_local_legal_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $own = $this->stock('LOCAL-SUP');
        $foreign = $this->stock('FOREIGN-SUP');
        $actor = $this->inventoryActor($own['store_id']);
        $checker = User::query()->create([
            'name' => 'Scoped Independent Checker',
            'email' => 'checker-scoped@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $foreignSupplierId = $this->supplier($foreign['store_id']);
        $this->position($own['inventory_id'], 'FOODEX', null, 6, 2, $actor->id, $checker->id, true);
        $this->position($own['inventory_id'], 'SUPPLIER', $foreignSupplierId, 4, 1, $actor->id, $checker->id, true);

        $snapshot = app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $own['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertNull($snapshot['positions'][1]['supplier_id']);
        $this->assertFalse($snapshot['positions'][0]['certified']);
        $this->assertFalse($snapshot['positions'][1]['certified']);
    }

    public function test_unknown_projection_tracks_canonical_stock_without_claiming_legal_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('UNKNOWN-RECEIPT');
        $inventory = Inventory::query()->findOrFail($stock['inventory_id']);

        DB::transaction(function () use ($inventory): void {
            $inventory->forceFill(['quantity' => 12])->save();
            app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
        });

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 12,
            'reserved_quantity' => 3,
            'certified_by' => null,
        ]);

        DB::transaction(function () use ($inventory): void {
            $inventory->forceFill(['quantity' => 9])->save();
            app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
        });

        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'quantity' => 9,
            'reserved_quantity' => 3,
        ]);
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($this->inventoryActor($stock['store_id']), $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
        $this->assertSame('9.000', $snapshot['quantity']);
        $this->assertSame('3.000', $snapshot['reserved_quantity']);
    }

    public function test_unknown_projection_does_not_rewrite_known_owner_assertions_or_hide_stale_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('MIXED-PRESERVED');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Separate Title Checker',
            'email' => 'p110-mixed-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $inventory = Inventory::query()->findOrFail($stock['inventory_id']);

        DB::transaction(function () use ($inventory): void {
            $inventory->forceFill(['quantity' => 11])->save();
            app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
        });

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'FOODEX',
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->where('owner_key', 'UNKNOWN')->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_unknown_projection_is_rolled_back_with_failed_canonical_stock_mutation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ROLLBACK-PROJECTION');
        $inventory = Inventory::query()->findOrFail($stock['inventory_id']);

        try {
            DB::transaction(function () use ($inventory): void {
                $inventory->forceFill(['quantity' => 15])->save();
                app(UnknownInventoryOwnerProjection::class)->afterProcurementMutation($inventory);
                throw new \RuntimeException('simulated movement failure');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated movement failure', $exception->getMessage());
        }
        // If no exception occurs, the committed stock/position assertions
        // below still fail. A real rollback must preserve both together.

        $this->assertSame(10.0, (float) DB::table('inventories')
            ->where('id', $stock['inventory_id'])->value('quantity'));
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
    }

    public function test_wms_dispatch_and_receive_keep_unattributed_positions_current_without_stock_double_posting(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('TRANSFER-UNKNOWN');
        $target = $this->transferTarget($stock, 'TRANSFER-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $service = app(WarehouseTransferService::class);

        $transfer = $service->create(
            $actor,
            $target['source_warehouse_id'],
            $target['target_warehouse_id'],
            [['product_id' => $target['product_id'], 'quantity' => 2]],
            'p110-unknown-transfer',
        );

        $service->dispatch($actor, $transfer->id);
        $service->dispatch($actor, $transfer->id);
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'],
            'quantity' => 8,
            'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'quantity' => 8,
            'reserved_quantity' => 3,
        ]);

        $service->receive($actor, $transfer->id);
        $service->receive($actor, $transfer->id);
        $this->assertDatabaseHas('inventories', [
            'id' => $target['target_inventory_id'],
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $target['target_inventory_id'],
            'owner_key' => 'UNKNOWN',
            'owner_kind' => 'UNKNOWN',
            'supplier_id' => null,
            'quantity' => 3,
            'reserved_quantity' => 0,
        ]);

        $this->assertSame(2, DB::table('enterprise_inventory_owner_positions')
            ->whereIn('inventory_id', [$stock['inventory_id'], $target['target_inventory_id']])
            ->count());
        $this->assertSame(2, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_warehouse_transfer')
            ->where('reference_id', $transfer->id)
            ->count());

        $read = app(InventoryOwnerProvenanceReadService::class);
        foreach ([$stock['inventory_id'], $target['target_inventory_id']] as $inventoryId) {
            $snapshot = $read->snapshot($actor, $inventoryId);
            $this->assertSame('UNKNOWN', $snapshot['state']);
            $this->assertFalse($snapshot['certified']);
            $this->assertSame('UNKNOWN', $snapshot['positions'][0]['owner_kind']);
        }
    }

    public function test_wms_transfer_never_silently_converts_unverified_foodex_title_to_unknown_or_certified(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('TRANSFER-KNOWN');
        $target = $this->transferTarget($stock, 'TRANSFER-KNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'WMS Distinct Checker',
            'email' => 'p110-wms-distinct-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $service = app(WarehouseTransferService::class);
        $transfer = $service->create(
            $actor,
            $target['source_warehouse_id'],
            $target['target_warehouse_id'],
            [['product_id' => $target['product_id'], 'quantity' => 2]],
            'p110-unverified-transfer',
        );

        $service->dispatch($actor, $transfer->id);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'],
            'owner_key' => 'FOODEX',
            'quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])
            ->where('owner_key', 'UNKNOWN')->count());

        $read = app(InventoryOwnerProvenanceReadService::class);
        $source = $read->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $source['state']);
        $this->assertFalse($source['certified']);

        $service->receive($actor, $transfer->id);
        $destination = $read->snapshot($actor, $target['target_inventory_id']);
        $this->assertSame('UNKNOWN', $destination['state']);
        $this->assertFalse($destination['certified']);
        $this->assertSame('UNKNOWN', $destination['positions'][0]['owner_kind']);
        $this->assertDatabaseHas('inventories', ['id' => $stock['inventory_id'], 'quantity' => 8]);
        $this->assertDatabaseHas('inventories', ['id' => $target['target_inventory_id'], 'quantity' => 3]);
    }

    public function test_wms_adjustment_posts_current_unknown_title_once_and_replay_has_no_second_stock_mutation(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ADJUST-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        $service = app(InventoryAdjustmentService::class);

        $movement = $service->post(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'correction', 'p110_unknown_adjustment', 1102844,
            'Verified stock correction, title still unknown',
        );
        $replay = $service->post(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'correction', 'p110_unknown_adjustment', 1102844,
            'Verified stock correction, title still unknown',
        );

        $this->assertSame($movement->getKey(), $replay->getKey());
        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 12, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 12, 'reserved_quantity' => 3, 'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_cycle_count_reconciles_unknown_title_in_same_inventory_transaction(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('COUNT-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $warehouseId = (int) DB::table('inventories')->where('id', $stock['inventory_id'])
            ->value('warehouse_id');
        $service = app(InventoryCountService::class);

        $count = $service->start($actor, $warehouseId, [$stock['inventory_id']], 'p110-count-unknown-title');
        $service->complete($actor, $count->getKey(), [$stock['inventory_id'] => 12]);
        $service->complete($actor, $count->getKey(), [$stock['inventory_id'] => 12]);

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 12, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 12, 'reserved_quantity' => 3, 'certified_at' => null,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'enterprise_inventory_count')
            ->where('reference_id', $count->getKey())->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_adjustment_never_reclassifies_an_unverified_owner_position(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('ADJUST-PRESERVE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Stock Title Checker',
            'email' => 'p110-adjust-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        app(InventoryAdjustmentService::class)->post(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            -1.0, 'loss', 'p110_preserve_title', 1102845,
            'Unverified title must not be reallocated',
        );

        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'FOODEX',
            'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->where('owner_key', 'UNKNOWN')->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_reservation_and_release_reconcile_unknown_title_and_replays_do_not_double_post(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-UNKNOWN');
        $actor = $this->inventoryActor($stock['store_id']);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        $service = app(StockReservationService::class);

        $this->assertSame(2.0, $service->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'p110_owner_reservation', 1102846,
        ));
        $this->assertSame(2.0, $service->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'p110_owner_reservation', 1102846,
        ));

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 5,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 10, 'reserved_quantity' => 5, 'certified_by' => null,
        ]);
        $this->assertSame(1, DB::table('stock_movements')
            ->where('reference_type', 'p110_owner_reservation')->where('reference_id', 1102846)
            ->where('type', 'reserve')->count());

        $this->assertSame(0.0, $service->releaseReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            'p110_owner_reservation', 1102846,
        ));
        $this->assertSame(0.0, $service->releaseReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            'p110_owner_reservation', 1102846,
        ));

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 10, 'reserved_quantity' => 3, 'certified_at' => null,
        ]);
        $this->assertSame(1, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $this->assertSame(2, DB::table('stock_movements')
            ->where('reference_type', 'p110_owner_reservation')->where('reference_id', 1102846)
            ->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('UNKNOWN', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_reservation_never_reclassifies_a_claimed_foodex_title(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-PRESERVE');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Reservation Title Checker',
            'email' => 'p110-reservation-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'FOODEX', null, 10, 3, $actor->id, $checker->id, true);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);

        app(StockReservationService::class)->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            1.0, 'p110_unverified_owner_reservation', 1102847,
        );

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 4,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'FOODEX',
            'quantity' => 10, 'reserved_quantity' => 3,
        ]);
        $this->assertSame(0, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->where('owner_key', 'UNKNOWN')->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_wms_reservation_does_not_refresh_unknown_when_mixed_legal_title_is_present(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('RESERVE-MIXED');
        $actor = $this->inventoryActor($stock['store_id']);
        $checker = User::query()->create([
            'name' => 'Mixed Title Checker',
            'email' => 'p110-reservation-mixed-title-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $this->position($stock['inventory_id'], 'UNKNOWN', null, 6, 2, $actor->id, $checker->id, false);
        $this->position($stock['inventory_id'], 'FOODEX', null, 4, 1, $actor->id, $checker->id, true);
        $inventory = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);

        app(StockReservationService::class)->ensureReservation(
            $actor, (int) $inventory->warehouse_id, (int) $inventory->product_id,
            2.0, 'p110_mixed_title_reservation', 1102848,
        );

        $this->assertDatabaseHas('inventories', [
            'id' => $stock['inventory_id'], 'quantity' => 10, 'reserved_quantity' => 5,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'UNKNOWN',
            'quantity' => 6, 'reserved_quantity' => 2,
        ]);
        $this->assertDatabaseHas('enterprise_inventory_owner_positions', [
            'inventory_id' => $stock['inventory_id'], 'owner_key' => 'FOODEX',
            'quantity' => 4, 'reserved_quantity' => 1,
        ]);
        $this->assertSame(2, DB::table('enterprise_inventory_owner_positions')
            ->where('inventory_id', $stock['inventory_id'])->count());
        $snapshot = app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('STALE', $snapshot['state']);
        $this->assertFalse($snapshot['certified']);
    }

    public function test_other_store_inventory_is_never_exposed_through_owner_read_service(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $own = $this->stock('OWN');
        $foreign = $this->stock('FOREIGN');
        $actor = $this->inventoryActor($own['store_id']);
        $this->expectException(HttpException::class);
        app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $foreign['inventory_id']);
    }

    public function test_owner_rule_configuration_requires_independent_review_and_never_certifies_stock(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $stock = $this->stock('POLICY-RULE');
        $unitId = DB::table('enterprise_business_units')->value('id');
        DB::table('enterprise_store_scopes')->insert([
            'store_id' => $stock['store_id'],
            'business_unit_id' => $unitId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $maker = User::query()->create([
            'name' => 'Ownership Policy Maker',
            'email' => 'p110-policy-maker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $checker = User::query()->create([
            'name' => 'Independent Ownership Policy Checker',
            'email' => 'p110-policy-checker@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        foreach ([$maker, $checker] as $user) {
            DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $role->id]);
        }

        $service = app(InventoryOwnershipRuleService::class);
        $request = [
            'store_id' => $stock['store_id'],
            'channel' => 'B2C',
            'warehouse_id' => DB::table('inventories')->where('id', $stock['inventory_id'])->value('warehouse_id'),
            'initial_owner_kind' => 'FOODEX',
            'title_transfer_trigger' => 'CONTRACT_EVENT',
            'required_evidence_classes' => ['SIGNED_CONTRACT', 'TITLE_ADDENDUM'],
            'checker_permission' => 'inventory_ownership_rules.review',
            'effective_at' => now()->addDays(2)->startOfDay()->toDateTimeString(),
            'request_uuid' => (string) Str::uuid(),
        ];
        $draft = $service->propose($maker, $request);
        $this->assertSame('DRAFT', $draft->status);
        $this->assertSame($draft->id, $service->propose($maker, $request)->id);
        $this->assertSame(1, DB::table('enterprise_inventory_ownership_rule_revisions')->count());

        try {
            $service->review($maker, (int) $draft->id, true);
            $this->fail('A maker must not review their own rule, even as Super Admin.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $approved = $service->review($checker, (int) $draft->id, true);
        $this->assertSame('APPROVED_RULE', $approved->status);
        $this->assertSame($approved->id, $service->review($checker, (int) $draft->id, true)->id);
        $this->travelTo(now()->addDays(3));
        $advisory = $service->advisoryFor($checker, $stock['store_id'], 'B2C', null, (int) $request['warehouse_id']);
        $this->assertSame('UNKNOWN', $advisory['state']);
        $this->assertFalse($advisory['certified']);
        $this->assertSame($approved->id, $advisory['policy']->id);
        $this->assertSame('UNKNOWN', app(InventoryOwnerProvenanceReadService::class)
            ->snapshot($this->inventoryActor($stock['store_id']), $stock['inventory_id'])['state']);
        $this->travelBack();

        $conflictingRequest = $request;
        $conflictingRequest['request_uuid'] = (string) Str::uuid();
        $duplicateScope = $service->propose($maker, $conflictingRequest);
        try {
            $service->review($checker, (int) $duplicateScope->id, true);
            $this->fail('Overlapping approved policy identities must not coexist.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $migration = require database_path('migrations/2026_11_16_030000_create_enterprise_inventory_ownership_rule_revisions.php');
        try {
            $migration->down();
            $this->fail('Immutable policy/review history must not disappear on rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('preserved', $e->getMessage());
        }
        $this->assertDatabaseHas('enterprise_inventory_ownership_rule_revisions', [
            'id' => $draft->id,
            'status' => 'APPROVED_RULE',
            'reviewed_by' => $checker->id,
        ]);
    }

    public function test_owner_rule_configuration_denies_wrong_store_supplier_and_retroactivity(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $home = $this->stock('POLICY-HOME');
        $foreign = $this->stock('POLICY-FOREIGN');
        DB::table('enterprise_store_scopes')->insert([
            'store_id' => $home['store_id'],
            'business_unit_id' => DB::table('enterprise_business_units')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = User::query()->create([
            'name' => 'Ownership Rule Admin',
            'email' => 'p110-ownership-admin@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        DB::table('role_user')->insert(['user_id' => $actor->id, 'role_id' => $role->id]);
        $service = app(InventoryOwnershipRuleService::class);
        $request = [
            'store_id' => $home['store_id'],
            'channel' => 'B2C',
            'warehouse_id' => DB::table('inventories')->where('id', $foreign['inventory_id'])->value('warehouse_id'),
            'initial_owner_kind' => 'UNKNOWN',
            'title_transfer_trigger' => 'NONE',
            'required_evidence_classes' => ['SIGNED_CONTRACT'],
            'checker_permission' => 'inventory_ownership_rules.review',
            'effective_at' => now()->addDay()->toDateTimeString(),
            'request_uuid' => (string) Str::uuid(),
        ];
        try {
            $service->propose($actor, $request);
            $this->fail('Cross-store warehouse policy must fail closed.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $request['warehouse_id'] = null;
        $request['initial_owner_kind'] = 'SUPPLIER';
        $request['supplier_id'] = $this->supplier($foreign['store_id']);
        try {
            $service->propose($actor, $request);
            $this->fail('A foreign-store supplier cannot be scoped into this store.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        unset($request['supplier_id']);
        $request['initial_owner_kind'] = 'UNKNOWN';
        $request['effective_at'] = now()->subDay()->toDateTimeString();
        try {
            $service->propose($actor, $request);
            $this->fail('Retroactive policy must fail closed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_at', $e->errors());
        }
        $this->assertSame(0, DB::table('enterprise_inventory_ownership_rule_revisions')->count());
    }

    /** @return array{store_id:int,inventory_id:int} */
    private function stock(string $suffix): array
    {
        $storeType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'code' => 'OWN-'.$suffix,
            'name' => 'Owner Stock '.$suffix,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA-OWN-'.$suffix,
            'name' => 'Each',
            'decimal_places' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'catalog-own-'.$suffix,
            'name' => 'Owner Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'SKU-OWN-'.$suffix,
            'name' => 'Owner Stock Item',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $warehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $storeId,
            'code' => 'WH-OWN-'.$suffix,
            'name' => 'Owner Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $inventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
            'reserved_quantity' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['store_id' => $storeId, 'inventory_id' => $inventoryId];
    }

    /** @param array{store_id:int,inventory_id:int} $stock
     * @return array{source_warehouse_id:int,target_warehouse_id:int,target_inventory_id:int,product_id:int}
     */
    private function transferTarget(array $stock, string $suffix): array
    {
        $source = DB::table('inventories')->where('id', $stock['inventory_id'])
            ->first(['warehouse_id', 'product_id']);
        $targetWarehouseId = (int) DB::table('warehouses')->insertGetId([
            'store_id' => $stock['store_id'],
            'code' => 'P110-WMS-TARGET-'.$suffix,
            'name' => 'P110 Target Warehouse',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetInventoryId = (int) DB::table('inventories')->insertGetId([
            'warehouse_id' => $targetWarehouseId,
            'product_id' => (int) $source->product_id,
            'quantity' => 1,
            'reserved_quantity' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'source_warehouse_id' => (int) $source->warehouse_id,
            'target_warehouse_id' => $targetWarehouseId,
            'target_inventory_id' => $targetInventoryId,
            'product_id' => (int) $source->product_id,
        ];
    }

    private function inventoryActor(int $storeId): User
    {
        $this->enableEnterpriseWmsForStore($storeId);
        $actor = User::query()->create([
            'name' => 'Owner Read Operator',
            'email' => 'owner-read-'.$storeId.'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $role = Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail();
        UserStoreRole::query()->create([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
        ]);

        return $actor;
    }

    private function supplier(int $storeId): int
    {
        $company = DB::table('enterprise_companies')->first(['id', 'organization_id']);

        return (int) DB::table('enterprise_suppliers')->insertGetId([
            'organization_id' => $company->organization_id,
            'company_id' => $company->id,
            'store_id' => $storeId,
            'scope_type' => 'store',
            'code' => 'LEGAL-OWNER-'.$storeId,
            'name_en' => 'Verified Supplier Fixture',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function position(
        int $inventoryId,
        string $kind,
        ?int $supplierId,
        int $quantity,
        int $reserved,
        int $proposer,
        int $checker,
        bool $withEvidence,
    ): void {
        DB::table('enterprise_inventory_owner_positions')->insert([
            'inventory_id' => $inventoryId,
            'owner_key' => $kind === 'SUPPLIER' ? 'SUPPLIER:'.$supplierId : $kind,
            'owner_kind' => $kind,
            'supplier_id' => $supplierId,
            'quantity' => $quantity,
            'reserved_quantity' => $reserved,
            'source_inventory_updated_at' => DB::table('inventories')
                ->where('id', $inventoryId)->value('updated_at'),
            'evidence_type' => $withEvidence ? 'goods_receipt_legal_title' : null,
            'evidence_reference' => $withEvidence ? 'TEST-PROOF-'.$inventoryId : null,
            'proposed_by' => $proposer,
            'certified_by' => $checker,
            'certified_at' => $withEvidence ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
