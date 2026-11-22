<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailDriverOrdersPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retail_driver_orders_reject_invalid_page_after_driver_scope_ar_en(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'V2-DRIVER-PAGE',
            'name' => 'Retail Driver Page',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverUser = User::factory()->create(['is_active' => true]);
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $storeId,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());
        $roleless = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $url = route('admin.drivers.orders', ['driver' => $driver->id, 'channel' => 'b2c']);
        $wrongChannel = route('admin.drivers.orders', ['driver' => $driver->id, 'channel' => 'b2b']);
        $originalLocale = app()->getLocale();

        try {
            foreach (['ar' => 'نوع النشاط', 'en' => 'Business Type'] as $locale => $label) {
                $admin->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                $assignmentCount = DB::table('driver_assignments')->count();
                $auditCount = DB::table('audit_logs')->count();

                foreach ([
                    '&page[]=2',
                    '&page=invalid',
                    '&page=0',
                    '&page=-1',
                    '&page=1.5',
                    '&page=999999999999999999999999',
                ] as $invalid) {
                    $this->actingAs($admin->fresh())->getJson($url.$invalid)
                        ->assertUnprocessable();
                    $this->assertSame($assignmentCount, DB::table('driver_assignments')->count());
                    $this->assertSame($auditCount, DB::table('audit_logs')->count());
                }

                $this->actingAs($admin->fresh())->getJson($wrongChannel.'&page=invalid')
                    ->assertNotFound();
                $this->actingAs($roleless)->getJson($url.'&page=invalid')
                    ->assertForbidden();

                foreach (['', '&page=1', '&page=2', '&page='] as $valid) {
                    $this->actingAs($admin->fresh())->get($url.$valid)
                        ->assertOk()
                        ->assertSee($label);
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }
}
