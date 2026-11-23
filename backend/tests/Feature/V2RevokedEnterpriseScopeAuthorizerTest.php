<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** P031: a resolved tenant context must not survive persisted user revocation. */
final class V2RevokedEnterpriseScopeAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_cached_super_admin_context_loses_permission_after_database_revocation(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $context = new ResolvedTenantContext(
            userId: (int) $actor->id,
            channel: 'b2b',
            storeId: null,
            supportAccess: true,
        );
        $authorizer = app(EnterpriseScopeAuthorizer::class);

        $this->assertTrue($authorizer->allows($actor, $context, 'finance.view', 'organization', 1));
        $before = DB::table('audit_logs')->count();

        // User model and previously issued tenant context remain cached.
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $this->assertFalse($authorizer->allows($actor, $context, 'finance.view', 'organization', 1));
        $this->assertFalse($authorizer->allows($actor->fresh(), $context, 'finance.view', 'organization', 1));

        try {
            $authorizer->authorize($actor, $context, 'finance.view', 'organization', 1);
            $this->fail('A revoked identity must never reuse a previously valid context.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame($before, DB::table('audit_logs')->count());
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($authorizer->allows($actor->fresh(), $context, 'finance.view', 'organization', 1));
    }

    public function test_disabled_regular_user_cannot_authorize_with_existing_context(): void
    {
        $actor = User::factory()->create(['is_active' => false]);
        $context = new ResolvedTenantContext(
            userId: (int) $actor->id,
            channel: 'b2b',
            storeId: null,
        );

        $this->assertFalse(
            app(EnterpriseScopeAuthorizer::class)->allows(
                $actor, $context, 'finance.view', 'organization', 1,
            ),
        );
    }
}
