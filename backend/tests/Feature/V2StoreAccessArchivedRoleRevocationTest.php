<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Support\StoreAccess;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2StoreAccessArchivedRoleRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_but_active_global_role_no_longer_grants_store_access(): void
    {
        $store = $this->store('ARCHIVED-GLOBAL-STORE');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $access = app(StoreAccess::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($access->hasGlobalStoreAccess($actor));
        $this->assertTrue($access->canAccess($actor, $store));
        $this->assertContains($store->id, $access->scopeStores(Store::query(), $actor)->pluck('id')->all());

        // Historical role remains active at the DB level, but archived roles
        // must never be an authorization source, even with a stale actor.
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);
        $this->assertFalse($access->hasGlobalStoreAccess($actor));
        $this->assertFalse($access->canAccess($actor, $store));
        $this->assertNotContains($store->id, $access->scopeStores(Store::query(), $actor)->pluck('id')->all());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_archived_scoped_role_fails_closed_in_admission_and_both_query_scopes(): void
    {
        $store = $this->store('ARCHIVED-SCOPED-STORE');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $access = app(StoreAccess::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($access->canAccess($actor, $store));
        $this->assertContains($store->id, $access->scopeStores(Store::query(), $actor)->pluck('id')->all());
        $this->assertSame(1, $access->scopeStoreOwned(UserStoreRole::query(), $actor)->where('store_id', $store->id)->count());

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);
        $this->assertFalse($access->canAccess($actor, $store));
        $this->assertNotContains($store->id, $access->scopeStores(Store::query(), $actor)->pluck('id')->all());
        $this->assertSame(0, $access->scopeStoreOwned(UserStoreRole::query(), $actor)->where('store_id', $store->id)->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
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
