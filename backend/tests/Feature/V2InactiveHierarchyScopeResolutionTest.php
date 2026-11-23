<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Store;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: deactivation revokes effective enterprise scope without deleting history. */
final class V2InactiveHierarchyScopeResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_disabled_ancestor_at_any_level_makes_existing_scope_unresolvable_without_mutation(): void
    {
        $store = $this->store('P031-SCOPE-READ-DISABLED');
        $service = app(OrganizationHierarchyService::class);
        $service->ensureDefaultScopeForStore($store);
        $scope = $service->scopeForStore((int) $store->id);
        $this->assertNotNull($scope);

        $scopeRecord = DB::table('enterprise_store_scopes')
            ->where('store_id', $store->id)
            ->first();
        $this->assertNotNull($scopeRecord);
        $auditCount = DB::table('audit_logs')->count();

        foreach ([
            ['enterprise_organizations', 'organization_id'],
            ['enterprise_companies', 'company_id'],
            ['enterprise_legal_entities', 'legal_entity_id'],
            ['enterprise_business_units', 'business_unit_id'],
        ] as [$table, $column]) {
            $id = $scope[$column];
            DB::table($table)->where('id', $id)->update(['is_active' => false]);

            $this->assertNull(
                $service->scopeForStore((int) $store->id),
                $table.' must revoke the effective enterprise scope.',
            );
            $this->assertDatabaseHas('enterprise_store_scopes', [
                'id' => $scopeRecord->id,
                'store_id' => $store->id,
                'business_unit_id' => $scope['business_unit_id'],
            ]);
            $this->assertSame($auditCount, DB::table('audit_logs')->count());

            DB::table($table)->where('id', $id)->update(['is_active' => true]);
            $this->assertSame(
                $scope,
                $service->scopeForStore((int) $store->id),
                $table.' reactivation must restore the exact scope.',
            );
        }

        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_store_without_enterprise_mapping_still_returns_null(): void
    {
        $store = $this->store('P031-SCOPE-READ-MISSING');
        $this->assertNull(
            app(OrganizationHierarchyService::class)->scopeForStore((int) $store->id),
        );
    }

    private function store(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
