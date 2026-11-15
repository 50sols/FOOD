<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\RetailWholesaleAccountService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2RetailLinkedB2bAccountStatusLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_derived_wholesale_account_status_guard_uses_saved_arabic_and_english_without_writes(): void
    {
        foreach ([
            'ar' => 'حالة حساب الجملة المرتبط بمتجر تجزئة تُدار من خلال حالة متجر التجزئة.',
            'en' => 'Retail-linked Wholesale account status is controlled by the Retail store status.',
        ] as $locale => $message) {
            $admin = $this->admin('B2B_ADMIN', $locale);

            foreach ([true, false] as $activeStore) {
                $account = $this->retailLinkedAccount('STATUS-'.strtoupper($locale).'-'.($activeStore ? 'ON' : 'OFF'), $activeStore);
                $initialStatus = $activeStore ? 'active' : 'suspended';
                $attemptedStatus = $activeStore ? 'suspended' : 'active';
                $storeId = (int) DB::table('retail_wholesale_accounts')
                    ->where('b2b_customer_id', $account->b2b_customer_id)
                    ->value('retail_store_id');
                $before = $this->watchedTableCounts();

                $this->actingAs($admin, 'sanctum')
                    ->withHeaders(['Accept-Language' => $locale === 'ar' ? 'en' : 'ar'])
                    ->patchJson('/api/v1/admin/b2b/accounts/'.$account->getKey().'/status', [
                        'status' => $attemptedStatus,
                    ])
                    ->assertStatus(422)
                    ->assertJsonPath('message', $message);

                $this->assertSame($initialStatus, $account->fresh()->status);
                $this->assertDatabaseHas('stores', [
                    'id' => $storeId,
                    'is_active' => $activeStore,
                ]);
                $this->assertDatabaseHas('retail_wholesale_accounts', [
                    'retail_store_id' => $storeId,
                    'b2b_customer_id' => $account->b2b_customer_id,
                ]);
                $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true, 'locale' => $locale]);
                $this->assertSame($before, $this->watchedTableCounts());
            }
        }
    }

    public function test_retail_status_override_requires_authentication_and_wholesale_admin_permission(): void
    {
        $account = $this->retailLinkedAccount('STATUS-AUTH', true);
        $url = '/api/v1/admin/b2b/accounts/'.$account->getKey().'/status';
        $before = $this->watchedTableCounts();

        $this->patchJson($url, ['status' => 'suspended'])->assertUnauthorized();

        $finance = $this->admin('FINANCE', 'en');
        $this->actingAs($finance, 'sanctum')
            ->patchJson($url, ['status' => 'suspended'])
            ->assertForbidden();

        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame($before['b2b_accounts'], DB::table('b2b_accounts')->count());
        $this->assertSame($before['audit_logs'], DB::table('audit_logs')->count());
        foreach (['orders', 'invoices', 'payments', 'inventories', 'stock_movements'] as $table) {
            $this->assertSame($before[$table], DB::table($table)->count());
        }
    }

    private function admin(string $roleCode, string $locale): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailLinkedAccount(string $code, bool $activeStore): B2bAccount
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => 'Retail '.$code,
            'is_active' => $activeStore,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = app(RetailWholesaleAccountService::class)
            ->ensureForStore(Store::query()->findOrFail($storeId));

        return B2bAccount::query()->where('b2b_customer_id', $customer->getKey())->firstOrFail();
    }

    /**
     * A rejected command must not create ledger/stock/business/audit records.
     *
     * @return array<string, int>
     */
    private function watchedTableCounts(): array
    {
        $tables = [
            'b2b_accounts',
            'b2b_customers',
            'retail_wholesale_accounts',
            'stores',
            'users',
            'orders',
            'invoices',
            'payments',
            'inventories',
            'stock_movements',
            'audit_logs',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
