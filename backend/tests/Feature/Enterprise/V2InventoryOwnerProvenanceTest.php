<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\Wms\InventoryOwnerProvenanceReadService;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $verified = $service->snapshot($actor, $stock['inventory_id']);
        $this->assertSame('CERTIFIED', $verified['state']);
        $this->assertTrue($verified['certified']);
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
        $this->assertSame('CERTIFIED', $snapshot['state']);
        $this->assertSame(2, count($snapshot['positions']));
        $this->assertSame($supplierId, $snapshot['positions'][1]['supplier_id']);

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

    public function test_other_store_inventory_is_never_exposed_through_owner_read_service(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $own = $this->stock('OWN');
        $foreign = $this->stock('FOREIGN');
        $actor = $this->inventoryActor($own['store_id']);
        $this->expectException(HttpException::class);
        app(InventoryOwnerProvenanceReadService::class)->snapshot($actor, $foreign['inventory_id']);
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
