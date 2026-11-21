<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        $this->assertGreaterThan(0, $tierId);
        Storage::fake('public');

        // Start with a real, successfully provisioned Retail store and linked
        // Wholesale owner. An invalid reassignment must preserve this link.
        $this->actingAs($super)->post(route('admin.retail-stores.store'), [
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-3735-STORE',
            'name' => 'Original Retail Store Name',
            'logo' => UploadedFile::fake()->image('v2-3735-store.png', 256, 256),
            'price_tier_id' => $tierId,
            'is_active' => '1',
            'manager_mode' => 'new',
            'manager_name' => 'Existing Active Store Owner',
            'manager_email' => 'v2-3735-original-owner@example.test',
            'manager_password' => 'password123',
        ])->assertRedirect(route('admin.retail-stores.index'));

        $storeId = (int) DB::table('stores')->where('code', 'V2-3735-STORE')->value('id');
        $this->assertGreaterThan(0, $storeId);
        $linked = DB::table('retail_wholesale_accounts')->where('retail_store_id', $storeId)->first();
        $this->assertNotNull($linked);
        $originalOwnerId = (int) $linked->owner_user_id;
        $linkedCustomerId = (int) $linked->b2b_customer_id;
        $this->assertGreaterThan(0, $originalOwnerId);
        $this->assertGreaterThan(0, $linkedCustomerId);

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
            $this->assertDatabaseHas('retail_wholesale_accounts', [
                'retail_store_id' => $storeId,
                'owner_user_id' => $originalOwnerId,
                'b2b_customer_id' => $linkedCustomerId,
            ]);
            $this->assertDatabaseHas('b2b_accounts', [
                'b2b_customer_id' => $linkedCustomerId,
                'status' => 'active',
            ]);
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
        $this->assertDatabaseHas('retail_wholesale_accounts', [
            'retail_store_id' => $storeId,
            'owner_user_id' => $originalOwnerId,
            'b2b_customer_id' => $linkedCustomerId,
        ]);
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());
    }
}
