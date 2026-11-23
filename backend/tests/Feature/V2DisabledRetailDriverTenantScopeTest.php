<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Store;
use App\Models\User;
use App\Services\DriverTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P031: a disabled identity never resolves an active Retail Driver tenant. */
final class V2DisabledRetailDriverTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_driver_scope_requires_active_user_in_addition_to_active_profile_and_store(): void
    {
        $store = Store::query()->create([
            'store_type_id' => (int) DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'P031-4314-ACTIVE',
            'name' => 'Retail Driver Scope',
            'is_active' => true,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $driver = Driver::query()->create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'driver_type' => 'b2c',
            'is_active' => true,
        ]);

        $scope = app(DriverTenantScope::class);
        $this->assertSame($driver->id, $scope->eligibleRetailDriver($user)?->id);

        // User offboarding is not the same as disabling the driver profile.
        // The still-active driver/store records must not reinstate the user.
        $user->forceFill(['is_active' => false])->save();
        $this->assertNull($scope->eligibleRetailDriver($user->fresh()));
        $this->assertNull($scope->eligibleRetailDriver($user->fresh(), true));
        $this->assertTrue((bool) $driver->fresh()->is_active);
        $this->assertTrue((bool) $store->fresh()->is_active);

        $user->forceFill(['is_active' => true])->save();
        $this->assertSame($driver->id, $scope->eligibleRetailDriver($user->fresh())?->id);

        $store->forceFill(['is_active' => false])->save();
        $this->assertNull($scope->eligibleRetailDriver($user->fresh()));
    }
}
