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

/** P031: privilege does not turn inactive/nonexistent stores into live tenants. */
final class V2StoreAccessInactiveStoreAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_super_admin_denies_inactive_deleted_and_nonexistent_store_identifiers(): void
    {
        $store = $this->store('P031-STORE-ACCESS-ACTIVE');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $access = app(StoreAccess::class);

        $this->assertTrue($access->canAccess($actor, $store));
        $this->assertTrue($access->canAccess($actor, (int) $store->id));
        $this->assertFalse($access->canAccess($actor, 999999));

        $auditCount = DB::table('audit_logs')->count();
        DB::table('stores')->where('id', $store->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $store->is_active, 'Original Store model must remain stale.');
        $this->assertFalse($access->canAccess($actor, $store));
        $this->assertFalse($access->canAccess($actor, (int) $store->id));

        DB::table('stores')->where('id', $store->id)->update(['is_active' => true]);
        $this->assertTrue($access->canAccess($actor, $store));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_regular_store_role_maintains_existing_active_store_scope(): void
    {
        $own = $this->store('P031-STORE-ACCESS-OWN');
        $other = $this->store('P031-STORE-ACCESS-OTHER');
        $actor = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $access = app(StoreAccess::class);
        $this->assertTrue($access->canAccess($actor, (int) $own->id));
        $this->assertFalse($access->canAccess($actor, (int) $other->id));
        DB::table('stores')->where('id', $own->id)->update(['is_active' => false]);
        $this->assertFalse($access->canAccess($actor, $own));
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
