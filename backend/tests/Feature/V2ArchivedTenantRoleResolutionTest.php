<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class V2ArchivedTenantRoleResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_active_wholesale_role_does_not_resolve_wholesale_tenant(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $resolver = app(TenantContextResolver::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertSame('b2b', $resolver->wholesale($actor)->channel);

        // Preserve historical active bit to exercise the archived-active edge case.
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);

        foreach ([$actor, $actor->fresh()] as $candidate) {
            try {
                $resolver->wholesale($candidate);
                $this->fail('Archived Wholesale role must not resolve the B2B tenant.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_archived_active_store_role_cannot_enumerate_or_resolve_retail_store(): void
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'P031-ARCHIVED-TENANT-RETAIL',
            'name' => 'P031 Archived Retail',
            'is_active' => true,
        ]);
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resolver = app(TenantContextResolver::class);
        $auditCount = DB::table('audit_logs')->count();

        $this->assertSame([$store->id], $resolver->retailStoreIds($actor));
        $this->assertSame('b2c', $resolver->retail($actor, (int) $store->id)->channel);

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) $role->is_active);

        $this->assertSame([], $resolver->retailStoreIds($actor));
        $this->assertNull($resolver->defaultRetailStoreId($actor));
        try {
            $resolver->retail($actor, (int) $store->id);
            $this->fail('Archived Retail role must not resolve a store tenant.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame([], $resolver->retailStoreIds($actor->fresh()));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }
}
