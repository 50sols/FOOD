<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2GuestCatalogMalformedPaginationTest extends TestCase
{
    use RefreshDatabase;

    private int $retailStore;

    private int $foreignRetailStore;

    private int $wholesaleStore;

    private int $inactiveStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $wholesaleType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');

        $this->retailStore = $this->createStore('V2-PAGINATION-A', $retailType, true);
        $this->foreignRetailStore = $this->createStore('V2-PAGINATION-B', $retailType, true);
        $this->wholesaleStore = $this->createStore('V2-PAGINATION-W', $wholesaleType, true);
        $this->inactiveStore = $this->createStore('V2-PAGINATION-I', $retailType, false);
    }

    public function test_public_retail_collections_reject_malformed_page_and_per_page_in_both_languages_without_writes(): void
    {
        $auditCount = DB::table('audit_logs')->count();
        $storeCount = DB::table('stores')->count();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (['categories', 'products', 'offers', 'banners'] as $collection) {
                $uri = "/api/v1/stores/{$this->retailStore}/{$collection}";
                $invalid = [
                    ['page%5B%5D=2', 'page'],
                    ['page%5Bnested%5D=2', 'page'],
                    ['page=0', 'page'],
                    ['page=-1', 'page'],
                    ['page=1.5', 'page'],
                    ['per_page%5B%5D=4', 'per_page'],
                    ['per_page%5Bnested%5D=4', 'per_page'],
                    ['per_page=oops', 'per_page'],
                    ['per_page=0', 'per_page'],
                    ['per_page=-2', 'per_page'],
                    ['per_page='.($collection === 'banners' ? '51' : '101'), 'per_page'],
                ];

                foreach ($invalid as [$query, $field]) {
                    $this->getJson($uri.'?'.$query, ['Accept-Language' => $locale])
                        ->assertUnprocessable()
                        ->assertJsonValidationErrors($field);
                }
            }
        }

        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSame($storeCount, DB::table('stores')->count());
    }

    public function test_valid_pagination_preserves_existing_defaults_bounds_and_store_visibility(): void
    {
        foreach (['categories', 'products', 'offers', 'banners'] as $collection) {
            $uri = "/api/v1/stores/{$this->retailStore}/{$collection}";

            $this->getJson($uri)
                ->assertOk()
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.per_page', $collection === 'banners' ? 10 : 20);

            $this->getJson($uri.'?per_page=1&page=2')
                ->assertOk()
                ->assertJsonPath('meta.current_page', 2)
                ->assertJsonPath('meta.per_page', 1)
                ->assertJsonCount(0, 'data');

            $this->getJson($uri.'?per_page='.($collection === 'banners' ? '50' : '100'))
                ->assertOk()
                ->assertJsonPath('meta.per_page', $collection === 'banners' ? 50 : 100);

            $this->getJson("/api/v1/stores/{$this->wholesaleStore}/{$collection}?per_page%5B%5D=2")
                ->assertNotFound();
            $this->getJson("/api/v1/stores/{$this->inactiveStore}/{$collection}?page%5B%5D=2")
                ->assertNotFound();
            $this->getJson("/api/v1/stores/{$this->foreignRetailStore}/{$collection}")
                ->assertOk()->assertJsonCount(0, 'data');
        }

        $this->getJson("/api/v1/stores/{$this->retailStore}/products?q=Example&sort=name&direction=asc&per_page=1")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.per_page', 1);
    }

    private function createStore(string $code, int $type, bool $active): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
