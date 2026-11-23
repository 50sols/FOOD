<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\LookupScopeService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2RevokedLookupScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_stale_revoked_super_admin_cannot_read_or_mutate_global_lookups(): void
    {
        $lookup = Unit::query()->create([
            'code' => 'REVOCATION-U',
            'name' => 'Revocation Test',
            'scope' => 'global',
            'is_active' => true,
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $scope = app(LookupScopeService::class);
        $request = Request::create('/admin/lookups', 'POST');
        $auditCount = DB::table('audit_logs')->count();

        $this->assertContains($lookup->id, $scope->visible(Unit::query(), $actor, 'units')->pluck('id')->all());
        $this->assertSame('global', $scope->authorizeMutation($actor, 'global', null, false, $request));
        $this->assertTrue($scope->canManage($actor, 'global', null));
        $this->assertSame(['global', 'b2b', 'store'], $scope->manageableScopes($actor));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active, 'The loaded model must remain stale.');
        $this->assertSame([], $scope->visible(Unit::query(), $actor, 'units')->pluck('id')->all());
        $this->assertSame([], $scope->visible(Unit::query(), $actor->fresh(), 'units')->pluck('id')->all());
        $this->assertFalse($scope->canManage($actor, 'global', null));
        $this->assertSame([], $scope->manageableScopes($actor));
        $this->assertSame([], $scope->visibleRetailStores($actor)->all());
        try {
            $scope->authorizeMutation($actor, 'global', null, false, $request);
            $this->fail('Revoked identity must not authorize a global lookup mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($auditCount, DB::table('audit_logs')->count());

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertContains($lookup->id, $scope->visible(Unit::query(), $actor->fresh(), 'units')->pluck('id')->all());
        $this->assertTrue($scope->canManage($actor->fresh(), 'global', null));
    }

    public function test_stale_revoked_wholesale_role_loses_wholesale_lookup_access(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $scope = app(LookupScopeService::class);
        $this->assertTrue($scope->canAccessWholesale($actor));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertFalse($scope->canAccessWholesale($actor));
        $this->assertSame([], $scope->manageableScopes($actor));

        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($scope->canAccessWholesale($actor->fresh()));

        DB::table('roles')->where('id', $role->id)->update(['is_active' => false]);
        $this->assertFalse($scope->canAccessWholesale($actor->fresh()));
    }
}
