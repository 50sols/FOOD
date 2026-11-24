<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Role;
use App\Models\User;
use App\Policies\B2bCustomerPolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class V2RevokedB2bCustomerPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_revoked_cached_customer_cannot_view_or_update_own_wholesale_profile(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $own = B2bCustomer::query()->create(['user_id' => $actor->id, 'name' => 'Own B2B']);
        $foreign = B2bCustomer::query()->create(['user_id' => $other->id, 'name' => 'Foreign B2B']);
        $policy = app(B2bCustomerPolicy::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($policy->view($actor, $own));
        $this->assertTrue($policy->update($actor, $own));
        $this->assertTrue(Gate::forUser($actor)->allows('view', $own));
        $this->assertFalse($policy->view($actor, $foreign));
        $this->assertFalse($policy->update($actor, $foreign));
        $this->assertFalse($policy->delete($actor, $own));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active, 'Cached identity must remain stale for this test.');
        $this->assertFalse($policy->view($actor, $own));
        $this->assertFalse($policy->update($actor, $own));
        $this->assertFalse($policy->delete($actor, $own));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $own));
        $this->assertFalse($policy->view($actor->fresh(), $own));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($policy->view($actor->fresh(), $own));
        $this->assertTrue($policy->update($actor->fresh(), $own));
        $this->assertFalse($policy->view($actor->fresh(), $foreign));
    }

    public function test_revoked_wholesale_staff_cannot_retain_cross_customer_permissions(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $customer = B2bCustomer::query()->create(['user_id' => $owner->id, 'name' => 'Wholesale profile']);
        $staff = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $staff->roles()->attach($role);
        $policy = app(B2bCustomerPolicy::class);

        $this->assertTrue($policy->view($staff, $customer));
        $this->assertTrue($policy->update($staff, $customer));
        $this->assertTrue($policy->delete($staff, $customer));

        DB::table('users')->where('id', $staff->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $staff->is_active);
        $this->assertFalse($policy->view($staff, $customer));
        $this->assertFalse($policy->update($staff, $customer));
        $this->assertFalse($policy->delete($staff, $customer));

        DB::table('users')->where('id', $staff->id)->update(['is_active' => true]);
        $this->assertTrue($policy->view($staff->fresh(), $customer));
        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertFalse($policy->view($staff->fresh(), $customer));
        $this->assertFalse($policy->update($staff->fresh(), $customer));
        $this->assertFalse($policy->delete($staff->fresh(), $customer));
    }
}
