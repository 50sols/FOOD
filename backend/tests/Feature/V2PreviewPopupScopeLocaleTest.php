<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2PreviewPopupScopeLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_preview_popup_channel_mismatch_is_localized_without_writes_and_store_scope_remains_closed(): void
    {
        $storeId = $this->retailStore('PREVIEW-POPUP-LOCALE');
        $otherStoreId = $this->retailStore('PREVIEW-POPUP-FOREIGN');
        $admin = User::query()->create([
            'name' => 'Preview Popup Store Admin',
            'email' => 'preview-popup-admin@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $customer = User::query()->create([
            'name' => 'Preview Popup Customer',
            'email' => 'preview-popup-customer@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('b2c_customers')->insert([
            'user_id' => $customer->id,
            'store_id' => $storeId,
            'name' => $customer->name,
            'email' => $customer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $previewToken = (string) $this->postJson('/api/v1/admin/app-preview/sessions', [
            'target_user_id' => $customer->id,
            'target_type' => 'customer',
            'channel' => 'b2c',
            'store_id' => $storeId,
        ])->assertCreated()->json('preview_token');

        $this->app['auth']->forgetGuards();
        $tableCounts = [];
        foreach (['notification_campaigns', 'notification_campaign_popup_views', 'notifications', 'audit_logs', 'app_preview_sessions'] as $table) {
            $tableCounts[$table] = DB::table($table)->count();
        }

        foreach ([
            ['ar', 'en-US', 'ar'],
            ['en', 'ar-KW', 'en'],
            [null, 'ar-KW', 'ar'],
            [null, 'en-US', 'en'],
        ] as [$requestedLocale, $headerLocale, $expectedLocale]) {
            $params = [
                'channel' => 'b2b',
                'store_id' => $storeId,
                'install_id' => 'preview-popup-install',
            ];
            if ($requestedLocale !== null) {
                $params['locale'] = $requestedLocale;
            }

            $this->withHeaders([
                'X-Foodex-Preview-Token' => $previewToken,
                'Accept-Language' => $headerLocale,
            ])->getJson('/api/v1/app-preview/customer/notification-campaign-popups?'.http_build_query($params))
                ->assertNotFound()
                ->assertJsonPath(
                    'message',
                    trans('popup_preview_scope.context_mismatch', [], $expectedLocale),
                );

            foreach ($tableCounts as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), $table.' changed on denied preview');
            }
        }

        $this->withHeaders([
            'X-Foodex-Preview-Token' => $previewToken,
            'Accept-Language' => 'ar-KW',
        ])->getJson('/api/v1/app-preview/customer/notification-campaign-popups?'.http_build_query([
            'channel' => 'b2c',
            'store_id' => $storeId,
            'locale' => 'ar',
            'install_id' => 'preview-popup-install',
        ]))->assertOk()->assertJsonPath('data', []);

        $this->withHeader('X-Foodex-Preview-Token', $previewToken)
            ->getJson('/api/v1/app-preview/customer/notification-campaign-popups?'.http_build_query([
                'channel' => 'b2c',
                'store_id' => $otherStoreId,
                'locale' => 'ar',
                'install_id' => 'preview-popup-install',
            ]))->assertNotFound();

        foreach ($tableCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed after preview reads');
        }
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'advertising_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
