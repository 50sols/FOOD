<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2RevokedOperationalTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_stale_revoked_super_admin_cannot_enumerate_or_enter_retail_stores(): void
    {
        $storeId = $this->store('REVOCATION-RETAIL');
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $scope = app(OperationalTenantScope::class);

        $this->assertContains($storeId, $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));
        $this->assertSame('b2c', $scope->assertStore($actor, $storeId, 'orders.view', 'B2C'));
        $auditCount = DB::table('audit_logs')->count();

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active, 'The original Eloquent actor must remain stale.');
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));
        $this->assertSame([], $scope->allowedStoreIds($actor->fresh(), 'orders.view', 'B2C'));
        $this->assertStoreDenied($scope, $actor, $storeId);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertContains($storeId, $scope->allowedStoreIds($actor->fresh(), 'orders.view', 'B2C'));
    }

    public function test_stale_revoked_retail_role_cannot_reuse_store_assignment(): void
    {
        $storeId = $this->store('REVOCATION-ROLE');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scope = app(OperationalTenantScope::class);
        $this->assertSame([$storeId], $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));
        $this->assertStoreDenied($scope, $actor, $storeId);
        $this->assertSame([], $scope->allowedStoreIds($actor->fresh(), 'orders.view', 'B2C'));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertSame([$storeId], $scope->allowedStoreIds($actor->fresh(), 'orders.view', 'B2C'));
    }

    private function assertStoreDenied(OperationalTenantScope $scope, User $actor, int $storeId): void
    {
        try {
            $scope->assertStore($actor, $storeId, 'orders.view', 'B2C');
            $this->fail('Persisted revocation must block the previously authorized store.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
