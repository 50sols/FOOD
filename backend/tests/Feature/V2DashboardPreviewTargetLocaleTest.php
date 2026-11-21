<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2DashboardPreviewTargetLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_retail_missing_store_422_is_localized_without_preview_writes_or_scope_bypass(): void
    {
        $ownStoreId = $this->retailStore('V2-3748-TARGET-OWN');
        $foreignStoreId = $this->retailStore('V2-3748-TARGET-FOREIGN');
        $admin = $this->storeAdmin($ownStoreId);
        $before = $this->previewWriteCounts();

        foreach ([
            'ar' => 'تتطلب معاينة أهداف متجر التجزئة تحديد متجر.',
            'en' => 'Retail preview requires a store.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->getJson(route('admin.app-preview.targets', [
                    'target_type' => 'customer',
                    'channel' => 'b2c',
                ]))
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertSame($before, $this->previewWriteCounts());
        }

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $foreignStoreId,
            ]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $ownStoreId,
            ]))
            ->assertOk()
            ->assertJsonStructure(['data']);

        $roleless = User::query()->create([
            'name' => 'Roleless Preview Target',
            'email' => 'v2-3748-preview-roleless@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $this->actingAs($roleless)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'customer',
                'channel' => 'b2c',
                'store_id' => $ownStoreId,
            ]))
            ->assertForbidden();

        $this->assertSame($before, $this->previewWriteCounts());
    }

    public function test_wholesale_noncanonical_store_422_is_localized_and_valid_principal_still_works(): void
    {
        $principalStoreId = app(WholesalePrincipal::class)->storeId();
        $admin = User::query()->create([
            'name' => 'Wholesale Preview Target Admin',
            'email' => 'v2-3748-wholesale-admin@example.test',
            'password' => 'password123',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $before = $this->previewWriteCounts();

        foreach ([
            'ar' => 'تستخدم معاينة أهداف الجملة المتجر الرئيسي المعتمد فقط.',
            'en' => 'Wholesale preview uses the canonical principal store.',
        ] as $locale => $message) {
            $admin->forceFill(['locale' => $locale])->save();

            $this->actingAs($admin)
                ->getJson(route('admin.app-preview.targets', [
                    'target_type' => 'driver',
                    'channel' => 'b2b',
                    'store_id' => $principalStoreId + 100000,
                ]))
                ->assertUnprocessable()
                ->assertJsonPath('message', $message);

            $this->assertSame($before, $this->previewWriteCounts());
        }

        // The B2B driver target list remains empty following the VAN-only cutover.
        $this->actingAs($admin)
            ->getJson(route('admin.app-preview.targets', [
                'target_type' => 'driver',
                'channel' => 'b2b',
                'store_id' => $principalStoreId,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame($before, $this->previewWriteCounts());
    }

    /** @return array{sessions:int,events:int,audit:int} */
    private function previewWriteCounts(): array
    {
        return [
            'sessions' => DB::table('app_preview_sessions')->count(),
            'events' => DB::table('app_preview_invalidation_events')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];
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

    private function storeAdmin(int $storeId): User
    {
        $admin = User::query()->create([
            'name' => 'Retail Preview Target Admin',
            'email' => 'v2-3748-retail-admin@example.test',
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

        return $admin;
    }
}
