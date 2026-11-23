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

final class V2StoreAccessScopedChannelBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_scoped_role_never_admits_a_wholesale_store_even_with_explicit_assignment(): void
    {
        $retail = $this->store('P031-RETAIL-BOUNDARY', 'B2C');
        $wholesale = $this->store('P031-WHOLESALE-BOUNDARY', 'B2B');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $this->assign($actor, $role, $retail);
        $this->assign($actor, $role, $wholesale);
        $access = app(StoreAccess::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($access->canAccess($actor, $retail));
        $this->assertTrue($access->canAccess($actor, (int) $retail->id));
        $this->assertFalse($access->canAccess($actor, $wholesale));
        $this->assertFalse($access->canAccess($actor, (int) $wholesale->id));
        $this->assertFalse($access->canAccess($actor, 99999999));
        $this->assertEqualsCanonicalizing(
            [$retail->id],
            $access->scopeStores(Store::query()->whereKey([$retail->id, $wholesale->id]), $actor)
                ->pluck('id')->all(),
        );

        DB::table('stores')->where('id', $retail->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $retail->is_active, 'Store model is intentionally stale.');
        $this->assertFalse($access->canAccess($actor, $retail));
        DB::table('stores')->where('id', $retail->id)->update(['is_active' => true]);
        $this->assertTrue($access->canAccess($actor, $retail));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertFalse($access->canAccess($actor, $retail));
        $this->assertFalse($access->canAccess($actor, $wholesale));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_global_super_admin_retains_existing_active_wholesale_access(): void
    {
        $wholesale = $this->store('P031-WHOLESALE-SUPER-ADMIN', 'B2B');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $access = app(StoreAccess::class);

        $this->assertTrue($access->canAccess($actor, $wholesale));
        DB::table('stores')->where('id', $wholesale->id)->update(['is_active' => false]);
        $this->assertFalse($access->canAccess($actor, $wholesale));
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

    private function assign(User $user, Role $role, Store $store): void
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
