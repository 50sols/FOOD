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

final class V2ArchivedNotificationAudienceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archiving_an_active_retail_role_revokes_store_notification_targeting_for_stale_actor(): void
    {
        $ownStore = $this->retailStore('P031-NOTIFICATION-OWN');
        $foreignStore = $this->retailStore('P031-NOTIFICATION-FOREIGN');
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $this->assign($actor, $ownStore, $role);
        $audience = app(NotificationAudience::class);
        $auditBefore = DB::table('audit_logs')->count();

        $this->assertTrue($audience->matchesTarget($actor, 'all', 'all', 'all', $ownStore->id));
        $this->assertFalse($audience->matchesTarget($actor, 'all', 'all', 'all', $foreignStore->id));

        DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
        $this->assertTrue((bool) DB::table('roles')->where('id', $role->id)->value('is_active'));

        $this->assertFalse($audience->matchesTarget($actor, 'all', 'all', 'all', $ownStore->id));
        $this->assertFalse($audience->matchesTarget($actor->fresh(), 'all', 'all', 'all', $ownStore->id));
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_archiving_one_role_preserves_a_separate_active_retail_store_assignment(): void
    {
        $archivedStore = $this->retailStore('P031-NOTIFICATION-ARCHIVED');
        $activeStore = $this->retailStore('P031-NOTIFICATION-ACTIVE');
        $actor = User::factory()->create(['is_active' => true]);
        $archivedRole = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        $activeRole = Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail();
        $this->assign($actor, $archivedStore, $archivedRole);
        $this->assign($actor, $activeStore, $activeRole);
        $audience = app(NotificationAudience::class);

        $this->assertTrue($audience->matchesTarget($actor, 'all', 'all', 'all', $archivedStore->id));
        $this->assertTrue($audience->matchesTarget($actor, 'all', 'all', 'all', $activeStore->id));

        DB::table('roles')->where('id', $archivedRole->id)->update(['archived_at' => now()]);

        $this->assertFalse($audience->matchesTarget($actor, 'all', 'all', 'all', $archivedStore->id));
        $this->assertTrue($audience->matchesTarget($actor, 'all', 'all', 'all', $activeStore->id));
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

    private function assign(User $actor, Store $store, Role $role): void
    {
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
