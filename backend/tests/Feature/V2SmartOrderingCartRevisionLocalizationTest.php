<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class V2SmartOrderingCartRevisionLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private string $branchPublicId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);

        $storeTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $storeTypeId,
            'code' => 'V2-SMART-REVISION-LOCALE',
            'name' => 'V2 Smart Ordering Revision Locales',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        $this->siteId = (int) DB::table('smart_ordering_sites')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'v2-cart-revision-localization',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('smart_ordering_entitlements')->insert([
            'site_id' => $this->siteId,
            'branch_id' => null,
            'scope_key' => 'site',
            'module' => 'ordering',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->branchPublicId = (string) Str::uuid();
        SmartOrderingBranch::query()->create([
            'public_id' => $this->branchPublicId,
            'site_id' => $this->siteId,
            'code' => 'v2-revision-localized',
            'name_ar' => 'فرع الطلب الذكي',
            'name_en' => 'Smart Ordering Branch',
            'timezone' => 'Asia/Kuwait',
            'is_active' => true,
            'accepting_orders' => true,
            'supports_pickup' => true,
        ]);
    }

    public function test_public_cart_rejects_missing_and_stale_revisions_in_selected_language_without_writes(): void
    {
        $messages = [
            'ar' => [
                'missing' => 'لا توجد نسخة منشورة من الطلب الذكي.',
                'stale' => 'نسخة الطلب الذكي المطلوبة قديمة أو غير منشورة.',
            ],
            'en' => [
                'missing' => 'Smart Ordering has no published revision.',
                'stale' => 'Requested Smart Ordering revision is stale or unpublished.',
            ],
        ];

        foreach ($messages as $locale => $copy) {
            app()->setLocale($locale);

            $this->withHeader('Accept-Language', $locale)
                ->postJson('/api/smart-ordering/carts', [
                    'branch_public_id' => $this->branchPublicId,
                ])
                ->assertConflict()
                ->assertJsonPath('message', $copy['missing']);

            $this->assertNoCartCheckoutWrites();
        }

        $publishedRevisionPublicId = (string) Str::uuid();
        $payload = json_encode([
            'schema_version' => 1,
            'site' => ['slug' => 'v2-cart-revision-localization', 'name_en' => 'Restaurant'],
        ], JSON_THROW_ON_ERROR);
        DB::table('smart_ordering_revisions')->insert([
            'public_id' => $publishedRevisionPublicId,
            'site_id' => $this->siteId,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => $payload,
            'checksum' => hash('sha256', $payload),
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($messages as $locale => $copy) {
            app()->setLocale($locale);

            $this->withHeader('Accept-Language', $locale)
                ->postJson('/api/smart-ordering/carts', [
                    'branch_public_id' => $this->branchPublicId,
                    'revision_public_id' => (string) Str::uuid(),
                ])
                ->assertConflict()
                ->assertJsonPath('message', $copy['stale']);

            $this->assertNoCartCheckoutWrites();
        }

        app()->setLocale('ar');
        $created = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/smart-ordering/carts', [
                'branch_public_id' => $this->branchPublicId,
                'revision_public_id' => $publishedRevisionPublicId,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('smart_ordering_carts', [
            'public_id' => (string) $created->json('cart.public_id'),
            'revision_public_id' => $publishedRevisionPublicId,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('smart_ordering_carts', 1);
    }

    private function assertNoCartCheckoutWrites(): void
    {
        $this->assertDatabaseCount('smart_ordering_carts', 0);
        $this->assertDatabaseCount('smart_ordering_cart_items', 0);
        $this->assertDatabaseCount('smart_ordering_analytics_events', 0);
        $this->assertDatabaseCount('smart_ordering_order_contexts', 0);
        $this->assertDatabaseCount('orders', 0);
    }
}
