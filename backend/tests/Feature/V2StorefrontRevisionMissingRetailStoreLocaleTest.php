<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2StorefrontRevisionMissingRetailStoreLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authenticated_retail_revision_index_and_draft_require_store_with_saved_ar_en_locale_and_no_writes(): void
    {
        $storeId = $this->retailStore('V2-3881-RETAIL');
        $admin = $this->storeAdmin($storeId);
        Sanctum::actingAs($admin);

        foreach ([
            ['ar', 'en-US,en;q=0.9', 'يجب تحديد متجر التجزئة لإدارة نسخ واجهة المتجر.'],
            ['en', 'ar-KW,ar;q=0.9', 'Retail storefront revision requires a store.'],
            ['fr', 'ar-KW,ar;q=0.9,en;q=0.5', 'يجب تحديد متجر التجزئة لإدارة نسخ واجهة المتجر.'],
            ['fr', 'en-US,en;q=0.9,ar;q=0.5', 'Retail storefront revision requires a store.'],
        ] as [$savedLocale, $header, $expectedMessage]) {
            $admin->forceFill(['locale' => $savedLocale])->save();
            $counts = $this->stateCounts();

            $this->withHeader('Accept-Language', $header)
                ->getJson('/api/v1/admin/app-preview/storefront-revisions?channel=b2c')
                ->assertUnprocessable()
                ->assertJsonPath('message', $expectedMessage);

            $this->withHeader('Accept-Language', $header)
                ->postJson('/api/v1/admin/app-preview/storefront-revisions/draft', [
                    'channel' => 'b2c',
                ])
                ->assertUnprocessable()
                ->assertJsonPath('message', $expectedMessage);

            $this->assertSame($counts, $this->stateCounts());
            $this->assertDatabaseHas('user_store_roles', [
                'user_id' => $admin->getKey(),
                'store_id' => $storeId,
            ]);
            $this->assertDatabaseHas('users', [
                'id' => $admin->getKey(),
                'locale' => $savedLocale,
                'is_active' => true,
            ]);
        }
    }

    public function test_missing_retail_store_is_unauthorized_without_actor_and_foreign_store_is_forbidden(): void
    {
        $url = '/api/v1/admin/app-preview/storefront-revisions';
        $this->getJson($url.'?channel=b2c')->assertUnauthorized();
        $this->postJson($url.'/draft', ['channel' => 'b2c'])->assertUnauthorized();

        $ownStore = $this->retailStore('V2-3881-OWN');
        $foreignStore = $this->retailStore('V2-3881-FOREIGN');
        $admin = $this->storeAdmin($ownStore);
        Sanctum::actingAs($admin);
        $counts = $this->stateCounts();

        $this->getJson($url.'?channel=b2c&store_id='.$foreignStore)
            ->assertForbidden();
        $this->postJson($url.'/draft', [
            'channel' => 'b2c',
            'store_id' => $foreignStore,
        ])->assertForbidden();

        $this->assertSame($counts, $this->stateCounts());
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
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->getKey(),
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin;
    }

    /**
     * Error paths must not create sessions, revisions, business state or audit rows.
     *
     * @return array<string, int>
     */
    private function stateCounts(): array
    {
        $counts = [];

        foreach ([
            'storefront_revisions',
            'app_preview_sessions',
            'app_preview_invalidation_events',
            'storefront_settings',
            'storefront_sections',
            'audit_logs',
            'stores',
            'user_store_roles',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
