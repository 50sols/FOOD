<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2RetailPrimaryOwnerInactiveLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_inactive_retail_primary_owner_is_rejected_in_actor_locale_without_store_or_ledger_writes(): void
    {
        $super = User::query()->create([
            'name' => 'Retail Ownership Super Admin',
            'email' => 'v2-3735-admin@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $super->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $inactive = User::query()->create([
            'name' => 'Inactive Retail Owner',
            'email' => 'v2-3735-inactive@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => false,
        ]);

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-3735-STORE',
            'name' => 'Original Retail Store Name',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $this->assertGreaterThan(0, $tierId);

        $before = [
            'stores' => DB::table('stores')->count(),
            'links' => DB::table('retail_wholesale_accounts')->count(),
            'account' => DB::table('b2b_accounts')->count(),
            'audit' => DB::table('audit_logs')->count(),
            'user_store_roles' => DB::table('user_store_roles')->count(),
        ];
        $payload = [
            'code' => 'V2-3735-STORE-EDITED',
            'name' => 'Unauthorized Retail Store Rename',
            'price_tier_id' => $tierId,
            'primary_owner_user_id' => $inactive->id,
            'is_active' => '0',
            'advertising_enabled' => '1',
        ];
        $url = route('admin.retail-stores.update', $storeId);

        foreach ([
            'ar' => 'يجب أن يكون المالك الرئيسي مستخدمًا نشطًا.',
            'en' => 'Primary owner must be an active user.',
        ] as $locale => $message) {
            $super->forceFill(['locale' => $locale])->save();
            $this->actingAs($super)
                ->patchJson($url, $payload)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertDatabaseHas('stores', [
                'id' => $storeId,
                'code' => 'V2-3735-STORE',
                'name' => 'Original Retail Store Name',
                'is_active' => true,
            ]);
            $this->assertDatabaseHas('users', ['id' => $inactive->id, 'is_active' => false]);
            $this->assertSame($before['stores'], DB::table('stores')->count());
            $this->assertSame($before['links'], DB::table('retail_wholesale_accounts')->count());
            $this->assertSame($before['account'], DB::table('b2b_accounts')->count());
            $this->assertSame($before['audit'], DB::table('audit_logs')->count());
            $this->assertSame($before['user_store_roles'], DB::table('user_store_roles')->count());
        }

        $roleless = User::query()->create([
            'name' => 'No Store Management Role',
            'email' => 'v2-3735-roleless@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)->patchJson($url, $payload)->assertForbidden();
        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'code' => 'V2-3735-STORE',
            'name' => 'Original Retail Store Name',
        ]);
        $this->assertSame($before['links'], DB::table('retail_wholesale_accounts')->count());
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());
    }
}
