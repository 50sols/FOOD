<?php

namespace Tests\Feature;

use App\Jobs\DispatchPushNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\B2cCustomerService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class V2OrderOperationsStatusGuardLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_localized_status_guards_reject_before_any_write(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        Queue::fake();
        $store = $this->store('V2-3719-RETAIL');
        $otherStore = $this->store('V2-3719-FOREIGN');
        $customer = app(B2cCustomerService::class)->create($store, ['name' => 'Status Guard Customer']);
        $foreignCustomer = app(B2cCustomerService::class)->create($otherStore, ['name' => 'Other Guard Customer']);
        $pending = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'V2-3719-PENDING', 'pending');
        $delivered = $this->order($store, (int) $customer->legacy_customer_id, (int) $customer->id, 'V2-3719-DELIVERED', 'delivered');
        $foreign = $this->order($otherStore, (int) $foreignCustomer->legacy_customer_id, (int) $foreignCustomer->id, 'V2-3719-FOREIGN', 'pending');

        $admin = User::query()->create([
            'name' => 'Status Guard Admin',
            'email' => 'v2-3719-admin@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $store,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $history = DB::table('order_status_history')->count();
        $notifications = DB::table('notifications')->count();
        $audit = DB::table('audit_logs')->count();

        foreach ([
            'ar' => [
                'cancel' => 'لا يمكن إلغاء الطلبات التي تم تسليمها.',
                'automatic' => 'تُنفَّذ خطوات تأكيد الطلب وتجهيزه تلقائيًا عند إسناد جهة التنفيذ.',
            ],
            'en' => [
                'cancel' => 'Delivered orders cannot be cancelled.',
                'automatic' => 'Confirmation and preparation steps are automatic when fulfillment is assigned.',
            ],
        ] as $locale => $messages) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin)
                ->postJson("/admin/operations/orders/{$delivered}/status", ['status' => 'cancelled'])
                ->assertStatus(409)->assertJsonPath('message', $messages['cancel']);
            $this->actingAs($admin)
                ->postJson("/admin/operations/orders/{$pending}/status", ['status' => 'ready'])
                ->assertStatus(409)->assertJsonPath('message', $messages['automatic']);
            $this->actingAs($admin)
                ->postJson("/admin/operations/orders/{$foreign}/status", ['status' => 'ready'])
                ->assertNotFound();

            $this->assertDatabaseHas('orders', ['id' => $pending, 'status' => 'pending']);
            $this->assertDatabaseHas('orders', ['id' => $delivered, 'status' => 'delivered']);
            $this->assertDatabaseHas('orders', ['id' => $foreign, 'status' => 'pending']);
            $this->assertSame($history, DB::table('order_status_history')->count());
            $this->assertSame($notifications, DB::table('notifications')->count());
            $this->assertSame($audit, DB::table('audit_logs')->count());
            Queue::assertNotPushed(DispatchPushNotification::class);
        }

        $roleless = User::query()->create([
            'name' => 'Roleless Status Actor',
            'email' => 'v2-3719-roleless@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)
            ->postJson("/admin/operations/orders/{$pending}/status", ['status' => 'ready'])
            ->assertForbidden();
        $this->assertSame($history, DB::table('order_status_history')->count());
        Queue::assertNotPushed(DispatchPushNotification::class);
    }

    private function store(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function order(int $store, int $legacy, int $customer, string $number, string $status): int
    {
        return (int) DB::table('orders')->insertGetId([
            'store_id' => $store,
            'customer_id' => $legacy,
            'b2c_customer_id' => $customer,
            'order_number' => $number,
            'channel' => 'b2c',
            'status' => $status,
            'currency' => 'KWD',
            'subtotal' => 10,
            'discount_total' => 0,
            'delivery_total' => 0,
            'grand_total' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
