<?php

namespace Tests\Feature;

use App\Domain\Assistant\Security\AiPrincipalContextResolver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class V2ArchivedAiPrincipalRoleContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_global_role_is_excluded_while_independent_active_role_remains(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $active = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $historical = Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail();
        $actor->roles()->attach([$active->id, $historical->id]);
        $resolver = app(AiPrincipalContextResolver::class);
        $context = ['path' => '/admin/b2b/dashboard', 'channel' => 'b2b'];
        $auditCount = DB::table('audit_logs')->count();

        $before = $resolver->resolve($actor, 'en', $context, 'v2-role-before');
        $this->assertContains('B2B_ADMIN', $before->roleCodes);
        $this->assertContains('SUPER_ADMIN', $before->roleCodes);

        DB::table('roles')->where('id', $historical->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $historical->id)->value('is_active'));
        foreach ([$actor, $actor->fresh()] as $currentActor) {
            $principal = $resolver->resolve($currentActor, 'en', $context, 'v2-global-after');
            $this->assertContains('B2B_ADMIN', $principal->roleCodes);
            $this->assertNotContains('SUPER_ADMIN', $principal->roleCodes);
        }
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_archived_retail_role_does_not_enter_authorized_store_principal_projection(): void
    {
        $ownStore = $this->retailStore('AI-ARCHIVE-OWN');
        $foreign = $this->retailStore('AI-ARCHIVE-FOREIGN');
        $actor = User::factory()->create(['is_active' => true]);
        $archived = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $active = Role::query()->create([
            'code' => 'AI_P031_ACTIVE_RETAIL',
            'name' => 'AI scoped assistant operator',
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);
        $active->permissions()->attach(Permission::query()->where('code', 'assistant.use')->firstOrFail());
        foreach ([$archived, $active] as $role) {
            DB::table('user_store_roles')->insert([
                'user_id' => $actor->id,
                'store_id' => $ownStore->id,
                'role_id' => $role->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resolver = app(AiPrincipalContextResolver::class);
        $page = ['path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $ownStore->id];
        $before = $resolver->resolve($actor, 'en', $page, 'v2-store-before');
        $this->assertContains('AI_P031_ACTIVE_RETAIL', $before->roleCodes);
        $this->assertContains('B2C_STORE_ADMIN', $before->roleCodes);
        $this->assertSame([$ownStore->id], $before->permittedStoreIds);

        DB::table('roles')->where('id', $archived->id)->update(['archived_at' => now()]);
        foreach ([$actor, $actor->fresh()] as $currentActor) {
            $principal = $resolver->resolve($currentActor, 'en', $page, 'v2-store-after');
            $this->assertContains('AI_P031_ACTIVE_RETAIL', $principal->roleCodes);
            $this->assertNotContains('B2C_STORE_ADMIN', $principal->roleCodes);
            $this->assertSame([$ownStore->id], $principal->permittedStoreIds);
        }

        $this->expectException(NotFoundHttpException::class);
        $resolver->resolve($actor, 'en', [
            'path' => '/admin/b2c/dashboard', 'channel' => 'b2c', 'store_id' => $foreign->id,
        ], 'v2-foreign-denied');
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
