<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class V2AdminInvoicePdfUnavailableLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_invoice_pdf_failure_is_bilingual_private_and_does_not_write(): void
    {
        $storeId = $this->store('B2B', 'V2-ADMIN-PDF-503-WHOLESALE');
        $invoice = $this->invoice($storeId, 'b2b', 'V2-ADMIN-PDF-503-1');
        $actor = $this->globalActor('B2B_ADMIN', 'v2-admin-pdf-locales@example.test');
        $invoiceCount = DB::table('invoices')->count();
        $auditCount = DB::table('audit_logs')->count();

        // A real HTTP request must take the RuntimeException branch; no fake
        // error response and no global PDF renderer replacement.
        $this->app->bindMethod(InvoiceService::class.'@renderPdf', static function (): string {
            throw new \RuntimeException('INTERNAL_PDF_SECRET_SENTINEL');
        });

        foreach ([
            ['en', 'en', 'ar', 'خدمة إنشاء ملف الفاتورة بصيغة PDF غير متاحة مؤقتًا.'],
            ['ar', 'ar', 'en', 'PDF generation is temporarily unavailable.'],
            ['ar', 'en', null, 'خدمة إنشاء ملف الفاتورة بصيغة PDF غير متاحة مؤقتًا.'],
            ['en', 'ar', null, 'PDF generation is temporarily unavailable.'],
            ['fr', 'ar-KW,ar;q=0.9,en;q=0.6', null, 'خدمة إنشاء ملف الفاتورة بصيغة PDF غير متاحة مؤقتًا.'],
            ['fr', 'en-US,en;q=0.9,ar;q=0.6', null, 'PDF generation is temporarily unavailable.'],
        ] as [$accountLocale, $acceptLanguage, $queryLocale, $expected]) {
            $actor->forceFill(['locale' => $accountLocale])->save();
            $url = route('admin.invoices.download', ['invoice' => $invoice->getKey()], false)
                .($queryLocale === null ? '' : '?locale='.$queryLocale);

            $this->actingAs($actor->fresh())
                ->withHeader('Accept-Language', $acceptLanguage)
                ->getJson($url)
                ->assertStatus(503)
                ->assertJsonPath('message', $expected)
                ->assertDontSee('INTERNAL_PDF_SECRET_SENTINEL', false)
                ->assertDontSee('RuntimeException', false);

            $this->assertDatabaseHas('invoices', [
                'id' => $invoice->getKey(),
                'store_id' => $storeId,
                'channel' => 'b2b',
                'status' => 'issued',
            ]);
        }

        $this->assertSame($invoiceCount, DB::table('invoices')->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_foreign_channel_is_denied_before_pdf_renderer_and_preserves_invoice(): void
    {
        $retailStoreId = $this->store('B2C', 'V2-ADMIN-PDF-503-RETAIL');
        $invoice = $this->invoice($retailStoreId, 'b2c', 'V2-ADMIN-PDF-503-FOREIGN');
        $actor = $this->globalActor('B2B_ADMIN', 'v2-admin-pdf-foreign@example.test');

        $this->app->bindMethod(InvoiceService::class.'@renderPdf', static function (): string {
            throw new \RuntimeException('INTERNAL_PDF_SECRET_SENTINEL');
        });

        foreach (['ar', 'en'] as $locale) {
            $this->actingAs($actor)
                ->getJson(route('admin.invoices.download', [
                    'invoice' => $invoice->getKey(),
                    'locale' => $locale,
                ], false))
                ->assertNotFound()
                ->assertDontSee('INTERNAL_PDF_SECRET_SENTINEL', false);
        }

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->getKey(),
            'store_id' => $retailStoreId,
            'channel' => 'b2c',
            'status' => 'issued',
        ]);
    }

    public function test_invalid_pdf_locale_stays_a_validation_error_without_invoice_mutation(): void
    {
        $storeId = $this->store('B2B', 'V2-ADMIN-PDF-503-VALIDATION');
        $invoice = $this->invoice($storeId, 'b2b', 'V2-ADMIN-PDF-503-VALIDATE');
        $actor = $this->globalActor('B2B_ADMIN', 'v2-admin-pdf-validation@example.test');

        $this->actingAs($actor)
            ->getJson(route('admin.invoices.download', ['invoice' => $invoice->getKey()], false).'?locale=fr')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('locale');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->getKey(),
            'status' => 'issued',
        ]);
    }

    public function test_successful_pdf_download_keeps_original_attachment_contract(): void
    {
        $storeId = $this->store('B2B', 'V2-ADMIN-PDF-503-SUCCESS');
        $invoice = $this->invoice($storeId, 'b2b', 'V2-ADMIN-PDF-503-SUCCESS');
        $actor = $this->globalActor('B2B_ADMIN', 'v2-admin-pdf-success@example.test');

        $this->actingAs($actor)
            ->get(route('admin.invoices.download', [
                'invoice' => $invoice->getKey(),
                'locale' => 'ar',
            ], false))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'private, no-store')
            ->assertSee('%PDF', false);
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => $channel === 'B2C' ? 'RETAIL_STORE' : null,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function invoice(int $storeId, string $channel, string $number): Invoice
    {
        return Invoice::query()->create([
            'customer_id' => Customer::query()->create([
                'name' => 'V2 Admin PDF Customer '.$number,
                'type' => $channel,
            ])->getKey(),
            'store_id' => $storeId,
            'channel' => $channel,
            'invoice_number' => $number,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => 12.500,
            'subtotal' => 12.500,
            'store_name_snapshot' => 'V2 Admin PDF Store',
            'customer_name_snapshot' => 'V2 Admin PDF Customer',
            'issued_at' => now(),
        ]);
    }

    private function globalActor(string $roleCode, string $email): User
    {
        $actor = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'locale' => 'en',
        ]);
        $actor->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $actor->fresh();
    }
}
