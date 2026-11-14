<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\SmartOrderingMenuRevisionService;
use App\Domain\SmartOrdering\SmartOrderingMenuWriteService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2SmartOrderingMenuLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_product_picker_searches_name_sku_barcode_as_literal_text_and_never_leaks_other_stores(): void
    {
        $own = $this->fixture('LITERAL-A');
        $foreign = $this->fixture('LITERAL-B');

        $symbol = $this->product($own, 'LIT-A-1', 'اسم %_! عربي', 'BAR-A-1');
        $sku = $this->product($own, 'SKU_!SPECIAL', 'English Regular', 'BAR-A-2');
        $barcode = $this->product($own, 'LIT-A-3', 'Barcoded Item', 'BAR%!SPECIAL');
        $slash = $this->product($own, 'LIT-A-4', 'Slash \ product', 'BAR-A-4');
        $this->product($own, 'LIT-A-5', 'اسم XYZ عربي', 'BAR-A-5');
        $this->product($foreign, 'LIT-B-1', 'اسم %_! عربي', 'BAR-B-1');

        $initial = [
            'products' => DB::table('products')->count(),
            'audit' => DB::table('audit_logs')->count(),
            'store_products' => DB::table('store_products')->count(),
        ];

        foreach ([
            ['locale' => 'ar', 'q' => '%_!', 'expected' => [$symbol]],
            ['locale' => 'en', 'q' => 'SKU_!', 'expected' => [$sku]],
            ['locale' => 'en', 'q' => 'BAR%!', 'expected' => [$barcode]],
            ['locale' => 'en', 'q' => chr(92), 'expected' => [$slash]],
            ['locale' => 'ar', 'q' => 'عربي', 'expected' => [$symbol]],
            ['locale' => 'en', 'q' => 'English', 'expected' => [$sku]],
            ['locale' => 'en', 'q' => 'missing%_!', 'expected' => []],
        ] as $case) {
            $own['admin']->forceFill(['locale' => $case['locale']])->save();
            app()->setLocale($case['locale']);

            $response = $this->actingAs($own['admin']->fresh())
                ->getJson(route('admin.smart-ordering.menu.products', [
                    'store_id' => $own['store_id'],
                    'q' => $case['q'],
                ]))
                ->assertOk()
                ->assertJsonPath('currency', 'EGP');

            $actual = collect($response->json('products'))
                ->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
            $expected = $case['expected'];
            sort($expected);
            $this->assertSame($expected, $actual, 'Literal product picker must exclude wildcard lookalikes.');
        }

        $this->actingAs($own['admin']->fresh())
            ->getJson(route('admin.smart-ordering.menu.products', [
                'store_id' => $foreign['store_id'],
                'q' => '%_!',
            ]))
            ->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->getJson(route('admin.smart-ordering.menu.products', [
                'store_id' => $own['store_id'],
                'q' => '%_!',
            ]))
            ->assertForbidden();

        $this->assertSame($initial['products'], DB::table('products')->count());
        $this->assertSame($initial['store_products'], DB::table('store_products')->count());
        $this->assertSame($initial['audit'], DB::table('audit_logs')->count());
    }

    public function test_draft_focus_search_is_literal_scoped_bilingual_and_read_only(): void
    {
        $own = $this->fixture('FOCUS-A');
        $foreign = $this->fixture('FOCUS-B');
        $writer = app(SmartOrderingMenuWriteService::class);
        $revisions = app(SmartOrderingMenuRevisionService::class);
        $draft = $revisions->draft($own['admin'], $own['site']);
        $foreignDraft = $revisions->draft($foreign['admin'], $foreign['site']);

        $match = $writer->createMenu($own['admin'], $own['site'], $draft, [
            'name_ar' => 'قائمة %_! عربية',
            'name_en' => 'Menu %_! English',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $writer->createMenu($own['admin'], $own['site'], $draft, [
            'name_ar' => 'قائمة XYZ عربية',
            'name_en' => 'Menu XYZ English',
            'is_default' => false,
            'is_visible' => true,
            'sort_order' => 1,
        ]);
        $writer->createMenu($foreign['admin'], $foreign['site'], $foreignDraft, [
            'name_ar' => 'قائمة %_! أجنبية',
            'name_en' => 'Menu %_! Foreign',
            'is_default' => true,
            'is_visible' => true,
            'sort_order' => 0,
        ]);
        $category = $writer->createCategory($own['admin'], $own['site'], $draft, $match, [
            'name_ar' => 'تصنيف !_\ هدف',
            'name_en' => 'Category !_\ Target',
            'is_visible' => true,
            'sold_out' => false,
            'sort_order' => 0,
        ]);

        $initial = [
            'menus' => DB::table('smart_ordering_menus')->count(),
            'categories' => DB::table('smart_ordering_menu_categories')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        foreach ([
            ['locale' => 'ar', 'q' => '%_!', 'type' => 'menu', 'expected' => [$match->id]],
            ['locale' => 'en', 'q' => '%_!', 'type' => 'menu', 'expected' => [$match->id]],
            ['locale' => 'en', 'q' => '!_'.chr(92), 'type' => 'category', 'expected' => [$category->id]],
            ['locale' => 'ar', 'q' => 'عربية', 'type' => 'menu', 'expected' => [$match->id]],
            ['locale' => 'en', 'q' => 'missing%_!', 'type' => 'menu', 'expected' => []],
        ] as $case) {
            $own['admin']->forceFill(['locale' => $case['locale']])->save();
            app()->setLocale($case['locale']);

            $response = $this->actingAs($own['admin']->fresh())
                ->get(route('admin.smart-ordering.menu.index', [
                    'store_id' => $own['store_id'],
                    'q' => $case['q'],
                ]))
                ->assertOk()
                ->assertSee('dir="'.($case['locale'] === 'ar' ? 'rtl' : 'ltr').'"', false);

            $actual = collect($response->viewData('focusResults'))
                ->where('type', $case['type'])
                ->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
            $expected = $case['expected'];
            sort($expected);
            $this->assertSame($expected, $actual, 'Draft focus must not expand literal LIKE metacharacters.');
        }

        $this->actingAs($own['admin']->fresh())
            ->get(route('admin.smart-ordering.menu.index', [
                'store_id' => $foreign['store_id'],
                'q' => '%_!',
            ]))
            ->assertNotFound();

        $this->assertSame($initial['menus'], DB::table('smart_ordering_menus')->count());
        $this->assertSame($initial['categories'], DB::table('smart_ordering_menu_categories')->count());
        $this->assertSame($initial['audit'], DB::table('audit_logs')->count());
    }

    /** @return array{store_id:int, admin:User, site:mixed, catalog_id:int, unit_id:int, category_id:int} */
    private function fixture(string $code): array
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'V2-SO-'.$code,
            'name' => 'V2 Smart Ordering '.$code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'V2-SO-UNIT-'.$code,
            'name' => 'Each '.$code,
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2c',
            'code' => 'default',
            'name' => 'V2 Smart Catalog '.$code,
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = (int) DB::table('categories')->insertGetId([
            'catalog_id' => $catalogId,
            'name' => 'V2 Source '.$code,
            'slug' => strtolower('v2-smart-'.$code),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);
        $site = app(SmartOrderingSiteService::class)->ensureForStore($storeId, $admin);

        return ['store_id' => $storeId, 'admin' => $admin, 'site' => $site,
            'catalog_id' => $catalogId, 'unit_id' => $unitId, 'category_id' => $categoryId];
    }

    /** @param array<string,mixed> $fixture */
    private function product(array $fixture, string $sku, string $name, string $barcode): int
    {
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $fixture['catalog_id'],
            'category_id' => $fixture['category_id'],
            'unit_id' => $fixture['unit_id'],
            'sku' => $sku,
            'barcode' => $barcode,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $fixture['store_id'],
            'product_id' => $productId,
            'price' => 4.250,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }
}
