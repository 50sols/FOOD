<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArchivedOperationalTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_retail_store_assignment_cannot_expand_operational_scope(): void
    {
        $store = $this->store('P031-STORE-ARCHIVE', 'B2C');
        $foreign = $this->store('P031-STORE-FOREIGN', 'B2C');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $this->assign($actor, $store, $role);
        $service = app(OperationalTenantScope::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertSame([$store->id], $service->allowedStoreIds($actor, 'orders.view', 'B2C'));
        $this->assertNotContains($foreign->id, $service->allowedStoreIds($actor, 'orders.view', 'B2C'));
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));
        $this->assertSame([], $service->allowedStoreIds($actor, 'orders.view', 'B2C'));
        $this->assertSame([], $service->allowedStoreIds($actor->fresh(), 'orders.view', 'B2C'));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_direct_store_override_cannot_reuse_archived_assignment_as_scope_context(): void
    {
        $store = $this->store('P031-OVERRIDE-ARCHIVE', 'B2C');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $this->assign($actor, $store, $role);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'reports.view')->firstOrFail()->id,
            'store_id' => $store->id,
            'scope_key' => 'store:'.$store->id,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scope = app(OperationalTenantScope::class);
        $this->assertContains($store->id, $scope->allowedStoreIds($actor, 'reports.view', 'B2C'));

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertSame([], $scope->allowedStoreIds($actor, 'reports.view', 'B2C'));
    }

    public function test_archived_global_role_cannot_expand_channel_from_separate_active_permission_role(): void
    {
        $wholesale = $this->store('P031-WHOLESALE', 'B2B');
        $retail = $this->store('P031-RETAIL', 'B2C');
        $actor = User::factory()->create(['is_active' => true]);
        $active = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $archived = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor->roles()->attach([$active->id, $archived->id]);
        $scope = app(OperationalTenantScope::class);
        $this->assertTrue($actor->hasPermission('orders.view'));
        $this->assertSame([$wholesale->id], $scope->allowedStoreIds($actor, 'orders.view', 'B2B'));
        $this->assertSame([$retail->id], $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));

        DB::table('roles')->where('id', $archived->id)->update(['archived_at' => now()]);
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertTrue($actor->hasPermission('orders.view'));
        $this->assertSame([$wholesale->id], $scope->allowedStoreIds($actor, 'orders.view', 'B2B'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));
    }

    private function store(string $code, string $channel): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function assign(User $user, Store $store, Role $role): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
