<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StorefrontRevision;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBusinessManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_super_admin_can_manage_operational_data_from_web_ui(): void
    {
        $user = $this->superAdmin();
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'OPS',
            'name' => 'Operations Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'Operations Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'OPS-1',
            'name' => 'Operations Product',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->get('/admin/business?tab=inventory')
            ->assertRedirect(route('admin.index'));

        $this->actingAs($user)->post('/admin/business/warehouses', [
            'store_id' => $storeId,
            'code' => 'OPS-WH',
            'name' => 'Main Warehouse',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $warehouseId = (int) DB::table('warehouses')->where('code', 'OPS-WH')->value('id');

        $this->actingAs($user)->post('/admin/business/inventory', [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'quantity' => 10,
        ])->assertSessionHasNoErrors();

        $inventoryId = (int) DB::table('inventories')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('id');

        $this->actingAs($user)->patch('/admin/business/inventory/'.$inventoryId, [
            'quantity_delta' => 5,
            'reason' => 'Opening stock correction',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventories', ['id' => $inventoryId, 'quantity' => 15]);

        $this->actingAs($user)->post('/admin/business/customers?store_id='.$storeId.'&support_access=1', [
            'type' => 'b2c',
            'store_id' => $storeId,
            'name' => 'Customer One',
            'phone' => '5550001',
            'email' => 'customer@example.test',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('b2c_customers', [
            'store_id' => $storeId,
            'name' => 'Customer One',
        ]);

        $this->actingAs($user)->post('/admin/business/promotions', [
            'store_id' => $storeId,
            'name' => 'Launch Offer',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('promotions', ['name' => 'Launch Offer']);

        $this->actingAs($user)->post('/admin/business/banners', [
            'store_id' => $storeId,
            'support_access' => 1,
            'title' => 'Launch Banner',
            'banner_image' => UploadedFile::fake()->image('launch.jpg', 1200, 420),
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('banners', ['title' => 'Launch Banner']);

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();
        $this->assertNotNull(collect($draft->payload['banners'])->firstWhere('title', 'Launch Banner'));

        $this->actingAs($user)->post('/admin/business/drivers', [
            'store_id' => $storeId,
            'name' => 'Driver One',
            'email' => 'driver-one@example.test',
            'password' => 'StrongPass123!',
            'driver_type' => 'b2c',
            'is_available' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'driver-one@example.test']);
        $this->assertDatabaseHas('drivers', ['store_id' => $storeId, 'driver_type' => 'b2c', 'is_active' => 1]);

        foreach ([
            '/admin/b2c/inventory' => 'المخزون',
            '/admin/b2c/customers' => 'العملاء',
            '/admin/b2c/promotions' => 'العروض',
            '/admin/b2c/content' => 'المحتوى والبنرات',
            '/admin/b2c/drivers' => 'السائقون والتوصيل',
        ] as $uri => $label) {
            $this->actingAs($user)
                ->get($uri.'?store_id='.$storeId.'&support_access=1')
                ->assertOk()
                ->assertSee($label);
        }
    }

    public function test_driver_cannot_open_business_management_center(): void
    {
        $user = User::query()->create([
            'name' => 'Driver',
            'email' => 'driver-no-admin@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2C_DRIVER')->firstOrFail());

        $this->actingAs($user)->get('/admin/business')->assertForbidden();
    }

    public function test_wrong_wholesale_principal_store_is_bilingual_and_rejected_before_driver_writes(): void
    {
        $admin = $this->superAdmin();
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->assertGreaterThan(0, $typeId);

        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'DRIVER-PRINCIPAL-RETAIL',
            'name' => 'Driver Principal Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admin/business/drivers', [
            'store_id' => $retailStoreId,
            'name' => 'Existing Retail Driver',
            'email' => 'existing-retail-driver@example.test',
            'password' => 'StrongPass123!',
            'driver_type' => 'b2c',
            'is_available' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $driver = DB::table('drivers')->where('store_id', $retailStoreId)->first();
        $this->assertNotNull($driver);
        $usersBefore = DB::table('users')->count();
        $driversBefore = DB::table('drivers')->count();
        $roleLinksBefore = DB::table('role_user')->count();
        $auditBefore = DB::table('audit_logs')->count();

        foreach ([
            'ar' => 'يجب أن يتبع سائق الجملة متجر الجملة الرئيسي.',
            'en' => 'Wholesale drivers must belong to the principal Wholesale store.',
        ] as $locale => $error) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)->postJson('/admin/business/drivers', [
                'store_id' => $retailStoreId,
                'name' => 'Invalid Wholesale Driver',
                'email' => 'invalid-wholesale-'.$locale.'@example.test',
                'password' => 'StrongPass123!',
                'driver_type' => 'b2b',
                'is_available' => 1,
                'is_active' => 1,
            ])->assertUnprocessable()->assertJsonPath('message', $error);

            $this->actingAs($admin)
                ->patchJson('/admin/business/drivers/'.$driver->id, [
                    'store_id' => $retailStoreId,
                    'driver_type' => 'b2b',
                    'is_available' => 1,
                    'is_active' => 1,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('message', $error);

            $this->assertSame($usersBefore, DB::table('users')->count());
            $this->assertSame($driversBefore, DB::table('drivers')->count());
            $this->assertSame($roleLinksBefore, DB::table('role_user')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertDatabaseHas('drivers', [
                'id' => $driver->id,
                'user_id' => $driver->user_id,
                'store_id' => $retailStoreId,
                'driver_type' => 'b2c',
            ]);
        }

        $unprivileged = User::query()->create([
            'name' => 'Unprivileged Driver',
            'email' => 'unprivileged-driver@example.test',
            'password' => 'unused-password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $unprivileged->roles()->attach(
            Role::query()->where('code', 'B2C_DRIVER')->firstOrFail(),
        );
        $this->actingAs($unprivileged)->postJson('/admin/business/drivers', [
            'store_id' => $retailStoreId,
            'name' => 'Forbidden Wholesale Driver',
            'email' => 'forbidden-wholesale@example.test',
            'password' => 'StrongPass123!',
            'driver_type' => 'b2b',
        ])->assertForbidden();

        $this->assertSame($driversBefore, DB::table('drivers')->count());
        $this->assertDatabaseMissing('users', ['email' => 'forbidden-wholesale@example.test']);
    }

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-business@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        return $user;
    }
}
