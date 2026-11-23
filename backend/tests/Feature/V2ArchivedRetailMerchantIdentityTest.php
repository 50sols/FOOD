<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailMerchantIdentityService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArchivedRetailMerchantIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_but_active_retail_manager_role_cannot_resolve_merchant_identity(): void
    {
        $store = $this->store('ARCHIVED-MERCHANT-RETAIL', 'B2C');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $this->assign($actor, $store, $role);
        $identity = app(RetailMerchantIdentityService::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertSame([$store->id], $identity->managedRetailStoreIds($actor));
        $this->assertSame([$store->id], $identity->retailStoreIds($actor));
        $this->assertTrue($identity->isRetailMerchant($actor));
        $this->assertSame([$store->id], $identity->identityPayload($actor)['managed_retail_store_ids']);

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));
        $this->assertSame([], $identity->managedRetailStoreIds($actor));
        $this->assertSame([], $identity->managedRetailStoreIds($actor->fresh()));
        $this->assertSame([], $identity->retailStoreIds($actor));
        $this->assertFalse($identity->isRetailMerchant($actor));
        $this->assertFalse($identity->identityPayload($actor)['retail_merchant']);
        $this->assertSame([], $identity->identityPayload($actor)['managed_retail_store_ids']);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_retail_merchant_role_cannot_admit_wholesale_or_inactive_stores(): void
    {
        $retail = $this->store('ACTIVE-MERCHANT-RETAIL', 'B2C');
        $wholesale = $this->store('ACTIVE-MERCHANT-WHOLESALE', 'B2B');
        $inactive = $this->store('INACTIVE-MERCHANT-RETAIL', 'B2C', false);
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        foreach ([$retail, $wholesale, $inactive] as $store) {
            $this->assign($actor, $store, $role);
        }

        $identity = app(RetailMerchantIdentityService::class);
        $this->assertSame([$retail->id], $identity->managedRetailStoreIds($actor));

        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertSame([], $identity->managedRetailStoreIds($actor));
        $this->assertSame([], $identity->retailStoreIds($actor));
    }

    private function store(string $code, string $channel, bool $active = true): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => $active,
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
