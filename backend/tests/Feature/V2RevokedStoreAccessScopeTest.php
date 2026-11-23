<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreAccess;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RevokedStoreAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_revoked_cached_super_admin_loses_global_and_scoped_store_access(): void
    {
        $retail = $this->store('STORE-ACCESS-A');
        $foreign = $this->store('STORE-ACCESS-B');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $access = app(StoreAccess::class);
        $before = DB::table('audit_logs')->count();

        $this->assertTrue($access->hasGlobalStoreAccess($actor));
        $this->assertTrue($access->canAccess($actor, $retail));
        $this->assertEqualsCanonicalizing([$retail->id, $foreign->id], Store::query()->accessibleTo($actor)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$retail->id, $foreign->id], $access->scopeStoreOwned(Store::query(), $actor, 'id')->pluck('id')->all());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active, 'Persisted revocation must be tested against an unchanged model.');
        $this->assertFalse($access->hasGlobalStoreAccess($actor));
        $this->assertFalse($access->canAccess($actor, $retail));
        $this->assertSame([], Store::query()->accessibleTo($actor)->pluck('id')->all());
        $this->assertSame([], $access->scopeStoreOwned(Store::query(), $actor, 'id')->pluck('id')->all());
        $this->assertSame([], Store::query()->accessibleTo($actor->fresh())->pluck('id')->all());
        $this->assertSame($before, DB::table('audit_logs')->count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($access->hasGlobalStoreAccess($actor->fresh()));
        $this->assertTrue($access->canAccess($actor->fresh(), $foreign));
    }

    public function test_revoked_cached_store_role_loses_only_its_retail_store_and_reactivation_restores_it(): void
    {
        $own = $this->store('STORE-ACCESS-OWN');
        $foreign = $this->store('STORE-ACCESS-FOREIGN');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $access = app(StoreAccess::class);
        $this->assertFalse($access->hasGlobalStoreAccess($actor));
        $this->assertTrue($access->canAccess($actor, $own));
        $this->assertFalse($access->canAccess($actor, $foreign));
        $this->assertSame([$own->id], Store::query()->accessibleTo($actor)->pluck('id')->all());
        $this->assertSame([$own->id], $access->scopeStoreOwned(Store::query(), $actor, 'id')->pluck('id')->all());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertFalse($access->canAccess($actor, $own));
        $this->assertSame([], Store::query()->accessibleTo($actor)->pluck('id')->all());
        $this->assertSame([], $access->scopeStoreOwned(Store::query(), $actor, 'id')->pluck('id')->all());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($access->canAccess($actor->fresh(), $own));
        $this->assertFalse($access->canAccess($actor->fresh(), $foreign));
    }

    public function test_inactive_super_admin_role_does_not_grant_global_store_access(): void
    {
        $store = $this->store('STORE-ACCESS-ROLE-INACTIVE');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $access = app(StoreAccess::class);
        $this->assertTrue($access->canAccess($actor, $store));

        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertFalse($access->hasGlobalStoreAccess($actor));
        $this->assertFalse($access->canAccess($actor, $store));
        $this->assertSame([], Store::query()->accessibleTo($actor)->pluck('id')->all());
    }

    public function test_inactive_store_role_or_disabled_initial_actor_cannot_obtain_store_scope(): void
    {
        $store = $this->store('STORE-ACCESS-INITIAL-DENY');
        $actor = User::factory()->create(['is_active' => false]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $access = app(StoreAccess::class);
        $this->assertFalse($access->canAccess($actor, $store));
        $this->assertSame([], Store::query()->accessibleTo($actor)->pluck('id')->all());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($access->canAccess($actor->fresh(), $store));

        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertFalse($access->canAccess($actor->fresh(), $store));
        $this->assertSame([], Store::query()->accessibleTo($actor->fresh())->pluck('id')->all());
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
