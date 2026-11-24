<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SystemLookupController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2ArchivedSystemLookupAdmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_active_store_role_loses_lookup_view_admission(): void
    {
        $store = $this->retailStore('P031-SYSTEM-LOOKUP');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'lookups.view'], ['name' => 'Lookups view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->assign($actor, $role, $store);
        $auditCount = DB::table('audit_logs')->count();

        $this->authorizeView($actor);

        // Simulate a historical role archived without clearing is_active.
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);
        $this->assertDenied(fn () => $this->authorizeView($actor));
        $this->assertDenied(fn () => $this->authorizeView($actor->fresh()));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_persisted_revocation_rejects_stale_store_actor_and_super_admin(): void
    {
        $store = $this->retailStore('P031-SYSTEM-REVOKED');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'lookups.view'], ['name' => 'Lookups view'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->assign($actor, $role, $store);
        $this->authorizeView($actor);

        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertDenied(fn () => $this->authorizeView($actor));
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->authorizeView($actor->fresh());

        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $this->authorizeView($admin);
        DB::table('users')->where('id', $admin->id)->update(['is_active' => false]);
        $this->assertDenied(fn () => $this->authorizeView($admin));
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code, 'name' => $code, 'is_active' => true,
        ]);
    }

    private function assign(User $user, Role $role, Store $store): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id, 'role_id' => $role->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function authorizeView(User $user): void
    {
        (new ReflectionMethod(SystemLookupController::class, 'authorizeView'))
            ->invoke(app(SystemLookupController::class), $user);
    }

    private function assertDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Revoked lookup authority must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
