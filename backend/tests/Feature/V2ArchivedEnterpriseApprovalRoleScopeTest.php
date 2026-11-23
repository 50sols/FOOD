<?php

namespace Tests\Feature;

use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseSecurityAccessResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class V2ArchivedEnterpriseApprovalRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_global_role_cannot_override_active_role_organization_scope_for_same_permission(): void
    {
        $activeRole = $this->role('ACTIVE_P033_APPROVER', 'global', 'orders.approve');
        $historicalRole = $this->role('HISTORICAL_P033_APPROVER', 'global', 'orders.approve');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach([$activeRole->id, $historicalRole->id]);
        $orgA = EnterpriseOrganization::query()->firstOrFail();
        $orgB = EnterpriseOrganization::query()->create([
            'code' => 'p033_other_org',
            'name' => 'Other Organization',
            'is_active' => true,
        ]);
        EnterpriseRoleDataScope::query()->create([
            'role_id' => $activeRole->id,
            'scope_type' => 'organization',
            'scope_id' => $orgA->id,
            'scope_key' => 'organization:'.$orgA->id,
            'is_active' => true,
        ]);

        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $auditCount = DB::table('audit_logs')->count();
        $this->assertTrue($actor->hasPermission('orders.approve'));
        $this->assertTrue($resolver->allows($actor, 'orders.approve', 'organization', $orgA->id));
        $this->assertTrue($resolver->allows($actor, 'orders.approve', 'organization', $orgB->id), 'Unrestricted historical role is still valid before archive.');

        DB::table('roles')->where('id', $historicalRole->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $historicalRole->id)->value('is_active'));
        $this->assertTrue($actor->hasPermission('orders.approve'), 'Active scoped role must continue to grant the base permission.');
        $this->assertTrue($resolver->allows($actor, 'orders.approve', 'organization', $orgA->id));
        $this->assertFalse($resolver->allows($actor, 'orders.approve', 'organization', $orgB->id));
        $this->assertFalse($resolver->allows($actor->fresh(), 'orders.approve', 'organization', $orgB->id));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_archived_retail_store_role_is_not_a_candidate_for_enterprise_scoped_permissions(): void
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P033-RETAIL-SCOPE',
            'name' => 'P033 Retail',
            'is_active' => true,
        ]);
        $activeRole = Role::query()->where('code', 'RETAIL_CUSTOMER_SUPPORT')->firstOrFail();
        $archivedRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true]);
        foreach ([$activeRole, $archivedRole] as $role) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $store->id,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $this->assertContains($archivedRole->id, $this->candidateIds($resolver, $actor, 'customers.view', $store->id));
        $this->assertContains($activeRole->id, $this->candidateIds($resolver, $actor, 'customers.view', $store->id));

        DB::table('roles')->where('id', $archivedRole->id)->update(['archived_at' => now()]);
        $this->assertNotContains($archivedRole->id, $this->candidateIds($resolver, $actor, 'customers.view', $store->id));
        $this->assertContains($activeRole->id, $this->candidateIds($resolver, $actor, 'customers.view', $store->id));
        $this->assertNotContains($archivedRole->id, $this->candidateIds($resolver, $actor->fresh(), 'customers.view', $store->id));
    }

    private function role(string $code, string $scope, string $permission): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', $permission)->firstOrFail());

        return $role;
    }

    /** @return list<int> */
    private function candidateIds(EnterpriseSecurityAccessResolver $resolver, User $actor, string $permission, ?int $storeId): array
    {
        return (new ReflectionMethod($resolver, 'rolesWithPermission'))
            ->invoke($resolver, $actor, $permission, $storeId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
