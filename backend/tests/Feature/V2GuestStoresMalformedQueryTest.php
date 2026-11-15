<?php

namespace Tests\Feature;

use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2GuestStoresMalformedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_public_guest_store_listing_keeps_active_retail_scope_and_scalar_pagination(): void
    {
        $retail = $this->createStore('B2C', 'V2-3910-ACTIVE', true);
        $this->createStore('B2C', 'V2-3910-INACTIVE', false);
        $this->createStore('B2B', 'V2-3910-WHOLESALE', true);

        $this->getJson('/api/v1/stores')
            ->assertOk()
            ->assertJsonPath('data.0.id', $retail)
            ->assertJsonPath('data.0.channel', 'b2c')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/stores?country_code=kw&city=Kuwait&area=Hawalli&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $retail)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/stores?country_code=&city=&area=')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_malformed_public_guest_store_filters_are_bilingual_field_errors_without_writes(): void
    {
        $retail = $this->createStore('B2C', 'V2-3910-ACTIVE', true);
        $wholesale = $this->createStore('B2B', 'V2-3910-WHOLESALE', true);

        $before = [
            'stores' => DB::table('stores')->count(),
            'zones' => DB::table('store_service_zones')->count(),
            'orders' => DB::table('orders')->count(),
        ];

        $invalidQueries = [
            ['country_code%5B%5D=KW', 'country_code'],
            ['country_code%5Bkey%5D=KW', 'country_code'],
            ['city%5B%5D=Kuwait', 'city'],
            ['area%5Bx%5D=Hawalli', 'area'],
            ['per_page%5B%5D=1', 'per_page'],
            ['per_page=invalid', 'per_page'],
            ['per_page=', 'per_page'],
            ['per_page=0', 'per_page'],
            ['per_page=101', 'per_page'],
            ['per_page=999999999999999999999999999', 'per_page'],
            ['page%5B%5D=1', 'page'],
            ['page=0', 'page'],
            ['page=', 'page'],
            ['country_code='.str_repeat('X', 17), 'country_code'],
            ['city='.str_repeat('X', 151), 'city'],
            ['area='.str_repeat('X', 151), 'area'],
        ];

        foreach ([
            'ar-KW,ar;q=0.9,en;q=0.4' => 'معامل تصفية المتاجر غير صالح.',
            'en-US,en;q=0.9,ar;q=0.4' => 'The store filter parameter is invalid.',
        ] as $language => $message) {
            foreach ($invalidQueries as [$query, $field]) {
                $this->getJson('/api/v1/stores?'.$query, ['Accept-Language' => $language])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors($field)
                    ->assertJsonPath('errors.'.$field.'.0', $message)
                    ->assertJsonMissing(['id' => $retail])
                    ->assertJsonMissing(['id' => $wholesale]);
            }
        }

        $this->assertSame($before['stores'], DB::table('stores')->count());
        $this->assertSame($before['zones'], DB::table('store_service_zones')->count());
        $this->assertSame($before['orders'], DB::table('orders')->count());

        $this->getJson('/api/v1/stores')->assertOk()->assertJsonPath('meta.total', 1);
    }

    private function createStore(string $type, string $code, bool $active): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
