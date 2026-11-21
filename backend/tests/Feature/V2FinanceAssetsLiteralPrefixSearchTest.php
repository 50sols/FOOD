<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\FinanceAssetService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseFinanceAccount;
use App\Models\EnterpriseFixedAsset;
use App\Models\EnterpriseFixedAssetCategory;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2FinanceAssetsLiteralPrefixSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_finance_asset_prefix_is_literal_portable_and_scoped_to_legal_entity(): void
    {
        [$actor, $context] = $this->financeReader();
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);

        $category = $this->category($scope, $actor, 'FA-LITERAL');
        foreach ([
            ['EQ_001', 'Crate_% Prime', 'active'],
            ['EQX001', 'Crate Plain', 'active'],
            ['EQ-002', 'Crate!_ Secure', 'active'],
            ['EQ-003', 'مخزن معدات', 'active'],
            ['EQ-004', 'Crate% Promo', 'active'],
            ['EQ-005', 'Crate_ Inline', 'disposed'],
        ] as [$code, $name, $status]) {
            $this->asset($scope, $category, $actor, $code, $name, $status);
        }

        // A foreign legal entity with the same search prefix must never leak.
        $foreignOrganization = EnterpriseOrganization::query()->create([
            'code' => 'v2_other_finance_org', 'name' => 'Other Finance Organization', 'is_active' => true,
        ]);
        $foreignCompany = EnterpriseCompany::query()->create([
            'organization_id' => $foreignOrganization->id,
            'code' => 'v2_other_finance_company', 'name' => 'Other Finance Company', 'is_active' => true,
        ]);
        $foreignEntity = EnterpriseLegalEntity::query()->create([
            'company_id' => $foreignCompany->id,
            'code' => 'v2_other_finance_legal', 'name' => 'Other Finance Legal Entity', 'is_active' => true,
        ]);
        $foreignScope = new EnterpriseDataScope(
            (int) $foreignOrganization->id,
            (int) $foreignCompany->id,
            (int) $foreignEntity->id,
            $scope->businessUnitId,
            $scope->storeId,
        );
        $foreignCategory = $this->category($foreignScope, $actor, 'FA-FOREIGN');
        $this->asset($foreignScope, $foreignCategory, $actor, 'EQ_999', 'Crate_% Foreign');

        $service = app(FinanceAssetService::class);
        $find = static fn (string $q): array => $service
            ->paginateAssets($actor, $context, ['q' => $q])
            ->getCollection()
            ->pluck('asset_code')
            ->all();

        $this->assertSame(['EQ_001'], $find('EQ_'));
        $this->assertSame(['EQ_001'], $find('Crate_%'));
        $this->assertSame(['EQ-004'], $find('Crate%'));
        $this->assertSame(['EQ-002'], $find('Crate!_'));
        $this->assertSame(['EQ-005', 'EQ_001'], $find('Crate_'));
        $this->assertSame(['EQ-003'], $find('مخزن'));
        $this->assertSame([], $find('%'));
        $this->assertSame([], $find('_'));
        $this->assertCount(5, $find('Crate'));

        $filtered = $service->paginateAssets($actor, $context, [
            'q' => 'Crate_', 'status' => 'active', 'category_id' => $category->id,
        ], 1);
        $this->assertSame(['EQ_001'], $filtered->getCollection()->pluck('asset_code')->all());
        $this->assertSame(10, $filtered->perPage());
        $this->assertSame(6, $service->paginateAssets($actor, $context)->total());
        $this->assertSame(7, EnterpriseFixedAsset::query()->count());

        $unprivileged = User::factory()->create(['is_active' => true]);
        try {
            $service->paginateAssets($unprivileged, $context, ['q' => 'Crate_%']);
            $this->fail('Finance assets must deny users without read permission and entitlement.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    /** @return array{User, ResolvedTenantContext} */
    private function financeReader(): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => 'V2-FIN-ASSET-LIT',
            'name' => 'Finance Assets Literal Test',
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')
            ->where('scope', 'store')->where('is_active', true)->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'finance.view'], ['name' => 'finance.view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $this->grantPlatformErpAccess($actor);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id, 'store_id' => $store->id, 'role_id' => $role->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $context = app(TenantContextResolver::class)->retail($actor, $store->id);
        $this->assertNotNull($context->enterpriseScope);

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
        $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $context->enterpriseScope->legalEntityId),
            true,
            ['finance.foundation.read'],
            $actor,
        );

        return [$actor, $context];
    }

    private function category(EnterpriseDataScope $scope, User $actor, string $code): EnterpriseFixedAssetCategory
    {
        $account = EnterpriseFinanceAccount::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'code' => $code, 'name' => $code,
            'type' => 'asset', 'normal_balance' => 'debit',
            'is_control' => false, 'allow_posting' => true,
        ]);

        return EnterpriseFixedAssetCategory::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'code' => $code, 'name' => $code,
            'depreciation_method' => 'straight_line',
            'default_useful_life_months' => 12,
            'asset_account_id' => $account->id,
            'accumulated_depreciation_account_id' => $account->id,
            'depreciation_expense_account_id' => $account->id,
        ]);
    }

    private function asset(
        EnterpriseDataScope $scope,
        EnterpriseFixedAssetCategory $category,
        User $actor,
        string $code,
        string $name,
        string $status = 'active',
    ): void {
        EnterpriseFixedAsset::query()->create([
            'organization_id' => $scope->organizationId,
            'company_id' => $scope->companyId,
            'legal_entity_id' => $scope->legalEntityId,
            'category_id' => $category->id,
            'asset_code' => $code,
            'name' => $name,
            'acquisition_date' => '2026-01-01',
            'capitalization_date' => '2026-01-01',
            'acquisition_amount' => '100.000',
            'salvage_value' => '0.000',
            'useful_life_months' => 12,
            'depreciation_method' => 'straight_line',
            'acquisition_offset_account_id' => $category->asset_account_id,
            'status' => $status,
            'created_by' => $actor->id,
        ]);
    }
}
