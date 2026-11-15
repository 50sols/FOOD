<?php

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\Invoice;
use App\Models\User;
use App\Services\B2bCustomerService;
use App\Services\InvoiceService;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class V2B2bInvoicePdfUnavailableLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_failed_wholesale_pdf_is_localized_without_exception_leak_or_finance_mutation(): void
    {
        [$actor, $invoice] = $this->buyerWithInvoice('B2B-OWNED-PDF-ERROR', 'active', 'ar');
        Sanctum::actingAs($actor);

        // Resolve customer domain and the owned invoice before measuring changes.
        $this->getJson('/api/v1/b2b/invoices/'.$invoice->id)->assertOk();
        $this->app->bindMethod(InvoiceService::class.'@renderPdf', static function (): string {
            throw new \RuntimeException('SECRET_PDF_RENDERER_PATH_DO_NOT_DISCLOSE');
        });

        foreach ([
            ['ar', 'en', null, 'خدمة إنشاء ملف الفاتورة بصيغة PDF غير متاحة مؤقتًا.'],
            ['en', 'ar', null, 'PDF generation is temporarily unavailable.'],
            ['ar', 'en', 'en', 'PDF generation is temporarily unavailable.'],
            ['en', 'ar', 'ar', 'خدمة إنشاء ملف الفاتورة بصيغة PDF غير متاحة مؤقتًا.'],
            ['fr', 'ar-KW,ar;q=0.9,en;q=0.6', null, 'خدمة إنشاء ملف الفاتورة بصيغة PDF غير متاحة مؤقتًا.'],
            ['fr', 'en-US,en;q=0.9,ar;q=0.6', null, 'PDF generation is temporarily unavailable.'],
        ] as [$savedLocale, $headerLocale, $queryLocale, $expectedMessage]) {
            $actor->forceFill(['locale' => $savedLocale])->save();
            Sanctum::actingAs($actor->fresh());

            $businessBefore = $this->businessRecordCounts();
            $auditBefore = DB::table('audit_logs')
                ->where('event', 'b2b.finance.invoice_downloaded')->count();

            $query = $queryLocale === null ? '' : '?locale='.$queryLocale;
            $this->withHeader('Accept-Language', $headerLocale)
                ->getJson('/api/v1/b2b/invoices/'.$invoice->id.'/download'.$query)
                ->assertStatus(503)
                ->assertJsonPath('message', $expectedMessage)
                ->assertDontSee('SECRET_PDF_RENDERER_PATH_DO_NOT_DISCLOSE', false);

            $this->assertDatabaseHas('invoices', [
                'id' => $invoice->id,
                'b2b_customer_id' => $invoice->b2b_customer_id,
                'channel' => 'b2b',
                'status' => 'issued',
            ]);
            $this->assertSame($businessBefore, $this->businessRecordCounts());
            // This pre-render audit already existed. PDF failure must NOT remove
            // or duplicate the single legitimate download-attempt record.
            $this->assertSame(
                $auditBefore + 1,
                DB::table('audit_logs')->where('event', 'b2b.finance.invoice_downloaded')->count(),
            );
        }
    }

    public function test_unauthorized_or_unowned_b2b_invoice_never_reaches_pdf_renderer(): void
    {
        [$owner, $invoice] = $this->buyerWithInvoice('B2B-OWNER-SECURE-PDF', 'active', 'en');
        $path = '/api/v1/b2b/invoices/'.$invoice->id.'/download';
        $downloadAuditBefore = DB::table('audit_logs')
            ->where('event', 'b2b.finance.invoice_downloaded')->count();
        $this->getJson($path)->assertUnauthorized();

        [$foreign] = $this->buyerWithInvoice('B2B-FOREIGN-SECURE-PDF', 'active', 'ar');
        Sanctum::actingAs($foreign);
        $this->getJson($path)->assertNotFound();

        [$pending] = $this->buyerWithInvoice('B2B-PENDING-SECURE-PDF', 'pending', 'ar');
        Sanctum::actingAs($pending);
        $this->getJson($path)->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson($path.'?locale=fr')->assertUnprocessable();
        $retailChannelInvoice = Invoice::query()->create([
            'customer_id' => $invoice->customer_id,
            'b2b_customer_id' => $invoice->b2b_customer_id,
            'store_id' => $invoice->store_id,
            'channel' => 'b2c',
            'invoice_number' => 'B2C-IMPORTED-UNTRUSTED',
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 9,
            'issued_at' => now(),
        ]);
        $this->getJson('/api/v1/b2b/invoices/'.$retailChannelInvoice->id.'/download')
            ->assertNotFound();
        $this->assertSame(
            $downloadAuditBefore,
            DB::table('audit_logs')->where('event', 'b2b.finance.invoice_downloaded')->count(),
        );
    }

    /**
     * @return array{0: User, 1: Invoice}
     */
    private function buyerWithInvoice(string $code, string $status, string $locale): array
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => $locale]);
        $customer = app(B2bCustomerService::class)->create([
            'name' => 'V2 PDF Buyer',
            'email' => $user->email,
        ], $user);
        B2bAccount::query()->create([
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'company_name' => 'V2 PDF Buyer',
            'status' => $status,
        ]);
        $invoice = Invoice::query()->create([
            'store_id' => app(WholesalePrincipal::class)->storeId(),
            'customer_id' => $customer->legacy_customer_id,
            'b2b_customer_id' => $customer->id,
            'b2c_customer_id' => null,
            'channel' => 'b2b',
            'invoice_number' => $code,
            'status' => 'issued',
            'currency' => 'KWD',
            'subtotal' => 10,
            'total' => 10,
            'issued_at' => now(),
        ]);

        return [$user, $invoice];
    }

    /**
     * @return array<string, int>
     */
    private function businessRecordCounts(): array
    {
        $tables = [
            'b2b_accounts',
            'b2b_customers',
            'invoices',
            'invoice_items',
            'orders',
            'payments',
            'inventories',
            'stock_movements',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
