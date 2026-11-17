<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2InspectorBackupLinkAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_health_reader_does_not_see_backup_link_or_gain_update_authority_in_ar_en(): void
    {
        $reader = $this->actor('V2_4002_INSPECTOR', ['platform.manage']);
        $before = $this->mutationCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $reader->forceFill(['locale' => $locale])->save();

            $this->actingAs($reader->fresh())
                ->get(route('admin.inspector.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-system-runtime-health', false)
                ->assertDontSee('data-inspector-backup-center-link', false);

            $this->actingAs($reader->fresh())
                ->get(route('admin.system-update.index'))
                ->assertForbidden();

            $this->actingAs($reader->fresh())
                ->post(route('admin.system-update.backups.store'), [
                    'reason' => 'unauthorized scheduled database backup',
                ])->assertForbidden();

            $this->assertSame($before, $this->mutationCounts());
        }
    }

    public function test_active_super_admin_sees_original_link_in_both_locales_without_triggering_an_update(): void
    {
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach($role->id);
        $before = $this->mutationCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get(route('admin.inspector.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-inspector-backup-center-link', false)
                ->assertSee('href="'.route('admin.system-update.index').'"', false);

            $this->assertSame($before, $this->mutationCounts());
        }
    }

    public function test_explicit_system_update_deny_cannot_be_overridden_by_inspector_navigation(): void
    {
        $actor = $this->actor('V2_4002_PLATFORM_UPDATER', ['platform.manage', 'system.update']);
        $this->assertTrue($actor->hasPermission('system.update'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'system.update')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertFalse($actor->fresh()->hasPermission('system.update'));
        $before = $this->mutationCounts();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor->fresh())
                ->get(route('admin.inspector.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertDontSee('data-inspector-backup-center-link', false);

            $this->actingAs($actor->fresh())
                ->get(route('admin.system-update.index'))
                ->assertForbidden();
            $this->assertSame($before, $this->mutationCounts());
        }
    }

    public function test_roleless_and_inactive_users_cannot_bypass_inspector_direct_url(): void
    {
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $inactive = $this->actor('V2_4002_INACTIVE', ['platform.manage']);
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $actor) {
            $this->actingAs($actor)->get(route('admin.inspector.index'))->assertForbidden();
        }
    }

    /** @param list<string> $permissions */
    private function actor(string $roleCode, array $permissions): User
    {
        $role = Role::query()->create([
            'code' => $roleCode,
            'name' => $roleCode,
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('code', $permissions)->pluck('id')->all());

        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /** @return array<string, int> */
    private function mutationCounts(): array
    {
        return [
            'update_history' => DB::table('update_history')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'user_role_assignments' => DB::table('role_user')->count(),
            'overrides' => DB::table('user_permission_overrides')->count(),
        ];
    }
}
