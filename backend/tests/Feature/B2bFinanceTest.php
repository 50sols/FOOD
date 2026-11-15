<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerDomainResolver;
use App\Services\ReportExportService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class B2bFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_b2b_customer_can_view_owned_invoice_and_statement_with_audit(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user, $customer] = $this->buyer('finance-buyer@example.test', 'active');

        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2B')->value('id'),
            'code' => 'B2B-FINANCE-58',
            'name' => 'Finance Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'FIN-EA',
            'name' => 'Each',
            'decimal_places' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $primaryProduct = Product::query()->create([
            'unit_id' => $unitId,
            'sku' => 'FIN-IMG-PRIMARY',
            'name' => 'Primary image product',
            'is_active' => true,
        ]);
        $fallbackProduct = Product::query()->create([
            'unit_id' => $unitId,
            'sku' => 'FIN-IMG-FALLBACK',
            'name' => 'Fallback image product',
            'is_active' => true,
        ]);
        $noImageProduct = Product::query()->create([
            'unit_id' => $unitId,
            'sku' => 'FIN-NO-IMAGE',
            'name' => 'No image product',
            'is_active' => true,
        ]);
        DB::table('product_images')->insert([
            [
                'product_id' => $primaryProduct->id,
                'path' => 'images/primary-lower-sort.jpg',
                'sort_order' => 0,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $primaryProduct->id,
                'path' => 'https://cdn.example.test/primary.jpg',
                'sort_order' => 99,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $fallbackProduct->id,
                'path' => 'images/fallback-late.jpg',
                'sort_order' => 2,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'product_id' => $fallbackProduct->id,
                'path' => '/images/fallback-first.jpg',
                'sort_order' => 1,
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $invoice = Invoice::query()->create([
            'store_id' => $storeId,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-58-1',
            'status' => 'issued',
            'currency' => 'KWD',
            'subtotal' => 11.500,
            'discount_total' => 1.000,
            'delivery_total' => 1.000,
            'tax_total' => 1.000,
            'total' => 12.500,
            'store_name_snapshot' => 'FOODEX Wholesale',
            'customer_name_snapshot' => 'B2B Buyer',
            'customer_email_snapshot' => 'finance-buyer@example.test',
            'customer_phone_snapshot' => '55510000',
            'payment_method_snapshot' => 'account',
            'payment_status_snapshot' => 'partial',
            'commercial_snapshot' => ['internal_quote_id' => 999],
            'issued_at' => now(),
            'due_at' => now()->addDay(),
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice->id,
            'product_id' => $noImageProduct->id,
            'description' => 'Wholesale item',
            'sku_snapshot' => 'WHO-58',
            'quantity' => 1,
            'unit_price' => 12.500,
            'line_discount_total' => 1.000,
            'line_tax_total' => 1.000,
            'line_total' => 12.500,
            'currency' => 'KWD',
            'price_tier_code_snapshot' => 'INTERNAL-TIER',
            'line_snapshot' => json_encode(['secret_internal' => 'hidden']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('invoice_items')->insert([
            [
                'invoice_id' => $invoice->id,
                'product_id' => $primaryProduct->id,
                'description' => 'Primary image item',
                'sku_snapshot' => 'FIN-IMG-PRIMARY',
                'quantity' => 1,
                'unit_price' => 1,
                'line_discount_total' => 0,
                'line_tax_total' => 0,
                'line_total' => 1,
                'currency' => 'KWD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'invoice_id' => $invoice->id,
                'product_id' => $fallbackProduct->id,
                'description' => 'Fallback image item',
                'sku_snapshot' => 'FIN-IMG-FALLBACK',
                'quantity' => 1,
                'unit_price' => 1,
                'line_discount_total' => 0,
                'line_tax_total' => 0,
                'line_total' => 1,
                'currency' => 'KWD',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('payments')->insert(['invoice_id' => $invoice->id, 'provider' => 'account', 'provider_reference' => 'PAY-58', 'status' => 'paid', 'amount' => 5, 'currency' => 'KWD', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/b2b/invoices?status=partially_paid&q=INV-58-1')
            ->assertOk()
            ->assertJsonPath('data.0.invoice_number', 'INV-58-1')
            ->assertJsonPath('data.0.display_status', 'partially_paid')
            ->assertJsonPath('data.0.paid_amount', 5)
            ->assertJsonPath('data.0.outstanding_amount', 7.5)
            ->assertJsonPath('summary.invoice_count', 1)
            ->assertJsonPath('summary.totals.0.currency', 'KWD')
            ->assertJsonPath('summary.totals.0.total', 12.5)
            ->assertJsonPath('summary.totals.0.paid', 5)
            ->assertJsonPath('summary.totals.0.outstanding', 7.5)
            ->assertJsonPath('meta.has_more', false);
        $detail = $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.invoice_number', 'INV-58-1')
            ->assertJsonPath('data.subtotal', 11.5)
            ->assertJsonPath('data.discount_total', 1)
            ->assertJsonPath('data.delivery_total', 1)
            ->assertJsonPath('data.tax_total', 1)
            ->assertJsonPath('data.total', 12.5)
            ->assertJsonPath('data.paid_amount', 5)
            ->assertJsonPath('data.outstanding_amount', 7.5)
            ->assertJsonPath('data.seller.name', 'FOODEX Wholesale')
            ->assertJsonPath('data.customer.name', 'B2B Buyer')
            ->assertJsonPath('data.customer.email', 'finance-buyer@example.test')
            ->assertJsonPath('data.items.0.sku', 'WHO-58')
            ->assertJsonPath('data.items.0.description', 'Wholesale item')
            ->assertJsonPath('data.items.0.discount_total', 1)
            ->assertJsonPath('data.items.0.tax_total', 1)
            ->assertJsonPath('data.items.0.image_url', null)
            ->assertJsonPath('data.items.1.image_url', 'https://cdn.example.test/primary.jpg')
            ->assertJsonPath('data.items.2.image_url', url('/images/fallback-first.jpg'))
            ->assertJsonPath('data.payments.0.method', 'account')
            ->assertJsonPath('data.payments.0.reference', 'PAY-58')
            ->assertJsonPath('data.payments.0.amount', 5)
            ->assertJsonPath('data.pdf_path', '/api/v1/b2b/invoices/'.$invoice->id.'/download?store_id='.$storeId);

        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id.'?store_id='.$storeId)
            ->assertOk()
            ->assertJsonPath('data.items.1.image_url', 'https://cdn.example.test/primary.jpg')
            ->assertJsonPath('data.items.2.image_url', url('/images/fallback-first.jpg'));

        $pdf = $this->get('/api/v1/b2b/invoices/'.$invoice->id.'/download?locale=ar');
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $detail->assertJsonMissingPath('data.commercial_snapshot')
            ->assertJsonMissingPath('data.items.0.line_snapshot')
            ->assertJsonMissingPath('data.items.0.price_tier_code_snapshot')
            ->assertJsonMissingPath('data.payments.0.provider_reference');
        $this->getJson('/api/v1/b2b/account-statement')->assertOk()->assertJsonPath('data.total_debits', 12.5)->assertJsonPath('data.total_credits', 5)->assertJsonPath('data.balance', -7.5);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.invoice_viewed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.statement_viewed']);
    }

    public function test_approved_wholesale_invoice_search_matches_literal_symbols_without_cross_account_leakage(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$actor, $legacy] = $this->buyer('literal-invoices-owner@example.test', 'active');
        [$other, $foreignLegacy] = $this->buyer('literal-invoices-foreign@example.test', 'active');
        [$pending] = $this->buyer('literal-invoices-pending@example.test', 'pending');

        $resolver = app(CustomerDomainResolver::class);
        $ownerProfile = $resolver->b2b($actor);
        $foreignProfile = $resolver->b2b($other);

        $storeType = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        $retailType = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = static fn (string $code, int $type): int => (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $wholesaleStore = $store('B2B-LITERAL-INVOICES', $storeType);
        $otherWholesaleStore = $store('B2B-LITERAL-SECOND', $storeType);
        $retailStore = $store('B2C-LITERAL-EXCLUSION', $retailType);

        $make = static fn (
            int $b2bCustomerId, int $legacyCustomerId, int $storeId,
            string $invoiceNumber, string $channel = 'b2b',
        ): Invoice => Invoice::query()->create([
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $b2bCustomerId,
            'store_id' => $storeId,
            'channel' => $channel,
            'invoice_number' => $invoiceNumber,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 10,
            'issued_at' => now(),
        ]);
        $target = $make($ownerProfile->id, $legacy->id, $wholesaleStore, 'INV_%!\\-A');
        $decoy = $make($ownerProfile->id, $legacy->id, $wholesaleStore, 'INVX-OTHER');
        $leading = [];
        foreach (['%' => '%LEAD', '_' => '_LEAD', '!' => '!LEAD', '\\' => '\\LEAD'] as $symbol => $number) {
            $leading[$symbol] = $make(
                $ownerProfile->id, $legacy->id, $wholesaleStore, $number,
            )->id;
        }
        $foreign = $make(
            $foreignProfile->id, $foreignLegacy->id,
            $wholesaleStore, 'INV_%!\\-FOREIGN',
        );
        $retail = $make(
            $ownerProfile->id, $legacy->id, $retailStore, 'INV_%!\\-RETAIL', 'b2c',
        );

        $invoiceCount = Invoice::query()->count();
        $paymentCount = DB::table('payments')->count();
        Sanctum::actingAs($actor);
        $endpoint = '/api/v1/b2b/invoices?q=';
        foreach (['INV_', 'INV_%', 'INV_%!', 'INV_%!\\-'] as $q) {
            $this->getJson($endpoint.rawurlencode($q))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $target->id)
                ->assertJsonPath('filters.q', $q);
        }
        $this->getJson($endpoint.rawurlencode('INV'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->getJson($endpoint.rawurlencode('INV%'))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        foreach (['%', '_', '!', '\\'] as $symbol) {
            $this->getJson($endpoint.rawurlencode($symbol))
                ->assertOk()
                ->assertJsonPath('meta.total', 2);
        }
        $this->getJson($endpoint.rawurlencode('#'.$target->id))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $target->id);
        $this->getJson($endpoint.rawurlencode('INV_%!').'&store_id='.$otherWholesaleStore)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson($endpoint.rawurlencode('INV_%!').'&store_id='.$wholesaleStore)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->getJson($endpoint.rawurlencode('INV_%!').'&from=2035-01-01')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson($endpoint.rawurlencode('INV_%!').'&status=paid')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->getJson($endpoint.rawurlencode('%').'&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1);
        $this->getJson($endpoint.rawurlencode('INV_%!').'&per_page=51')
            ->assertUnprocessable();

        $this->assertNotSame($target->id, $foreign->id);
        $this->assertNotSame($target->id, $retail->id);
        Sanctum::actingAs($other);
        $this->getJson($endpoint.rawurlencode('INV_%!'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $foreign->id);
        Sanctum::actingAs($pending);
        $this->getJson($endpoint.rawurlencode('INV_%!'))->assertForbidden();

        $this->assertSame($invoiceCount, Invoice::query()->count());
        $this->assertSame($paymentCount, DB::table('payments')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'b2b.finance.invoices_viewed']);
    }

    public function test_invoice_ownership_and_account_approval_are_enforced(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$owner, $ownerCustomer] = $this->buyer('finance-owner@example.test', 'active');
        $invoice = Invoice::query()->create(['customer_id' => $ownerCustomer->id, 'invoice_number' => 'INV-58-2', 'status' => 'issued', 'currency' => 'KWD', 'total' => 2, 'issued_at' => now()]);
        [$other] = $this->buyer('finance-other@example.test', 'active');
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)->assertNotFound();
        $this->get('/api/v1/b2b/invoices/'.$invoice->id.'/download')->assertNotFound();

        [$pending] = $this->buyer('finance-pending@example.test', 'pending');
        Sanctum::actingAs($pending);
        $this->getJson('/api/v1/b2b/account-statement')->assertForbidden();
    }

    public function test_b2b_statement_export_renderer_failure_uses_selected_ar_en_and_keeps_data_intact(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        [$user] = $this->buyer('statement-export-error@example.test', 'active');
        Sanctum::actingAs($user);

        // Resolve the authorized account before taking snapshots. The real
        // request is retained; only the final renderer is replaced.
        $this->getJson('/api/v1/b2b/account-statement')->assertOk();
        $this->app->instance(ReportExportService::class, new class {
            /** @param  array<string, mixed>  $report */
            public function build(array $report, string $format, string $locale): array
            {
                throw new \RuntimeException('Private renderer path must never appear in the response.');
            }
        });

        $arabic = 'تصدير المستند غير متاح مؤقتًا.';
        $english = 'Document export is temporarily unavailable.';
        foreach ([
            ['ar', 'en', null, $arabic],
            ['en', 'ar', null, $english],
            ['ar', 'ar', 'en', $english],
            ['en', 'en', 'ar', $arabic],
            ['fr', 'ar', null, $english],
        ] as [$savedLocale, $acceptLanguage, $explicitLocale, $expected]) {
            $user->forceFill(['locale' => $savedLocale])->save();
            Sanctum::actingAs($user->fresh());

            $tables = ['orders', 'invoices', 'payments', 'stock_movements', 'audit_logs'];
            $before = [];
            foreach ($tables as $table) {
                $before[$table] = DB::table($table)->count();
            }

            $url = '/api/v1/b2b/account-statement/export?format=xlsx';
            if ($explicitLocale !== null) {
                $url .= '&locale='.$explicitLocale;
            }
            $this->withHeader('Accept-Language', $acceptLanguage)
                ->getJson($url)
                ->assertStatus(503)
                ->assertJsonPath('message', $expected)
                ->assertDontSee('Private renderer path must never appear in the response.');

            foreach ($tables as $table) {
                $this->assertSame($before[$table], DB::table($table)->count(), $table.' mutated on failed export');
            }
        }
    }

    private function buyer(string $email, string $status): array
    {
        $user = User::query()->create(['name' => 'B2B Buyer', 'email' => $email, 'password' => 'password', 'is_active' => true]);
        $customer = Customer::query()->create(['user_id' => $user->id, 'type' => 'b2b', 'name' => 'B2B Buyer', 'email' => $email]);
        B2bAccount::query()->create(['customer_id' => $customer->id, 'company_name' => 'Buyer Co', 'status' => $status]);

        return [$user, $customer];
    }
}
