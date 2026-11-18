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

final class V2SecurityCenterQueryTabAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_existing_security_sections_and_sidebar_role_matrix_remain_read_only_in_ar_en(): void
    {
        $actor = $this->admin();
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $before = $this->securityCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            foreach (['users', 'roles', 'governance', 'sessions', 'audit'] as $section) {
                $this->actingAs($actor->fresh())->get(route('admin.security.index', [
                    'section' => $section,
                ]))->assertOk()
                    ->assertViewHas('securitySection', $section)
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('security-tabs', false)
                    ->assertSee('aria-current="page"', false);
            }

            $this->actingAs($actor->fresh())->get(route('admin.sidebar-access.roles', [
                'role_id' => $role->id,
            ]))->assertOk()
                ->assertViewHas('selectedRole', fn (Role $selected): bool => $selected->id === $role->id)
                ->assertSee('dir="'.$direction.'"', false);
        }

        $this->assertSame($before, $this->securityCounts());
    }

    public function test_malformed_tab_and_filter_shapes_fail_closed_without_mutating_security_history(): void
    {
        $actor = $this->admin();
        $url = route('admin.security.index', [], false);
        $before = $this->securityCounts();

        foreach ([
            $url.'?section%5B%5D=roles',
            $url.'?section%5Bname%5D=audit',
            $url.'?q%5B%5D=admin',
            $url.'?status%5B%5D=active',
            $url.'?assignment%5B%5D=roleless',
            $url.'?deletion_preview%5B%5D=1',
        ] as $malformedUrl) {
            $this->actingAs($actor)->get($malformedUrl)->assertNotFound();
        }

        // Preserve existing backward-compatible behavior for unknown scalars.
        $this->actingAs($actor)->get($url.'?section=legacy_section')
            ->assertOk()->assertViewHas('securitySection', 'users');

        $this->assertSame($before, $this->securityCounts());
    }

    public function test_sidebar_explicit_role_selector_must_resolve_exact_role_or_return_404(): void
    {
        $actor = $this->admin();
        $root = route('admin.sidebar-access.roles', [], false);
        $before = $this->securityCounts();

        $this->actingAs($actor)->get($root)
            ->assertOk()->assertViewHas('selectedRole');
        foreach ([
            $root.'?role_id%5B%5D=1',
            $root.'?role_id%5Bfoo%5D=2',
            $root.'?role_id=not-a-role',
            $root.'?role_id=-1',
            $root.'?role_id=0',
            $root.'?role_id=999999999',
        ] as $malformedUrl) {
            $this->actingAs($actor)->get($malformedUrl)->assertNotFound();
        }

        $this->assertSame($before, $this->securityCounts());
    }

    public function test_archived_and_inactive_roles_remain_historical_not_editor_grants(): void
    {
        $actor = $this->admin();
        $archived = $this->customRole('V2_3982_ARCHIVED', 'global', ['security.view']);
        $archived->forceFill(['archived_at' => now(), 'is_active' => false])->save();
        $inactive = $this->customRole('V2_3982_INACTIVE', 'global', ['roles.manage']);
        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAs($actor)->get(route('admin.sidebar-access.roles', [
            'role_id' => $archived->id,
        ]))->assertOk()
            ->assertViewHas('selectedRole', fn (Role $role): bool => $role->id === $archived->id)
            ->assertViewHas('canEditRolePermissions', false);

        $this->actingAs($actor)->get(route('admin.sidebar-access.roles', [
            'role_id' => $inactive->id,
        ]))->assertOk()
            ->assertViewHas('selectedRole', fn (Role $role): bool => $role->id === $inactive->id)
            ->assertViewHas('canEditRolePermissions', false)
            ->assertSee('data-v2-role-status="inactive"', false);

        $archivedActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $archivedActor->roles()->attach($archived->id);
        $archivedActor->roles()->attach($inactive->id);

        $this->actingAs($archivedActor->fresh())->get(route('admin.security.index'))
            ->assertForbidden();
        $this->actingAs($archivedActor->fresh())->get(route('admin.sidebar-access.roles'))
            ->assertForbidden();
    }

    public function test_explicit_deny_wins_over_mixed_role_allow_and_cannot_be_bypassed_by_tab_query(): void
    {
        $security = $this->customRole('V2_3982_SECURITY', 'global', ['security.view']);
        $layout = $this->customRole('V2_3982_LAYOUT', 'global', ['roles.manage']);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach([$security->id, $layout->id]);

        $this->assertTrue($actor->fresh()->hasPermission('security.view'));
        $this->assertTrue($actor->fresh()->hasPermission('roles.manage'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'security.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $denied = $actor->fresh();
        $before = $this->securityCounts();

        $this->assertFalse($denied->hasPermission('security.view'));
        foreach (['users', 'roles', 'audit'] as $section) {
            $this->actingAs($denied)->get(route('admin.security.index', [
                'section' => $section,
            ]))->assertForbidden();
        }
        // A scoped role-layout page can still use its independent permission;
        // neither it nor its tab query can undo explicit security.view DENY.
        $this->actingAs($denied)->get(route('admin.sidebar-access.roles'))
            ->assertOk();

        $this->assertSame($before, $this->securityCounts());
    }

    public function test_explicit_roles_manage_deny_blocks_role_matrix_even_with_other_allow_roles(): void
    {
        $security = $this->customRole('V2_3982_SCOPE_VIEWER', 'global', ['security.view']);
        $editor = $this->customRole('V2_3982_SCOPE_EDITOR', 'global', ['roles.manage']);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $actor->roles()->attach([$security->id, $editor->id]);

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'roles.manage')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $denied = $actor->fresh();
        $before = $this->securityCounts();

        $this->assertTrue($denied->hasPermission('security.view'));
        $this->assertFalse($denied->hasPermission('roles.manage'));
        $this->actingAs($denied)->get(route('admin.security.index', [
            'section' => 'users',
        ]))->assertOk();
        $this->actingAs($denied)->get(route('admin.sidebar-access.roles', [
            'role_id' => $editor->id,
        ]))->assertForbidden();
        $this->actingAs($denied)->get(route('admin.sidebar-access.roles', [], false).'?role_id%5B%5D=1')
            ->assertForbidden();

        $this->assertSame($before, $this->securityCounts());
    }

    public function test_store_only_security_role_and_roleless_actor_cannot_open_platform_security_urls(): void
    {
        $storeRole = $this->customRole('V2_3982_STORE_SECURITY', 'store', [
            'security.view', 'roles.manage',
        ]);
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-3982-STORE',
            'name' => 'Role Query Store',
            'is_active' => true,
        ]);
        $storeActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $storeActor->storeRoleAssignments()->create([
            'store_id' => $store->id,
            'role_id' => $storeRole->id,
        ]);
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        foreach ([$storeActor->fresh(), $roleless] as $actor) {
            $this->actingAs($actor)->get(route('admin.security.index', [
                'section' => 'roles',
            ]))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.sidebar-access.roles', [
                'role_id' => $storeRole->id,
            ]))->assertForbidden();
        }
    }

    private function admin(): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        return $actor;
    }

    /** @param  list<string>  $permissions */
    private function customRole(string $code, string $scope, array $permissions): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => $scope,
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('code', $permissions)->pluck('id')->all(),
        );

        return $role;
    }

    /** @return array<string, int> */
    private function securityCounts(): array
    {
        return [
            'audit' => DB::table('audit_logs')->count(),
            'role_user' => DB::table('role_user')->count(),
            'store_roles' => DB::table('user_store_roles')->count(),
            'role_permissions' => DB::table('permission_role')->count(),
            'user_denies' => DB::table('user_permission_overrides')->count(),
            'sidebar_visibility' => DB::table('admin_sidebar_role_visibility')->count(),
        ];
    }
}
