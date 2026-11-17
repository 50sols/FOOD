<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArchivedActiveRoleEffectivePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_but_active_super_admin_cannot_retain_security_http_or_role_grant_in_both_locales(): void
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role->id);
        $this->assertTrue($actor->hasRole('SUPER_ADMIN'));
        $this->assertTrue($actor->hasPermission('security.view'));

        // Historical/import contradiction must not grant runtime privileges.
        // Raw fixture bypasses the guarded model archive workflow intentionally.
        $this->archiveWithoutClearingActive($role);
        $actor = $actor->fresh();
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->hasPermission('security.view'));
        $this->assertNotContains('security.view', $actor->inheritedPermissionCodes());
        $this->assertNotContains('security.view', $actor->effectivePermissionCodes());

        $before = $this->auditCounts();
        $authorizationBefore = $this->authorizationSnapshot($actor->id);
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', ['section' => 'roles']))
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
            $this->assertSame($before, $this->auditCounts(), $direction);
            $this->assertSame($authorizationBefore, $this->authorizationSnapshot($actor->id), $direction);
        }
        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'is_active' => true,
        ]);
        $this->assertNotNull(DB::table('roles')->where('id', $role->id)->value('archived_at'));
    }

    public function test_archived_but_active_store_role_cannot_supply_inherited_or_explicit_allow_for_other_stores(): void
    {
        $storeA = $this->store('V2-4000-A');
        $storeB = $this->store('V2-4000-B');
        $role = $this->role('V2_4000_STORE_EDITOR', 'store', ['catalog.view']);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->storeRoleAssignments()->create(['store_id' => $storeA->id, 'role_id' => $role->id]);

        $this->assertTrue($actor->hasRole($role->code, $storeA->id));
        $this->assertTrue($actor->hasPermission('catalog.view', $storeA->id));
        $this->assertFalse($actor->hasRole($role->code, $storeB->id));
        $this->assertFalse($actor->hasPermission('catalog.view', $storeB->id));

        // A stored override must not revive an archived assignment.
        $this->override($actor, 'catalog.view', 'store:'.$storeA->id, 'allow', $storeA->id);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->archiveWithoutClearingActive($role);
        $actor = $actor->fresh();

        foreach ([$storeA->id, $storeB->id] as $storeId) {
            $this->assertFalse($actor->hasRole($role->code, $storeId));
            $this->assertFalse($actor->hasPermission('catalog.view', $storeId));
            $this->assertNotContains('catalog.view', $actor->effectivePermissionCodes($storeId));
            $this->assertNotContains('catalog.view', $actor->inheritedPermissionCodes($storeId));
        }

        $this->assertSame(1, $actor->storeRoleAssignments()->count());
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $actor->id,
            'scope_key' => 'store:'.$storeA->id,
            'effect' => 'allow',
        ]);
    }

    public function test_mixed_active_and_archived_roles_keep_active_authority_but_explicit_deny_wins_on_direct_urls(): void
    {
        $active = $this->role('V2_4000_ACTIVE_CATALOG', 'global', ['catalog.view']);
        $archived = $this->role('V2_4000_OLD_SECURITY', 'global', ['security.view', 'roles.manage']);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $actor->roles()->attach([$active->id, $archived->id]);

        $this->assertTrue($actor->hasPermission('catalog.view'));
        $this->assertTrue($actor->hasPermission('security.view'));
        $this->archiveWithoutClearingActive($archived);
        $actor = $actor->fresh();
        $this->assertTrue($actor->hasPermission('catalog.view'));
        $this->assertFalse($actor->hasRole($archived->code));
        $this->assertFalse($actor->hasPermission('security.view'));
        $this->assertFalse($actor->hasPermission('roles.manage'));

        // A legacy GLOBAL ALLOW for an archived role is not an escalation path;
        // an explicit GLOBAL DENY still overrides the surviving active role.
        $this->override($actor, 'security.view', 'global', 'allow');
        $this->override($actor, 'catalog.view', 'global', 'deny');
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $before = $this->auditCounts();
        $authorizationBefore = $this->authorizationSnapshot($actor->id);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $effective = $actor->fresh();
            $this->assertFalse($effective->hasPermission('security.view'), $direction);
            $this->assertFalse($effective->hasPermission('catalog.view'), $direction);
            $this->assertNotContains('security.view', $effective->effectivePermissionCodes());
            $this->assertNotContains('catalog.view', $effective->effectivePermissionCodes());
            $this->actingAs($effective)->get(route('admin.catalog.index'))->assertForbidden();
            $this->actingAs($effective)->get(route('admin.security.index', ['section' => 'audit']))->assertForbidden();
            $this->assertSame($before, $this->auditCounts(), $direction);
            $this->assertSame($authorizationBefore, $this->authorizationSnapshot($actor->id), $direction);
        }

        $actor->forceFill(['is_active' => false])->save();
        $this->assertFalse($actor->fresh()->hasPermission('catalog.view'));
        $this->assertSame([], $actor->fresh()->effectivePermissionCodes());
    }

    private function archiveWithoutClearingActive(Role $role): void
    {
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
    }

    /** @param list<string> $permissionCodes */
    private function role(string $code, string $scope, array $permissionCodes): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $permissionCodes)->pluck('id')->all());

        return $role;
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

    private function override(User $actor, string $code, string $scope, string $effect, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
            'scope_key' => $scope,
            'store_id' => $storeId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function auditCounts(): array
    {
        return [
            'audit' => DB::table('audit_logs')->count(),
            'roles' => DB::table('role_user')->count(),
            'store_roles' => DB::table('user_store_roles')->count(),
            'overrides' => DB::table('user_permission_overrides')->count(),
            'sidebar' => DB::table('admin_sidebar_role_visibility')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function authorizationSnapshot(int $userId): array
    {
        $roleIds = DB::table('role_user')
            ->where('user_id', $userId)
            ->pluck('role_id')
            ->merge(DB::table('user_store_roles')->where('user_id', $userId)->pluck('role_id'))
            ->unique()
            ->values();

        return [
            'user_authority_flags' => DB::table('users')->where('id', $userId)->value('has_permission_overrides'),
            'global_roles' => DB::table('role_user')
                ->where('user_id', $userId)
                ->orderBy('role_id')
                ->get(['role_id'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'store_roles' => DB::table('user_store_roles')
                ->where('user_id', $userId)
                ->orderBy('store_id')
                ->orderBy('role_id')
                ->get(['store_id', 'role_id'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'role_permissions' => DB::table('permission_role')
                ->whereIn('role_id', $roleIds)
                ->orderBy('role_id')
                ->orderBy('permission_id')
                ->get(['role_id', 'permission_id'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            'permission_overrides' => DB::table('user_permission_overrides')
                ->where('user_id', $userId)
                ->orderBy('scope_key')
                ->orderBy('permission_id')
                ->get(['permission_id', 'scope_key', 'store_id', 'effect'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
        ];
    }
}
