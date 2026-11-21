<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2DriverWholesaleAssignmentLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_wholesale_driver_assignment_rejects_ar_en_before_any_write_and_denies_unscoped_actor(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $wholesaleStoreId = app(WholesalePrincipal::class)->storeId();
        $customerUser = User::factory()->create(['is_active' => true]);
        $customer = Customer::query()->create([
            'user_id' => $customerUser->id,
            'type' => 'b2b',
            'name' => 'Wholesale dispatch customer',
            'email' => $customerUser->email,
        ]);
        $order = Order::query()->create([
            'store_id' => $wholesaleStoreId,
            'customer_id' => $customer->id,
            'order_number' => 'V2-3720-WHOLESALE',
            'channel' => 'b2b',
            'status' => 'pending',
            'currency' => 'KWD',
            'subtotal' => 1,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 1,
        ]);

        $retailStore = Store::query()->create([
            'store_type_id' => StoreType::query()->where('code', 'B2C')->firstOrFail()->id,
            'code' => 'V2-3720-RETAIL',
            'name' => 'V2 Driver Dispatch Retail Fixture',
            'is_active' => true,
        ]);
        $driverUser = User::factory()->create(['is_active' => true]);
        $driver = Driver::query()->create([
            'user_id' => $driverUser->id,
            'store_id' => $retailStore->id,
            'driver_type' => 'b2c',
            'is_available' => true,
            'is_active' => true,
        ]);

        $admin = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $assignmentCount = DB::table('driver_assignments')->count();
        $notificationCount = DB::table('notifications')->count();
        $auditCount = DB::table('audit_logs')->count();

        foreach ([
            'ar' => 'يجب تنفيذ طلبات الجملة بواسطة الفان فقط.',
            'en' => 'Wholesale orders must be fulfilled by a Van.',
        ] as $locale => $expectedMessage) {
            $admin->forceFill(['locale' => $locale])->save();
            Sanctum::actingAs($admin->fresh());

            $this->postJson('/api/v1/admin/deliveries/assign', [
                'driver_id' => $driver->id,
                'order_id' => $order->id,
                'replace_existing' => true,
                'customer_service_override' => true,
            ])->assertConflict()->assertJsonPath('message', $expectedMessage);

            $this->assertSame($assignmentCount, DB::table('driver_assignments')->count());
            $this->assertSame($notificationCount, DB::table('notifications')->count());
            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'channel' => 'b2b',
                'status' => 'pending',
            ]);
        }

        $unscoped = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        Sanctum::actingAs($unscoped);
        $this->postJson('/api/v1/admin/deliveries/assign', [
            'driver_id' => $driver->id,
            'order_id' => $order->id,
        ])->assertNotFound();

        $this->assertSame($assignmentCount, DB::table('driver_assignments')->count());
        $this->assertSame($notificationCount, DB::table('notifications')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }
}
