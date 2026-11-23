<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class V2InactiveHierarchyStoreAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_direct_writer_refuses_each_inactive_hierarchy_level_without_scope_or_audit_mutation(): void
    {
        $service = app(OrganizationHierarchyService::class);

        foreach (['organization', 'company', 'entity', 'unit'] as $index => $inactiveLevel) {
            [$unit, $store] = $this->hierarchy('denied_'.$index, $inactiveLevel);
            $beforeAudit = DB::table('audit_logs')->count();

            try {
                $service->assignStore($store, $unit);
                $this->fail('Inactive '.$inactiveLevel.' must not allow a direct store assignment.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Cannot assign a store to an inactive enterprise hierarchy.',
                    $exception->getMessage(),
                );
            }

            $this->assertDatabaseMissing('enterprise_store_scopes', ['store_id' => $store->id]);
            $this->assertNull($service->scopeForStore((int) $store->id));
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        }
    }

    public function test_active_hierarchy_assignment_remains_idempotent_and_keeps_scope(): void
    {
        [$unit, $store] = $this->hierarchy('allowed', null);
        $service = app(OrganizationHierarchyService::class);
        $beforeAudit = DB::table('audit_logs')->count();

        $first = $service->assignStore($store, $unit);
        $this->assertSame((int) $unit->id, (int) $first->business_unit_id);
        $this->assertDatabaseHas('enterprise_store_scopes', [
            'store_id' => $store->id,
            'business_unit_id' => $unit->id,
        ]);

        $second = $service->assignStore($store, $unit);
        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
        $this->assertSame((int) $unit->id, $service->scopeForStore((int) $store->id)['business_unit_id']);
    }

    public function test_disabled_lineage_cannot_reassign_an_existing_scope(): void
    {
        [$unit, $store] = $this->hierarchy('existing', null);
        $service = app(OrganizationHierarchyService::class);
        $scope = $service->assignStore($store, $unit);
        $beforeAudit = DB::table('audit_logs')->count();

        $unit->legalEntity->company->organization->forceFill(['is_active' => false])->save();

        try {
            $service->assignStore($store, $unit);
            $this->fail('An inactive ancestor must block a direct reassignment.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot assign a store to an inactive enterprise hierarchy.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('enterprise_store_scopes', [
            'id' => $scope->id,
            'store_id' => $store->id,
            'business_unit_id' => $unit->id,
        ]);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    public function test_existing_scope_cannot_be_silently_reassigned_across_organizations(): void
    {
        [$firstUnit, $store] = $this->hierarchy('source_org', null);
        [$secondUnit] = $this->hierarchy('target_org', null);
        $service = app(OrganizationHierarchyService::class);
        $existing = $service->assignStore($store, $firstUnit);
        $beforeAudit = DB::table('audit_logs')->count();

        try {
            $service->assignStore($store, $secondUnit);
            $this->fail('Existing scope must not move silently between organizations.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'A store cannot be silently moved across enterprise organizations.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('enterprise_store_scopes', [
            'id' => $existing->id,
            'store_id' => $store->id,
            'business_unit_id' => $firstUnit->id,
        ]);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    /** @return array{EnterpriseBusinessUnit, Store} */
    private function hierarchy(string $suffix, ?string $inactiveLevel): array
    {
        $organization = EnterpriseOrganization::query()->create([
            'code' => 'v2_scope_'.$suffix,
            'name' => 'P031 Scope '.$suffix,
            'is_active' => $inactiveLevel !== 'organization',
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $organization->id,
            'code' => 'v2_company_'.$suffix,
            'name' => 'P031 Company '.$suffix,
            'is_active' => $inactiveLevel !== 'company',
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_entity_'.$suffix,
            'name' => 'P031 Entity '.$suffix,
            'is_active' => $inactiveLevel !== 'entity',
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_unit_'.$suffix,
            'name' => 'P031 Unit '.$suffix,
            'is_active' => $inactiveLevel !== 'unit',
        ]);
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'v2-direct-scope-'.$suffix,
            'name' => 'P031 Direct Scope '.$suffix,
            'is_active' => true,
        ]);

        return [$unit, $store];
    }
}
