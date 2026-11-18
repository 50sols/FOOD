<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RbacManager;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SecurityArchivedRoleAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_active_and_inactive_roles_are_historical_but_never_assignable_in_both_locales(): void
    {
        $actor = $this->superAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $store = $this->store('V2-4034-STORE');

        $globalActive = $this->role('V2_4034_GLOBAL_ACTIVE', 'global', ['catalog.view']);
        $storeActive = $this->role('V2_4034_STORE_ACTIVE', 'store', ['catalog.view']);
        $globalArchived = $this->role('V2_4034_GLOBAL_ARCHIVED', 'global', ['catalog.view']);
        $storeArchived = $this->role('V2_4034_STORE_ARCHIVED', 'store', ['catalog.view']);
        $globalInactive = $this->role('V2_4034_GLOBAL_INACTIVE', 'global', ['catalog.view']);
        $storeInactive = $this->role('V2_4034_STORE_INACTIVE', 'store', ['catalog.view']);

        // Legacy/import inconsistency: archive marker without is_active=0.
        DB::table('roles')->whereIn('id', [$globalArchived->id, $storeArchived->id])
            ->update(['archived_at' => now()]);
        $globalInactive->forceFill(['is_active' => false])->save();
        $storeInactive->forceFill(['is_active' => false])->save();

        $before = $this->securityCounts($target);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertViewHas('securitySection', 'users')
                ->assertViewHas('globalRoles', fn ($roles): bool => $roles->contains('id', $globalActive->id)
                    && ! $roles->contains('id', $globalArchived->id)
                    && ! $roles->contains('id', $globalInactive->id))
                ->assertViewHas('grantableStoreRolesByStore', function ($roles) use ($store, $storeActive, $storeArchived, $storeInactive): bool {
                    $candidates = $roles->get($store->id, collect());

                    return $candidates->contains('id', $storeActive->id)
                        && ! $candidates->contains('id', $storeArchived->id)
                        && ! $candidates->contains('id', $storeInactive->id);
                });

            // Historical role visibility does not imply effective authority.
            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', ['section' => 'roles']))
                ->assertOk()
                ->assertViewHas('roles', fn ($roles): bool => $roles->contains('id', $globalArchived->id)
                    && $roles->contains('id', $storeArchived->id))
                ->assertSee('dir="'.$direction.'"', false);

            foreach ([$globalArchived, $globalInactive] as $role) {
                $this->actingAs($actor->fresh())
                    ->put(route('admin.security.users.roles', $target), [
                        'global_role_ids' => [$role->id],
                        'change_reason' => 'Review historical global role assignment',
                    ])
                    ->assertSessionHasErrors('global_role_ids');
                $this->assertSame($before, $this->securityCounts($target));
            }

            foreach ([$storeArchived, $storeInactive] as $role) {
                $this->actingAs($actor->fresh())
                    ->put(route('admin.security.users.roles', $target), [
                        'store_role_ids' => [$store->id => [$role->id]],
                        'change_reason' => 'Review historical store role assignment',
                    ])
                    ->assertSessionHasErrors('store_roles');
                $this->assertSame($before, $this->securityCounts($target));
            }

            // One invalid role in a mixed request must not partly grant the valid role.
            $this->actingAs($actor->fresh())
                ->put(route('admin.security.users.roles', $target), [
                    'global_role_ids' => [$globalActive->id, $globalArchived->id],
                    'store_role_ids' => [$store->id => [$storeActive->id]],
                    'change_reason' => 'Review mixed historical role assignment',
                ])
                ->assertSessionHasErrors('global_role_ids');
            $this->assertSame($before, $this->securityCounts($target));
        }

        // The original valid global/store assignment and audit path is preserved.
        $this->actingAs($actor->fresh())
            ->put(route('admin.security.users.roles', $target), [
                'global_role_ids' => [$globalActive->id],
                'store_role_ids' => [$store->id => [$storeActive->id]],
                'change_reason' => 'Approve valid active role assignment',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('role_user', ['user_id' => $target->id, 'role_id' => $globalActive->id]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $target->id, 'store_id' => $store->id, 'role_id' => $storeActive->id,
        ]);
        $this->assertGreaterThan($before['audit'], DB::table('audit_logs')->count());
        $this->assertSame($before['sidebar_visibility'], DB::table('admin_sidebar_role_visibility')->count());
        $this->assertSame($before['overrides'], DB::table('user_permission_overrides')->count());
    }

    public function test_delegated_store_role_choices_follow_effective_permission_and_explicit_deny(): void
    {
        $store = $this->store('V2-4034-DENY');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $security = $this->role('V2_4034_SECURITY', 'global', ['security.view']);
        $delegated = $this->role('V2_4034_DELEGATED', 'global', ['users.roles.manage']);
        $catalog = $this->role('V2_4034_CATALOG', 'store', ['catalog.view']);
        $grantable = $this->role('V2_4034_GRANTABLE', 'store', ['catalog.view']);
        $target = User::factory()->create(['is_active' => true]);

        $actor->roles()->attach([$security->id, $delegated->id]);
        $actor->storeRoleAssignments()->create(['store_id' => $store->id, 'role_id' => $catalog->id]);

        $this->assertTrue($actor->fresh()->hasPermission('security.view'));
        $this->assertTrue($actor->fresh()->hasPermission('users.roles.manage'));
        $this->assertTrue($actor->fresh()->hasPermission('catalog.view', $store->id));
        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertViewHas('grantableStoreRolesByStore', fn ($map): bool => $map->get($store->id, collect())
                ->contains('id', $grantable->id));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'catalog.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$store->id,
            'store_id' => $store->id,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($actor->fresh()->hasPermission('catalog.view', $store->id));

        $before = $this->securityCounts($target);
        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', ['section' => 'users']))
            ->assertOk()
            ->assertViewHas('grantableStoreRolesByStore', fn ($map): bool => ! $map->get($store->id, collect())
                ->contains('id', $grantable->id));

        $this->actingAs($actor->fresh())
            ->put(route('admin.security.users.roles', $target), [
                'store_role_ids' => [$store->id => [$grantable->id]],
                'change_reason' => 'Blocked by explicit store permission deny',
            ])
            ->assertSessionHasErrors();
        $this->assertSame($before, $this->securityCounts($target));

        // Explicit DENY for the mutation permission overrides both active grants.
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'users.roles.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['users', 'roles', 'audit'] as $section) {
            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', ['section' => $section]))
                ->assertOk();
        }
        $this->actingAs($actor->fresh())
            ->put(route('admin.security.users.roles', $target), [
                'store_role_ids' => [$store->id => [$grantable->id]],
                'change_reason' => 'Role override cannot bypass direct HTTP',
            ])
            ->assertForbidden();
        $this->assertSame(
            $before['audit'],
            DB::table('audit_logs')->count(),
        );
        $this->assertSame($before['target_roles'], DB::table('role_user')->where('user_id', $target->id)->count());
        $this->assertSame($before['target_store_roles'], DB::table('user_store_roles')->where('user_id', $target->id)->count());
    }

    public function test_archived_only_and_roleless_actors_cannot_bypass_security_tab_or_role_assignment_url(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $role = $this->role('V2_4034_OLD_SECURITY', 'global', ['security.view', 'users.roles.manage']);
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $archivedActor = User::factory()->create(['is_active' => true]);
        $archivedActor->roles()->attach($role->id);
        $roleless = User::factory()->create(['is_active' => true]);
        $before = $this->securityCounts($target);

        foreach ([$roleless, $archivedActor] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'roles']))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'audit']))
                ->assertForbidden();
            $this->actingAs($actor)
                ->put(route('admin.security.users.roles', $target), [
                    'global_role_ids' => [$role->id],
                    'change_reason' => 'Direct URL cannot recover an archived role',
                ])
                ->assertForbidden();
        }

        $this->assertSame($before, $this->securityCounts($target));
    }

    /** @return array<string, int> */
    private function securityCounts(User $target): array
    {
        return [
            'audit' => DB::table('audit_logs')->count(),
            'target_roles' => DB::table('role_user')->where('user_id', $target->id)->count(),
            'target_store_roles' => DB::table('user_store_roles')->where('user_id', $target->id)->count(),
            'overrides' => DB::table('user_permission_overrides')->count(),
            'sidebar_visibility' => DB::table('admin_sidebar_role_visibility')->count(),
        ];
    }

    private function superAdmin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $actor;
    }

    /** @param list<string> $permissionCodes */
    private function role(string $code, string $scope, array $permissionCodes): Role
    {
        $role = Role::query()->create([
            'code' => $code, 'name' => $code, 'scope' => $scope, 'is_active' => true, 'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('code', $permissionCodes)->pluck('id')->all(),
        );

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
}
