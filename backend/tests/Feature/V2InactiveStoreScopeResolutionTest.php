<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: inactive stores retain their mapping but lose effective enterprise scope. */
final class V2InactiveStoreScopeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_deactivated_store_cannot_resolve_effective_scope_but_reactivation_restores_it(): void
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P031-INACTIVE-STORE-SCOPE',
            'name' => 'P031 Inactive Store Scope',
            'is_active' => true,
        ]);
        $service = app(OrganizationHierarchyService::class);
        $service->ensureDefaultScopeForStore($store);

        $scope = $service->scopeForStore((int) $store->id);
        $this->assertNotNull($scope);
        $this->assertSame((int) $store->id, $scope['store_id']);
        $mapping = DB::table('enterprise_store_scopes')->where('store_id', $store->id)->first();
        $this->assertNotNull($mapping);
        $auditCount = DB::table('audit_logs')->count();

        // Deactivation takes place independently of the retained Eloquent
        // object and its historical enterprise_store_scopes row.
        DB::table('stores')->where('id', $store->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $store->is_active);
        $this->assertNull($service->scopeForStore((int) $store->id));
        $this->assertDatabaseHas('enterprise_store_scopes', [
            'id' => $mapping->id,
            'store_id' => $store->id,
            'business_unit_id' => $mapping->business_unit_id,
        ]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        DB::table('stores')->where('id', $store->id)->update(['is_active' => true]);
        $this->assertSame($scope, $service->scopeForStore((int) $store->id));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_unknown_or_unmapped_store_has_no_effective_scope(): void
    {
        $this->assertNull(
            app(OrganizationHierarchyService::class)->scopeForStore(999999),
        );
    }
}
