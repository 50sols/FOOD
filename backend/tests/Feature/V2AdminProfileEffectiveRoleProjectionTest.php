<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2AdminProfileEffectiveRoleProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        config()->set('security.mfa.enforce_privileged', false);
    }

    public function test_archived_active_global_role_disappears_from_both_locales_without_deleting_history(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $owner = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $historical = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach([$owner->id, $historical->id]);

        $this->actingAs($actor)->get(route('admin.profile.index'))
            ->assertOk()
            ->assertViewHas('globalRoles', fn ($roles) => $roles->contains('code', 'B2B_ADMIN'));

        DB::table('roles')->where('id', $historical->id)->update(['archived_at' => now()]);
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            $actor->forceFill(['locale' => $locale])->save();
            $this->actingAs($actor)->get(route('admin.profile.index'))
                ->assertOk()
                ->assertViewHas('globalRoles', fn ($roles) => $roles->contains('code', 'SUPER_ADMIN')
                    && ! $roles->contains('code', 'B2B_ADMIN'));
        }

        $this->assertDatabaseHas('role_user', ['role_id' => $historical->id, 'user_id' => $actor->id]);
        $this->assertNotNull(DB::table('roles')->where('id', $historical->id)->value('archived_at'));
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_profile_store_assignments_exclude_archived_roles_and_inactive_stores(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $retailRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $storeType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeType,
            'business_profile' => 'RETAIL_STORE',
            'code' => 'PROFILE-RETAIL-4379',
            'name' => 'Current profile store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $retailRole->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->get(route('admin.profile.index'))
            ->assertOk()
            ->assertViewHas('storeAssignments', fn ($assignments) => $assignments->contains('store_id', $storeId));

        DB::table('stores')->where('id', $storeId)->update(['is_active' => false]);

        $this->actingAs($actor)->get(route('admin.profile.index'))
            ->assertOk()
            ->assertViewHas('storeAssignments', fn ($assignments) => ! $assignments->contains('store_id', $storeId));

        DB::table('stores')->where('id', $storeId)->update(['is_active' => true]);
        DB::table('roles')->where('id', $retailRole->id)->update(['archived_at' => now()]);
        $auditBefore = DB::table('audit_logs')->count();

        $this->actingAs($actor)->get(route('admin.profile.index'))
            ->assertOk()
            ->assertViewHas('storeAssignments', fn ($assignments) => ! $assignments->contains('store_id', $storeId));
        // An inactive role must also disappear even when it has not been archived.
        DB::table('roles')->where('id', $retailRole->id)->update([
            'archived_at' => null,
            'is_active' => false,
        ]);
        $this->actingAs($actor)->get(route('admin.profile.index'))
            ->assertOk()
            ->assertViewHas('storeAssignments', fn ($assignments) => ! $assignments->contains('store_id', $storeId));

        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $retailRole->id,
        ]);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }
}
