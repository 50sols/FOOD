<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarArchivedRoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_archived_historical_role_hide_cannot_suppress_a_separately_authorized_sidebar_link(): void
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $authorizedRole = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $user->roles()->attach($authorizedRole->id);

        // Simulate inconsistent legacy data: an archive marker was recorded
        // but the historical is_active flag was not cleared.
        $archivedRole = Role::query()->create([
            'code' => 'V2_HISTORICAL_SIDEBAR_ROLE',
            'name' => 'Historical Sidebar Role',
            'scope' => 'global',
            'is_active' => true,
            'is_system' => false,
            'archived_at' => now(),
        ]);
        $user->roles()->attach($archivedRole->id);

        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $archivedRole->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignmentsBefore = DB::table('role_user')->where('user_id', $user->id)->count();
        $overridesBefore = DB::table('admin_sidebar_role_visibility')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();

            $keys = $this->sidebarKeys($user->fresh());
            $this->assertContains('catalog_management', $keys);
            $this->assertTrue($user->fresh()->hasPermission('catalog.view'));
        }

        $this->assertSame($assignmentsBefore, DB::table('role_user')->where('user_id', $user->id)->count());
        $this->assertSame($overridesBefore, DB::table('admin_sidebar_role_visibility')->count());

        // An effective current role's explicit hide preference still wins.
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $authorizedRole->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotContains('catalog_management', $this->sidebarKeys($user->fresh()));
        }

        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)
            ->get(route('admin.catalog.index', [], false))
            ->assertForbidden();
    }

    /** @return list<string> */
    private function sidebarKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }
}
