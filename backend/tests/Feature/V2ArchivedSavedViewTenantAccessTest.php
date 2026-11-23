<?php

namespace Tests\Feature;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\EnterpriseSavedViewService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2ArchivedSavedViewTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_but_active_retail_role_cannot_access_saved_views(): void
    {
        $store = $this->store('P031-SAVED-RETAIL', 'B2C');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id, 'store_id' => $store->id,
            'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(EnterpriseSavedViewService::class);
        $auditBefore = DB::table('audit_logs')->count();
        $service->assertCanAccessScope($actor, 'enterprise.orders', 'store', (int) $store->id);

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);
        $this->assertDenied(function () use ($service, $actor, $store): void {
            $service->assertCanAccessScope($actor, 'enterprise.orders', 'store', (int) $store->id);
        });
        $this->assertDenied(function () use ($service, $actor, $store): void {
            $service->visible($actor, 'enterprise.orders', 'store', (int) $store->id);
        });
        $this->assertDenied(function () use ($service, $actor, $store): void {
            $service->assertCanAccessScope($actor->fresh(), 'enterprise.orders', 'store', (int) $store->id);
        });
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_archived_but_active_wholesale_global_role_cannot_access_saved_views(): void
    {
        $store = $this->store('P031-SAVED-WHOLESALE', 'B2B');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $service = app(EnterpriseSavedViewService::class);
        $auditBefore = DB::table('audit_logs')->count();

        $service->assertCanAccessScope($actor, 'enterprise.orders', 'store', (int) $store->id);

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);
        $this->assertDenied(function () use ($service, $actor, $store): void {
            $service->assertCanAccessScope($actor, 'enterprise.orders', 'store', (int) $store->id);
        });
        $this->assertDenied(function () use ($service, $actor, $store): void {
            $service->visible($actor, 'enterprise.orders', 'store', (int) $store->id);
        });
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    private function store(string $code, string $channel): Store
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', $channel)->value('id'),
            'code' => $code, 'name' => $code, 'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function assertDenied(callable $action): void
    {
        try {
            $action();
            $this->fail('Archived role must not authorize tenant Saved View access.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
