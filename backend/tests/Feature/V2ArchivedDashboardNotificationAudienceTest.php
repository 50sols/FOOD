<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\NotificationAudience;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2ArchivedDashboardNotificationAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_global_role_revokes_dashboard_app_targeting_for_stale_user(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $actor->roles()->attach($role);
        $audience = app(NotificationAudience::class);
        $auditBefore = DB::table('audit_logs')->count();

        $this->assertTrue($audience->matchesTarget($actor, 'all', 'dashboard', 'all'));

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));
        $this->assertFalse($audience->matchesTarget($actor, 'all', 'dashboard', 'all'));
        $this->assertFalse($audience->matchesTarget($actor->fresh(), 'all', 'dashboard', 'all'));
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_archived_retail_dashboard_role_does_not_keep_dashboard_app_targeting(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $store = $this->retailStore('P031-DASH-NOTIFICATION');
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $audience = app(NotificationAudience::class);

        $this->assertTrue($audience->matchesTarget($actor, 'all', 'dashboard', 'all'));
        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertFalse($audience->matchesTarget($actor, 'all', 'dashboard', 'all'));
    }

    public function test_separate_active_dashboard_role_preserves_app_targeting_after_other_role_archive(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $archived = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $retained = Role::query()->where('code', 'OPERATIONS')->firstOrFail();
        $actor->roles()->attach([$archived->id, $retained->id]);
        $audience = app(NotificationAudience::class);
        DB::table('roles')->where('id', $archived->id)->update(['archived_at' => now()]);

        $this->assertTrue($audience->matchesTarget($actor, 'all', 'dashboard', 'all'));
        $this->assertFalse($audience->matchesTarget($actor, 'all', 'customer', 'all'));
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
