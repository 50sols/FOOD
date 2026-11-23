<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2LookupPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_page_validation_after_access_and_nested_store_guard_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);

        $url = route('admin.lookups.index');
        $initialLocale = app()->getLocale();

        try {
            foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $brandCount = Brand::query()->count();
                $unitCount = Unit::query()->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '?page[]=2',
                    '?page=invalid',
                    '?page=0',
                    '?page=-1',
                    '?page=1.5',
                    '?page=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($brandCount, Brand::query()->count());
                    $this->assertSame($unitCount, Unit::query()->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                $this->actingAs($admin->fresh())->getJson($url.'?store_id[]=2&page=invalid')
                    ->assertNotFound();

                $this->actingAs($roleless)->getJson($url.'?page=invalid')
                    ->assertForbidden();

                foreach (['', '?page=1', '?page=2', '?page='] as $valid) {
                    $this->actingAs($admin->fresh())->get($url.$valid)
                        ->assertOk()
                        ->assertSee('dir="'.$direction.'"', false);
                }
            }
        } finally {
            app()->setLocale($initialLocale);
        }
    }
}
