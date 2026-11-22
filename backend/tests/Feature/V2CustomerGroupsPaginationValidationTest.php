<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2CustomerGroupsPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_group_pages_fail_closed_after_store_scope_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $storeId = app(WholesalePrincipal::class)->storeId();

        $url = route('admin.b2b.customer-groups.index', ['store_id' => $storeId]);
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $groupCount = DB::table('commercial_customer_groups')->count();
                $memberCount = DB::table('commercial_customer_group_members')->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '&page[]=2',
                    '&page=abc',
                    '&page=0',
                    '&page=-2',
                    '&page=1.5',
                    '&page=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($groupCount, DB::table('commercial_customer_groups')->count());
                    $this->assertSame($memberCount, DB::table('commercial_customer_group_members')->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                $this->actingAs($admin->fresh())->getJson($url.'&q[]=bad&page=abc')
                    ->assertNotFound();
                $this->actingAs($roleless)->getJson($url.'&page=abc')
                    ->assertForbidden();

                foreach (['', '&page=1', '&page=2', '&page='] as $valid) {
                    $this->actingAs($admin->fresh())->get($url.$valid)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
