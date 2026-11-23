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

/** P031: historical role assignments never reactivate a disabled identity. */
final class V2DisabledTenantContextResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_revoked_retail_actor_cannot_resolve_context_or_enumerate_stores(): void
    {
        $store = $this->retailStore('P031-REVOKED-RETAIL');
        $user = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resolver = app(TenantContextResolver::class);

        $this->assertSame((int) $store->id, $resolver->retail($user, (int) $store->id)->storeId);
        $this->assertSame([(int) $store->id], $resolver->retailStoreIds($user));
        $this->assertSame((int) $store->id, $resolver->defaultRetailStoreId($user));

        $user->forceFill(['is_active' => false])->save();
        $revoked = $user->fresh();

        $this->assertSame([], $resolver->retailStoreIds($revoked));
        $this->assertNull($resolver->defaultRetailStoreId($revoked));
        try {
            $resolver->retail($revoked, (int) $store->id);
            $this->fail('Disabled Retail user must never resolve a tenant context.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $user->id, 'store_id' => $store->id,
        ]);
    }

    public function test_revoked_wholesale_actor_cannot_resolve_historical_global_role(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $resolver = app(TenantContextResolver::class);
        $this->assertSame('b2b', $resolver->wholesale($user)->channel);

        $user->forceFill(['is_active' => false])->save();
        try {
            $resolver->wholesale($user->fresh());
            $this->fail('Disabled Wholesale actor must not resolve a tenant context.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_revoked_super_admin_cannot_resolve_retail_support_or_write_audit(): void
    {
        $store = $this->retailStore('P031-REVOKED-SUPPORT');
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $resolver = app(TenantContextResolver::class);

        // Keep the valid explicit support capability; only revoked users fail.
        $this->assertTrue($resolver->retail($user, (int) $store->id, true)->supportAccess);
        $this->assertContains((int) $store->id, $resolver->retailStoreIds($user));
        $user->forceFill(['is_active' => false])->save();
        $beforeAudit = DB::table('audit_logs')->count();

        $this->assertSame([], $resolver->retailStoreIds($user->fresh()));
        try {
            $resolver->retail($user->fresh(), (int) $store->id, true);
            $this->fail('Revoked SUPER_ADMIN must not enter support mode.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
    }

    private function retailStore(string $code): Store
    {
        return Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }
}
