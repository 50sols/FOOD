<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\EnterpriseSecurityAccessResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: cached user models are not proof of an active enterprise identity. */
final class V2RevokedEnterpriseSecurityIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_cached_super_admin_cannot_use_enterprise_shortcut_after_database_revocation(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $resolver = app(EnterpriseSecurityAccessResolver::class);
        $this->assertTrue($resolver->allows($actor, 'finance.post', 'platform', null));

        // Offboard the identity through a separate database update while the
        // current request still holds the old is_active=true Eloquent model.
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        $this->assertTrue((bool) $actor->is_active);
        $beforeAudit = DB::table('audit_logs')->count();

        $this->assertFalse($resolver->allows($actor, 'finance.post', 'platform', null));
        $this->assertFalse($resolver->allows($actor, 'reports.view', 'store', 999999));
        $this->assertFalse($resolver->allows($actor->fresh(), 'finance.post', 'platform', null));
        $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

        // Reactivation restores the normal authorized behavior on a fresh
        // User without mutating the original role relationship.
        DB::table('users')->where('id', $actor->id)->update(['is_active' => true]);
        $this->assertTrue($resolver->allows($actor->fresh(), 'finance.post', 'platform', null));
    }

    public function test_inactive_regular_identity_never_acquires_arbitrary_enterprise_permission(): void
    {
        $actor = User::factory()->create(['is_active' => false]);
        $this->assertFalse(
            app(EnterpriseSecurityAccessResolver::class)
                ->allows($actor, 'finance.view', 'store', 1234),
        );
    }
}
