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

/** P031: an inactive historical owner never permits a silent cross-org move. */
final class V2HistoricalOrganizationBoundaryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inactive_source_organization_still_blocks_reassignment_to_another_active_organization(): void
    {
        [$source, $sourceOrganization] = $this->hierarchy('old');
        [$target] = $this->hierarchy('new');
        $store = $this->store('P031-ORG-HISTORICAL-OWNER');
        $service = app(OrganizationHierarchyService::class);
        $original = $service->assignStore($store, $source);
        $this->assertNotNull($service->scopeForStore((int) $store->id));
        $auditCount = DB::table('audit_logs')->count();

        // The effective scope is intentionally hidden as soon as its old
        // organization is disabled, but its ownership is still persisted.
        DB::table('enterprise_organizations')->where('id', $sourceOrganization->id)
            ->update(['is_active' => false]);
        $this->assertNull($service->scopeForStore((int) $store->id));

        try {
            $service->assignStore($store, $target);
            $this->fail('Inactive original owner must not permit cross-organization transfer.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'A store cannot be silently moved across enterprise organizations.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('enterprise_store_scopes', [
            'id' => $original->id,
            'store_id' => $store->id,
            'business_unit_id' => $source->id,
        ]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        // Reactivation restores the unchanged owner; cross-organization
        // assignment remains prohibited even after restoring visibility.
        DB::table('enterprise_organizations')->where('id', $sourceOrganization->id)
            ->update(['is_active' => true]);
        $this->assertSame((int) $source->id, $service->scopeForStore((int) $store->id)['business_unit_id']);

        // A disabled store also makes effective scope null, but does not
        // abandon the historical organization's ownership of the mapping.
        DB::table('stores')->where('id', $store->id)->update(['is_active' => false]);
        $this->assertNull($service->scopeForStore((int) $store->id));
        try {
            $service->assignStore($store, $target);
            $this->fail('A disabled store must not bypass original organization ownership.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'A store cannot be silently moved across enterprise organizations.',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseHas('enterprise_store_scopes', [
            'id' => $original->id,
            'store_id' => $store->id,
            'business_unit_id' => $source->id,
        ]);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_same_organization_active_unit_reassignment_remains_permitted_and_idempotent(): void
    {
        [$source] = $this->hierarchy('same');
        $other = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $source->legal_entity_id,
            'code' => 'v2_hist_same_other',
            'name' => 'Same owner second unit',
            'is_active' => true,
        ]);
        $store = $this->store('P031-ORG-SAME-OWNER');
        $service = app(OrganizationHierarchyService::class);

        $service->assignStore($store, $source);
        $before = DB::table('audit_logs')->count();
        $second = $service->assignStore($store, $other);
        $this->assertSame((int) $other->id, (int) $second->business_unit_id);
        $this->assertSame($before + 1, DB::table('audit_logs')->count());

        $service->assignStore($store, $other);
        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    /** @return array{EnterpriseBusinessUnit, EnterpriseOrganization} */
    private function hierarchy(string $suffix): array
    {
        $org = EnterpriseOrganization::query()->create([
            'code' => 'v2_hist_org_'.$suffix,
            'name' => 'Historical Organization '.$suffix,
            'is_active' => true,
        ]);
        $company = EnterpriseCompany::query()->create([
            'organization_id' => $org->id,
            'code' => 'v2_hist_company_'.$suffix,
            'name' => 'Historical Company '.$suffix,
            'is_active' => true,
        ]);
        $entity = EnterpriseLegalEntity::query()->create([
            'company_id' => $company->id,
            'code' => 'v2_hist_entity_'.$suffix,
            'name' => 'Historical Entity '.$suffix,
            'is_active' => true,
        ]);
        $unit = EnterpriseBusinessUnit::query()->create([
            'legal_entity_id' => $entity->id,
            'code' => 'v2_hist_unit_'.$suffix,
            'name' => 'Historical Unit '.$suffix,
            'is_active' => true,
        ]);

        return [$unit, $org];
    }

    private function store(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
