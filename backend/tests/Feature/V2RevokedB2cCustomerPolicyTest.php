<?php

namespace Tests\Feature;

use App\Models\B2cCustomer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Policies\B2cCustomerPolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class V2RevokedB2cCustomerPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_revoked_cached_retail_customer_cannot_read_or_update_own_record(): void
    {
        $store = $this->store('B2C-POLICY-OWN');
        $actor = User::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['is_active' => true]);
        $own = B2cCustomer::query()->create(['store_id' => $store->id, 'user_id' => $actor->id, 'name' => 'Own customer']);
        $other = B2cCustomer::query()->create(['store_id' => $store->id, 'user_id' => $stranger->id, 'name' => 'Other customer']);
        $policy = app(B2cCustomerPolicy::class);
        $audit = DB::table('audit_logs')->count();

        $this->assertTrue($policy->view($actor, $own));
        $this->assertTrue($policy->update($actor, $own));
        $this->assertTrue(Gate::forUser($actor)->allows('view', $own));
        $this->assertFalse($policy->view($actor, $other));
        $this->assertFalse($policy->update($actor, $other));
        $this->assertFalse($policy->delete($actor, $own));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active, 'Actor instance must remain stale.');
        $this->assertFalse($policy->view($actor, $own));
        $this->assertFalse($policy->update($actor, $own));
        $this->assertFalse($policy->delete($actor, $own));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $own));
        $this->assertFalse($policy->view($actor->fresh(), $own));
        $this->assertSame($audit, DB::table('audit_logs')->count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($policy->view($actor->fresh(), $own));
        $this->assertTrue($policy->update($actor->fresh(), $own));
        $this->assertFalse($policy->view($actor->fresh(), $other));
    }

    public function test_revoked_store_admin_loses_permission_on_assigned_store_and_never_sees_foreign_store(): void
    {
        $ownStore = $this->store('B2C-POLICY-RETAIL-A');
        $otherStore = $this->store('B2C-POLICY-RETAIL-B');
        $own = B2cCustomer::query()->create(['store_id' => $ownStore->id, 'name' => 'Assigned retail customer']);
        $foreign = B2cCustomer::query()->create(['store_id' => $otherStore->id, 'name' => 'Foreign retail customer']);
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $ownStore->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $policy = app(B2cCustomerPolicy::class);

        $this->assertTrue($policy->view($actor, $own));
        $this->assertTrue($policy->update($actor, $own));
        $this->assertTrue($policy->delete($actor, $own));
        $this->assertFalse($policy->view($actor, $foreign));
        $this->assertFalse($policy->update($actor, $foreign));
        $this->assertFalse($policy->delete($actor, $foreign));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertFalse($policy->view($actor, $own));
        $this->assertFalse($policy->update($actor, $own));
        $this->assertFalse($policy->delete($actor, $own));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($policy->view($actor->fresh(), $own));
        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertFalse($policy->view($actor->fresh(), $own));
        $this->assertFalse($policy->delete($actor->fresh(), $own));
    }

    public function test_super_admin_still_cannot_override_retail_customer_scope(): void
    {
        $store = $this->store('B2C-POLICY-SUPER');
        $customer = B2cCustomer::query()->create(['store_id' => $store->id, 'name' => 'Retail customer']);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $policy = app(B2cCustomerPolicy::class);

        $this->assertFalse($policy->view($actor, $customer));
        $this->assertFalse($policy->update($actor, $customer));
        $this->assertFalse($policy->delete($actor, $customer));
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertFalse($policy->view($actor, $customer));
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
