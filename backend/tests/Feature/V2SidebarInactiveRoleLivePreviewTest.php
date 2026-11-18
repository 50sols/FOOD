<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SidebarInactiveRoleLivePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_picker_marks_inactive_and_archived_historical_grants_ineligible_in_both_locales(): void
    {
        $administrator = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $administrator->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail()->id);

        $active = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $inactive = $this->historicalRole('V2_P006_PREVIEW_INACTIVE', false, false, $active);
        $archived = $this->historicalRole('V2_P006_PREVIEW_ARCHIVED', true, true, $active);

        foreach ([$inactive, $archived] as $role) {
            DB::table('admin_sidebar_role_visibility')->insert([
                'role_id' => $role->id,
                'item_key' => 'catalog_management',
                'is_visible' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $history = DB::table('permission_role')->whereIn('role_id', [$inactive->id, $archived->id])->count();
        $visibilityHistory = DB::table('admin_sidebar_role_visibility')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $administrator->forceFill(['locale' => $locale])->save();

            foreach ([
                [$active, 'active', true],
                [$inactive, 'inactive', false],
                [$archived, 'archived', false],
            ] as [$role, $state, $expectedAllowed]) {
                $response = $this->actingAs($administrator->fresh())
                    ->get(route('admin.sidebar-access.roles', ['role_id' => $role->id], false));

                $response->assertOk()
                    ->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('data-preview-role-state="'.$state.'"', false);

                $node = collect($response->viewData('matrix')['groups'])
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->firstWhere('key', 'catalog_management');
                $this->assertIsArray($node);
                $this->assertSame($expectedAllowed, $node['base_allowed']);
                $this->assertSame($expectedAllowed, $node['visible_for_role']);

                if ($state === 'active') {
                    $response->assertDontSee('data-v2-role-status="inactive"', false)
                        ->assertDontSee('data-v2-role-status="archived"', false);
                } else {
                    $response->assertSee('data-v2-role-status="'.$state.'"', false);
                }

                $this->assertSame(1, preg_match(
                    '/<aside class="v2-resulting-sidebar".*?<\/aside>/s',
                    (string) $response->getContent(),
                    $preview,
                ));
                // Selecting another role only changes a read-only result tree.
                // It must not advertise fabricated direct routes that would 403.
                $this->assertStringNotContainsString('<a ', $preview[0]);
            }
        }

        $this->assertSame($history, DB::table('permission_role')->whereIn('role_id', [$inactive->id, $archived->id])->count());
        $this->assertSame($visibilityHistory, DB::table('admin_sidebar_role_visibility')->count());
    }

    public function test_multi_role_historical_hides_do_not_revoke_active_route_but_current_hide_does_hide_only_the_link(): void
    {
        $active = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $inactive = $this->historicalRole('V2_P006_SAVED_INACTIVE', false, false, $active);
        $archived = $this->historicalRole('V2_P006_SAVED_ARCHIVED', true, true, $active);
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach([$active->id, $inactive->id, $archived->id]);

        foreach ([$inactive, $archived] as $role) {
            $this->saveHide($role);
        }

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->assertContains('catalog_management', $this->visibleKeys($actor->fresh()));
            $this->actingAs($actor->fresh())
                ->get(route('admin.catalog.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
        }

        // Only a current role's saved hide may suppress the Sidebar link.
        $this->saveHide($active);
        $this->assertNotContains('catalog_management', $this->visibleKeys($actor->fresh()));
        $this->actingAs($actor->fresh())->get(route('admin.catalog.index', [], false))->assertOk();

        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->get(route('admin.catalog.index', [], false))->assertForbidden();
        $this->assertCount(3, DB::table('admin_sidebar_role_visibility')->where('item_key', 'catalog_management')->get());
    }

    private function historicalRole(string $code, bool $active, bool $archived, Role $source): Role
    {
        $role = Role::query()->create([
            'code' => $code,
            'name' => $code,
            'scope' => 'global',
            'is_system' => false,
            'is_active' => $active,
            'archived_at' => $archived ? now() : null,
        ]);
        $role->permissions()->sync($source->permissions()->pluck('permissions.id')->all());

        return $role;
    }

    private function saveHide(Role $role): void
    {
        DB::table('admin_sidebar_role_visibility')->insert([
            'role_id' => $role->id,
            'item_key' => 'catalog_management',
            'is_visible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function visibleKeys(User $user): array
    {
        return collect(app(AdminNavigation::class)->groupsFor($user))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')
            ->values()
            ->all();
    }
}
