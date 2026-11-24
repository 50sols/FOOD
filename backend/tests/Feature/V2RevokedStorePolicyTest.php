<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Policies\StorePolicy;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class V2RevokedStorePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_stale_revoked_retail_actor_loses_store_list_policy_and_direct_store_access(): void
    {
        $own = $this->store('STORE-POLICY-OWN');
        $foreign = $this->store('STORE-POLICY-FOREIGN');
        $actor = User::factory()->create(['is_active' => true]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id;
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own->id,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $policy = app(StorePolicy::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertTrue($policy->viewAny($actor));
        $this->assertTrue(Gate::forUser($actor)->allows('viewAny', Store::class));
        $this->assertTrue($policy->view($actor, $own));
        $this->assertFalse($policy->view($actor, $foreign));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active, 'The cached actor must remain stale.');
        $this->assertFalse($policy->viewAny($actor));
        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', Store::class));
        $this->assertFalse($policy->view($actor, $own));
        $this->assertFalse($policy->viewAny($actor->fresh()));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($policy->viewAny($actor->fresh()));
        $this->assertTrue($policy->view($actor->fresh(), $own));
        $this->assertFalse($policy->view($actor->fresh(), $foreign));
    }

    public function test_super_admin_list_policy_denies_stale_revocation_and_inactive_loaded_actor(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $policy = app(StorePolicy::class);

        $this->assertTrue($policy->viewAny($actor));
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertFalse($policy->viewAny($actor));
        $this->assertFalse($policy->viewAny($actor->fresh()));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($policy->viewAny($actor->fresh()));
        $actor->is_active = false;
        $this->assertFalse($policy->viewAny($actor));
    }

    public function test_active_unassigned_identity_can_request_list_but_initially_disabled_identity_cannot(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $policy = app(StorePolicy::class);
        $this->assertTrue($policy->viewAny($actor));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertFalse($policy->viewAny($actor));
        $this->assertFalse($policy->viewAny($actor->fresh()));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($policy->viewAny($actor->fresh()));
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
