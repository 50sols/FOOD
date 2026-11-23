<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2SalesQuotationsDualPaginationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_quotation_and_contract_pages_are_independently_validated_after_store_authorization_ar_en(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $quoteId = (int) DB::table('sales_quotations')->insertGetId([
            'reference' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'channel' => 'b2b',
            'customer_id' => 1,
            'customer_name_snapshot' => 'Authorized pagination customer',
            'created_by' => $actor->id,
            'status' => 'draft',
            'currency' => 'EGP',
            'subtotal' => 10,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
            'pricing_snapshot' => json_encode(['items' => []], JSON_THROW_ON_ERROR),
            'expires_at' => today()->addDays(7)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleless = User::factory()->create(['is_active' => true]);
        $foreignStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-P4234-FOREIGN',
            'name' => 'Foreign Retail Sales Quotations',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignActor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $foreignActor->id,
            'store_id' => $foreignStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = [
            'quotes' => DB::table('sales_quotations')->count(),
            'contracts' => DB::table('customer_sales_contracts')->count(),
            'orders' => DB::table('orders')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];

        $originalLocale = app()->getLocale();

        try {
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                $actor->forceFill(['locale' => $locale])->saveQuietly();
                app()->setLocale($locale);

                foreach ([
                    ['page' => ['bad']],
                    ['page' => 'bad'],
                    ['page' => '0'],
                    ['page' => '-1'],
                    ['page' => '1.5'],
                    ['page' => '999999999999999999999999'],
                    ['contracts_page' => ['bad']],
                    ['contracts_page' => 'bad'],
                    ['contracts_page' => '0'],
                    ['contracts_page' => '-1'],
                    ['contracts_page' => '1.5'],
                    ['contracts_page' => '999999999999999999999999'],
                    ['page' => '1', 'contracts_page' => 'bad'],
                    ['page' => 'bad', 'contracts_page' => '1'],
                ] as $selector) {
                    $field = array_key_exists('page', $selector) && $selector['page'] !== '1'
                        ? 'page' : 'contracts_page';

                    $this->actingAs($actor->fresh())
                        ->getJson(route('admin.sales-quotations.index', ['store_id' => $storeId] + $selector))
                        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
                    $this->actingAs($actor->fresh())
                        ->getJson(route('admin.sales-quotations.show', ['quotation' => $quoteId] + $selector))
                        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
                }

                // RBAC and tenant record identity must win before navigation errors.
                $this->actingAs($roleless)
                    ->getJson(route('admin.sales-quotations.index', [
                        'store_id' => $storeId, 'page' => 'bad',
                    ]))->assertForbidden();
                $this->actingAs($foreignActor)
                    ->getJson(route('admin.sales-quotations.index', [
                        'store_id' => $storeId, 'contracts_page' => 'bad',
                    ]))->assertNotFound();
                $this->actingAs($foreignActor)
                    ->getJson(route('admin.sales-quotations.show', [
                        'quotation' => $quoteId, 'page' => 'bad',
                    ]))->assertNotFound();

                foreach ([
                    [],
                    ['page' => ''],
                    ['contracts_page' => ''],
                    ['page' => '1', 'contracts_page' => '1'],
                    ['page' => '2', 'contracts_page' => '3'],
                ] as $selector) {
                    $this->actingAs($actor->fresh())
                        ->get(route('admin.sales-quotations.index', ['store_id' => $storeId] + $selector))
                        ->assertOk()->assertSee('dir="'.$direction.'"', false);
                    $this->actingAs($actor->fresh())
                        ->get(route('admin.sales-quotations.show', ['quotation' => $quoteId] + $selector))
                        ->assertOk()->assertSee('dir="'.$direction.'"', false);
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $this->assertSame($before['quotes'], DB::table('sales_quotations')->count());
        $this->assertSame($before['contracts'], DB::table('customer_sales_contracts')->count());
        $this->assertSame($before['orders'], DB::table('orders')->count());
        $this->assertSame($before['audits'], DB::table('audit_logs')->count());
        $this->assertDatabaseHas('sales_quotations', ['id' => $quoteId, 'status' => 'draft']);
    }
}
