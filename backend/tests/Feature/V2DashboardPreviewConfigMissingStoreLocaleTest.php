<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2DashboardPreviewConfigMissingStoreLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_guest_configuration_missing_store_is_localized_without_access_or_writes(): void
    {
        $storeId = $this->retailStore('V2-3738-OWN');
        $admin = User::query()->create([
            'name' => 'Retail Preview Configuration Admin',
            'email' => 'v2-3738-retail-admin@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $missingStoreUrl = route('admin.app-preview.storefront-configuration', [
            'channel' => 'b2c',
            'mode' => 'draft',
        ]);
        $before = [
            'sessions' => DB::table('app_preview_sessions')->count(),
            'events' => DB::table('app_preview_invalidation_events')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach ([
            'ar' => 'تتطلب معاينة ضيف متجر التجزئة تحديد متجر.',
            'en' => 'Retail Guest preview configuration requires a store.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->getJson($missingStoreUrl)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertSame($before['sessions'], DB::table('app_preview_sessions')->count());
            $this->assertSame($before['events'], DB::table('app_preview_invalidation_events')->count());
            $this->assertSame($before['audit'], DB::table('audit_logs')->count());
        }

        $foreignStoreId = $this->retailStore('V2-3738-FOREIGN');
        $this->actingAs($admin)->getJson(route('admin.app-preview.storefront-configuration', [
            'channel' => 'b2c',
            'mode' => 'draft',
            'store_id' => $foreignStoreId,
        ]))->assertForbidden();

        // The original, authorized read-only Guest preview is still usable.
        $this->actingAs($admin)->getJson(route('admin.app-preview.storefront-configuration', [
            'channel' => 'b2c',
            'mode' => 'draft',
            'store_id' => $storeId,
        ]))->assertOk();

        $this->assertSame($before['sessions'], DB::table('app_preview_sessions')->count());
        $this->assertSame($before['events'], DB::table('app_preview_invalidation_events')->count());

        $roleless = User::query()->create([
            'name' => 'Unauthorized Preview User',
            'email' => 'v2-3738-roleless@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)->getJson($missingStoreUrl)->assertForbidden();
        $this->assertSame($before['sessions'], DB::table('app_preview_sessions')->count());
        $this->assertSame($before['events'], DB::table('app_preview_invalidation_events')->count());
    }

    private function retailStore(string $code): int
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
}
