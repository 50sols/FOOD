<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2RetailWholesaleProductMappingLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_invalid_source_and_foreign_target_are_localized_and_never_write_mapping_or_finance_stock(): void
    {
        $fixture = $this->products();
        $admin = $this->actor('SUPER_ADMIN');
        Sanctum::actingAs($admin);

        foreach ([
            ['ar', 'en-US,en;q=0.9', 'يجب أن ينتمي المنتج المصدر إلى كتالوج جملة نشط.', 'يجب أن ينتمي منتج التجزئة إلى متجر التجزئة المحدد.'],
            ['en', 'ar-KW,ar;q=0.9', 'The source product must belong to an active wholesale catalog.', 'The retail product must belong to the selected retail store.'],
            ['fr', 'ar-KW,ar;q=0.9', 'يجب أن ينتمي المنتج المصدر إلى كتالوج جملة نشط.', 'يجب أن ينتمي منتج التجزئة إلى متجر التجزئة المحدد.'],
            ['fr', 'en-US,en;q=0.9', 'The source product must belong to an active wholesale catalog.', 'The retail product must belong to the selected retail store.'],
        ] as [$savedLocale, $acceptLanguage, $sourceMessage, $targetMessage]) {
            $admin->forceFill(['locale' => $savedLocale])->save();
            $before = $this->watchedCounts();

            // A B2C product cannot be forged into an approved B2B source.
            $this->withHeader('Accept-Language', $acceptLanguage)
                ->putJson('/api/v1/b2b/product-mappings/'.$fixture['retail_product'], [
                    'retail_store_id' => $fixture['retail_store'],
                    'retail_product_id' => $fixture['retail_product'],
                    'quantity_conversion_factor' => 1.5,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.source_product.0', $sourceMessage);

            // An otherwise valid wholesale source cannot map to a foreign B2C store.
            $this->withHeader('Accept-Language', $acceptLanguage)
                ->putJson('/api/v1/b2b/product-mappings/'.$fixture['wholesale_product'], [
                    'retail_store_id' => $fixture['retail_store'],
                    'retail_product_id' => $fixture['foreign_retail_product'],
                    'quantity_conversion_factor' => 1.5,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.retail_product_id.0', $targetMessage);

            $this->assertSame($before, $this->watchedCounts());
            $this->assertDatabaseHas('users', [
                'id' => $admin->getKey(),
                'locale' => $savedLocale,
                'is_active' => true,
            ]);
        }
    }

    public function test_missing_actor_and_non_mapping_role_are_denied_and_valid_mapping_upsert_is_idempotent(): void
    {
        $fixture = $this->products();
        $url = '/api/v1/b2b/product-mappings/'.$fixture['wholesale_product'];
        $payload = [
            'retail_store_id' => $fixture['retail_store'],
            'retail_product_id' => $fixture['retail_product'],
            'quantity_conversion_factor' => 1.5,
        ];
        $before = $this->watchedCounts();

        $this->putJson($url, $payload)->assertUnauthorized();

        $finance = $this->actor('FINANCE');
        Sanctum::actingAs($finance);
        $this->putJson($url, $payload)->assertForbidden();
        $this->assertSame($before, $this->watchedCounts());

        $admin = $this->actor('SUPER_ADMIN');
        Sanctum::actingAs($admin);
        $first = $this->putJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('retail_store_id', $fixture['retail_store'])
            ->assertJsonPath('source_wholesale_product_id', $fixture['wholesale_product'])
            ->assertJsonPath('retail_product_id', $fixture['retail_product']);

        $this->putJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('quantity_conversion_factor', 1.5);

        $this->assertDatabaseHas('retail_wholesale_product_mappings', [
            'id' => $first->json('id'),
            'retail_store_id' => $fixture['retail_store'],
            'source_wholesale_product_id' => $fixture['wholesale_product'],
            'retail_product_id' => $fixture['retail_product'],
        ]);
        $this->assertDatabaseCount('retail_wholesale_product_mappings', 1);
        foreach (['orders', 'invoices', 'payments', 'stock_movements', 'audit_logs'] as $table) {
            $this->assertSame($before[$table], DB::table($table)->count());
        }
    }

    /**
     * @return array<string, int>
     */
    private function products(): array
    {
        $wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $retailStore = $this->retailStore('V2-MAP-RETAIL');
        $foreignRetailStore = $this->retailStore('V2-MAP-FOREIGN');

        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'V2-MAP-UNIT',
            'name' => 'Mapping unit',
            'name_ar' => 'وحدة ربط',
            'name_en' => 'Mapping unit',
            'scope' => 'b2b',
            'scope_key' => 'b2b',
            'decimal_places' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wholesaleCatalog = $this->catalog($wholesaleStore, 'b2b', 'V2-MAP-B2B');
        $retailCatalog = $this->catalog($retailStore, 'b2c', 'V2-MAP-B2C');
        $foreignRetailCatalog = $this->catalog($foreignRetailStore, 'b2c', 'V2-MAP-FOREIGN');

        return [
            'retail_store' => $retailStore,
            'wholesale_product' => $this->product($wholesaleCatalog, $unitId, 'V2-MAP-WHOLESALE'),
            'retail_product' => $this->product($retailCatalog, $unitId, 'V2-MAP-LOCAL'),
            'foreign_retail_product' => $this->product($foreignRetailCatalog, $unitId, 'V2-MAP-FOREIGN'),
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

    private function catalog(int $storeId, string $channel, string $code): int
    {
        return (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => $channel,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(int $catalogId, int $unitId, string $sku): int
    {
        return (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => $sku,
            'name' => $sku,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['locale' => 'ar', 'is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $user;
    }

    /**
     * @return array<string, int>
     */
    private function watchedCounts(): array
    {
        $counts = [];

        foreach ([
            'retail_wholesale_product_mappings',
            'orders',
            'invoices',
            'payments',
            'stock_movements',
            'inventories',
            'audit_logs',
            'stores',
            'products',
            'catalogs',
            'users',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
