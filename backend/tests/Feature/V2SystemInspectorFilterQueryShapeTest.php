<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SystemInspectorFilterQueryShapeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_platform_admin_keeps_normal_and_blank_scalar_inspector_filters_in_both_locales(): void
    {
        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $admin = $this->userWithRole('SUPER_ADMIN', $locale);
            app()->setLocale($locale);

            foreach ([
                route('admin.inspector.index'),
                route('admin.inspector.index').'?source=javascript&severity=error&q=missing'
                    .'&app_version=1.2.3&app_build=42&channel=b2b'
                    .'&store_id=0&restaurant_store_id=0&verify_source=0',
                route('admin.inspector.index').'?source=&severity=&q=&app_version='
                    .'&app_build=&channel=&store_id=&restaurant_store_id=&verify_source=',
            ] as $url) {
                $this->actingAs($admin)->get($url)
                    ->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'">', false);
            }
        }
    }

    public function test_nested_inspector_selectors_are_not_silently_cast_or_rendered_in_arabic_and_english(): void
    {
        $filters = [
            'source', 'severity', 'q', 'app_version', 'app_build', 'channel',
            'store_id', 'restaurant_store_id', 'verify_source',
        ];

        // 404s are intentionally recorded by SystemInspectorRecorder as sanitized
        // diagnostic events; only business/audit source records must stay unchanged.
        $storesBefore = DB::table('stores')->count();
        $productsBefore = DB::table('products')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach (['ar', 'en'] as $locale) {
            $admin = $this->userWithRole('SUPER_ADMIN', $locale);
            app()->setLocale($locale);

            foreach ($filters as $filter) {
                $this->actingAs($admin)
                    ->get(route('admin.inspector.index').'?'.$filter.'[]=unexpected')
                    ->assertNotFound();
            }

            foreach ([
                '?source[nested][0]=javascript',
                '?restaurant_store_id[0][id]=1',
            ] as $nested) {
                $this->actingAs($admin)
                    ->get(route('admin.inspector.index').$nested)
                    ->assertNotFound();
            }
        }

        $this->assertSame($storesBefore, DB::table('stores')->count());
        $this->assertSame($productsBefore, DB::table('products')->count());
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }

    public function test_non_platform_actor_stays_forbidden_before_valid_or_nested_selector_validation(): void
    {
        $actor = $this->userWithRole('B2B_ADMIN', 'en');

        foreach ([
            route('admin.inspector.index'),
            route('admin.inspector.index').'?source=javascript',
            route('admin.inspector.index').'?source[]=javascript',
            route('admin.inspector.index').'?store_id[0]=1',
        ] as $url) {
            $this->actingAs($actor)->get($url)->assertForbidden();
        }
    }

    private function userWithRole(string $role, string $locale): User
    {
        $user = User::query()->create([
            'name' => $role.' '.$locale,
            'email' => strtolower($role).'-'.$locale.'-inspector-query-shape@example.test',
            'password' => 'password',
            'locale' => $locale,
            'is_active' => true,
        ]);

        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }
}
