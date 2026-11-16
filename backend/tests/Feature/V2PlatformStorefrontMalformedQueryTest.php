<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2PlatformStorefrontMalformedQueryTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleId;

    private int $retailId;

    private int $inactiveRetailId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        $b2bType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $b2cType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $this->assertGreaterThan(0, $b2bType);
        $this->assertGreaterThan(0, $b2cType);

        $now = now();
        $this->wholesaleId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2bType,
            'code' => 'V2-P3923-PLATFORM',
            'name' => 'V2 Public Wholesale Platform',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->retailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'V2-P3923-RETAIL',
            'name' => 'V2 Public Retail Placement',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->inactiveRetailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $b2cType,
            'code' => 'V2-P3923-INACTIVE',
            'name' => 'V2 Inactive Retail',
            'is_active' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        config(['foodex.platform_wholesale_store_code' => 'V2-P3923-PLATFORM']);
    }

    public function test_public_wholesale_home_keeps_authorized_b2b_principal_and_active_b2c_placements_with_scalar_filters(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $response = $this->getJson(
                '/api/v1/platform/storefront?q=Missing-SKU&category_id=999999&country_code=eg&city=Cairo&area=Downtown',
                ['Accept-Language' => $locale],
            )->assertOk()
                ->assertJsonPath('store.id', $this->wholesaleId)
                ->assertJsonPath('store.channel', 'b2b')
                ->assertJsonPath('store.is_platform_principal', true)
                ->assertJsonCount(0, 'products.data');

            $retailIds = collect($response->json('retail_banners'))->pluck('store_id')->map(
                static fn ($id): int => (int) $id,
            )->all();
            $this->assertContains($this->retailId, $retailIds);
            $this->assertNotContains($this->inactiveRetailId, $retailIds);
            $this->assertNotContains($this->wholesaleId, $retailIds);
        }

        $this->getJson('/api/v1/platform/storefront')
            ->assertOk()
            ->assertJsonPath('store.id', $this->wholesaleId)
            ->assertJsonStructure(['products' => ['data'], 'retail_banners']);
        $this->getJson('/api/v1/platform/storefront?q=&category_id=&country_code=&city=&area=')
            ->assertOk()
            ->assertJsonPath('store.id', $this->wholesaleId);
    }

    public function test_malformed_public_filter_shapes_fail_with_field_scoped_422_in_both_locales_without_writes(): void
    {
        $invalid = [
            'q%5B0%5D=product' => 'q',
            'q='.str_repeat('x', 161) => 'q',
            'country_code%5Bx%5D=EG' => 'country_code',
            'country_code='.str_repeat('E', 9) => 'country_code',
            'city%5B%5D=Cairo' => 'city',
            'city='.str_repeat('C', 161) => 'city',
            'area%5Bzone%5D=Downtown' => 'area',
            'area='.str_repeat('A', 161) => 'area',
            'category_id%5B%5D=4' => 'category_id',
            'category_id=not-a-number' => 'category_id',
            'category_id=0' => 'category_id',
            'category_id=-1' => 'category_id',
        ];

        $before = [
            'stores' => DB::table('stores')->count(),
            'orders' => DB::table('orders')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
        ];
        foreach (['ar', 'en'] as $locale) {
            foreach ($invalid as $query => $field) {
                $this->getJson('/api/v1/platform/storefront?'.$query, ['Accept-Language' => $locale])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors([$field])
                    ->assertJsonMissingPath('products');
            }
        }

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed during read-only requests');
        }
        $this->assertDatabaseHas('stores', ['id' => $this->wholesaleId, 'is_active' => true]);
        $this->assertDatabaseHas('stores', ['id' => $this->retailId, 'is_active' => true]);
        $this->assertDatabaseHas('stores', ['id' => $this->inactiveRetailId, 'is_active' => false]);
    }
}
