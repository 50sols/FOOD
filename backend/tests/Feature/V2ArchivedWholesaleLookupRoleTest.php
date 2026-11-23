<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\LookupScopeService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArchivedWholesaleLookupRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_but_active_wholesale_role_does_not_grant_lookup_access(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $access = app(LookupScopeService::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($access->canAccessWholesale($actor));

        // Simulate a historical archived-but-active role without refreshing
        // the principal or its role relationship in memory.
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertFalse($access->canAccessWholesale($actor));
        $this->assertFalse($access->canAccessWholesale($actor->fresh()));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_active_wholesale_role_requires_current_persisted_user_and_role_status(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $access = app(LookupScopeService::class);
        $this->assertTrue($access->canAccessWholesale($actor));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertFalse($access->canAccessWholesale($actor));
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($access->canAccessWholesale($actor->fresh()));

        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertFalse($access->canAccessWholesale($actor->fresh()));
    }
}
