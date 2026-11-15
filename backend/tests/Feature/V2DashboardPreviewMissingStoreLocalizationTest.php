<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2DashboardPreviewMissingStoreLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_missing_retail_preview_store_returns_localized_422_without_writes(): void
    {
        $storeId = $this->retailStore('PREVIEW-STREAM-AR-EN');
        $admin = User::query()->create([
            'name' => 'Preview store administrator',
            'email' => 'preview-stream-locale@example.test',
            'password' => 'unused-password',
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

        $url = route('admin.app-preview.events', ['channel' => 'b2c']);
        $auditCount = DB::table('audit_logs')->count();
        $sessionCount = DB::table('app_preview_sessions')->count();
        $eventCount = DB::table('app_preview_invalidation_events')->count();

        foreach ([
            'ar' => 'يجب تحديد المتجر لعرض أحداث معاينة التجزئة.',
            'en' => 'Retail preview event stream requires a store.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->getJson($url)
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertSame($auditCount, DB::table('audit_logs')->count());
            $this->assertSame($sessionCount, DB::table('app_preview_sessions')->count());
            $this->assertSame($eventCount, DB::table('app_preview_invalidation_events')->count());
        }

        $otherStore = $this->retailStore('PREVIEW-STREAM-FOREIGN');
        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.events', [
                'channel' => 'b2c',
                'store_id' => $otherStore,
            ]))
            ->assertForbidden();

        $this->assertSame($sessionCount, DB::table('app_preview_sessions')->count());
        $this->assertSame($eventCount, DB::table('app_preview_invalidation_events')->count());
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
