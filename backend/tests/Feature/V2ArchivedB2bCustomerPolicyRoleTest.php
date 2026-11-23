<?php

namespace Tests\Feature;

use App\Models\B2bCustomer;
use App\Models\Role;
use App\Models\User;
use App\Policies\B2bCustomerPolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class V2ArchivedB2bCustomerPolicyRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_but_still_active_wholesale_admin_no_longer_qualifies_for_cross_customer_reads(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['is_active' => true]);
        $customer = B2bCustomer::query()->create(['user_id' => $owner->id, 'name' => 'Wholesale customer']);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $staff->roles()->attach($role);
        $policy = app(B2bCustomerPolicy::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($this->wholesaleEligible($policy, $staff));
        $this->assertTrue($policy->view($staff, $customer));
        $this->assertFalse($policy->delete($staff, $customer));

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));
        $this->assertFalse($this->wholesaleEligible($policy, $staff), 'An active flag alone must not revive an archived role.');
        $this->assertFalse($this->wholesaleEligible($policy, $staff->fresh()));
        $this->assertFalse($policy->view($staff, $customer));
        $this->assertFalse($policy->update($staff, $customer));
        $this->assertFalse($policy->delete($staff, $customer));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_archived_super_admin_role_cannot_qualify_for_wholesale_customer_mutations(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $staff = User::factory()->create(['is_active' => true]);
        $customer = B2bCustomer::query()->create(['user_id' => $owner->id, 'name' => 'Wholesale customer 2']);
        $role = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $staff->roles()->attach($role);
        $policy = app(B2bCustomerPolicy::class);

        $this->assertTrue($this->wholesaleEligible($policy, $staff));
        $this->assertTrue($policy->view($staff, $customer));
        $this->assertTrue($policy->update($staff, $customer));
        $this->assertTrue($policy->delete($staff, $customer));

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertFalse($this->wholesaleEligible($policy, $staff));
        $this->assertFalse($policy->view($staff, $customer));
        $this->assertFalse($policy->update($staff, $customer));
        $this->assertFalse($policy->delete($staff, $customer));
    }

    public function test_archiving_wholesale_staff_role_does_not_revoke_active_customer_ownership(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $customer = B2bCustomer::query()->create(['user_id' => $user->id, 'name' => 'Personal wholesale profile']);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $user->roles()->attach($role);
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $policy = app(B2bCustomerPolicy::class);

        $this->assertFalse($this->wholesaleEligible($policy, $user));
        $this->assertTrue($policy->view($user, $customer));
        $this->assertTrue($policy->update($user, $customer));
        $this->assertFalse($policy->delete($user, $customer));
    }

    private function wholesaleEligible(B2bCustomerPolicy $policy, User $user): bool
    {
        // Isolate the policy's own role-eligibility gate, before the independent
        // User permission check can also reject an archived role.
        return (bool) (new ReflectionMethod($policy, 'canAccessWholesale'))->invoke($policy, $user);
    }
}
