<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class V2CustomerSalesContractRegisterTest extends TestCase
{
    use RefreshDatabase;

    private int $wholesaleStore;

    private User $maker;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('local');

        $this->wholesaleStore = app(WholesalePrincipal::class)->storeId();
        $this->maker = $this->actor('B2B_ADMIN', 'maker-contract@example.test');
        $this->checker = $this->actor('SUPER_ADMIN', 'checker-contract@example.test');
    }

    public function test_separate_checker_approves_private_signed_contract_and_termination_is_audited(): void
    {
        $quoteId = $this->issuedQuote();
        $before = [
            'orders' => DB::table('orders')->count(),
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
        ];

        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($quoteId))
            ->assertRedirect(route('admin.sales-quotations.show', ['quotation' => $quoteId]));

        $contract = DB::table('customer_sales_contracts')->sole();
        $this->assertSame('pending', $contract->status);
        $this->assertSame($this->wholesaleStore, (int) $contract->store_id);
        Storage::disk('local')->assertExists($contract->evidence_path);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($contract->evidence_path)), $contract->evidence_sha256);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.contract.submitted', 'store_id' => $this->wholesaleStore]);

        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.decision', ['contract' => $contract->id]), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($this->checker)
            ->get(route('admin.sales-contracts.evidence', ['contract' => $contract->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->checker)
            ->post(route('admin.sales-contracts.decision', ['contract' => $contract->id]), ['decision' => 'approve'])
            ->assertRedirect();
        $this->assertDatabaseHas('customer_sales_contracts', [
            'id' => $contract->id,
            'status' => 'active',
            'approved_by' => $this->checker->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.contract.approved', 'store_id' => $this->wholesaleStore]);

        $this->actingAs($this->checker)
            ->post(route('admin.sales-contracts.decision', ['contract' => $contract->id]), ['decision' => 'approve'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->checker)
            ->post(route('admin.sales-contracts.terminate', ['contract' => $contract->id]))
            ->assertRedirect();
        $this->assertDatabaseHas('customer_sales_contracts', ['id' => $contract->id, 'status' => 'terminated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.contract.terminated', 'store_id' => $this->wholesaleStore]);
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' must not change during contract governance');
        }
    }

    public function test_cannot_convert_draft_expired_or_already_registered_quotation_into_another_contract(): void
    {
        $draft = $this->issuedQuote('draft');
        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($draft))
            ->assertSessionHasErrors('quotation_id');

        $issued = $this->issuedQuote();
        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($issued))
            ->assertRedirect();
        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($issued))
            ->assertSessionHasErrors('quotation_id');

        $expired = $this->issuedQuote('issued', today()->subDay()->toDateString());
        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($expired))
            ->assertSessionHasErrors('quotation_id');

        $this->assertSame(1, DB::table('customer_sales_contracts')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_cross_store_roleless_and_inactive_actors_cannot_read_or_mutate_contracts(): void
    {
        $quote = $this->issuedQuote();
        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($quote))
            ->assertRedirect();
        $contract = DB::table('customer_sales_contracts')->sole();

        $retailStore = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-P195-FOREIGN',
            'name' => 'Foreign store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retailActor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retailActor->id,
            'store_id' => $retailStore,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($retailActor)
            ->get(route('admin.sales-contracts.evidence', ['contract' => $contract->id]))
            ->assertNotFound();
        $this->actingAs($retailActor)
            ->post(route('admin.sales-contracts.decision', ['contract' => $contract->id]), ['decision' => 'approve'])
            ->assertNotFound();

        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.sales-contracts.evidence', ['contract' => $contract->id]))
            ->assertForbidden();
        $this->checker->update(['is_active' => false]);
        $this->actingAs($this->checker)
            ->post(route('admin.sales-contracts.decision', ['contract' => $contract->id]), ['decision' => 'approve'])
            ->assertForbidden();

        $this->assertDatabaseHas('customer_sales_contracts', ['id' => $contract->id, 'status' => 'pending']);
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_tampered_private_document_blocks_approval_without_any_state_write(): void
    {
        $quote = $this->issuedQuote();
        $this->actingAs($this->maker)
            ->post(route('admin.sales-contracts.store'), $this->payload($quote))
            ->assertRedirect();
        $contract = DB::table('customer_sales_contracts')->sole();
        Storage::disk('local')->put($contract->evidence_path, '%PDF-1.5 altered');

        $before = DB::table('audit_logs')->count();
        $this->actingAs($this->checker)
            ->post(route('admin.sales-contracts.decision', ['contract' => $contract->id]), ['decision' => 'approve'])
            ->assertStatus(409);
        $this->actingAs($this->checker)
            ->get(route('admin.sales-contracts.evidence', ['contract' => $contract->id]))
            ->assertStatus(409);
        $this->assertDatabaseHas('customer_sales_contracts', ['id' => $contract->id, 'status' => 'pending']);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_english_and_arabic_contracts_workbench_is_discoverable_in_existing_sales_surface(): void
    {
        $this->actingAs($this->maker)
            ->get(route('admin.sales-quotations.index', ['store_id' => $this->wholesaleStore]))
            ->assertOk()
            ->assertSee('data-customer-contracts', false)
            ->assertSee('Customer contracts and pricing');

        $this->maker->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($this->maker->fresh())
            ->get(route('admin.sales-quotations.index', ['store_id' => $this->wholesaleStore]))
            ->assertOk()
            ->assertSee('عقود العملاء والأسعار');
    }

    private function actor(string $role, string $email): User
    {
        $actor = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'locale' => 'en',
        ]);
        $actor->roles()->attach(Role::query()->where('code', $role)->firstOrFail());

        return $actor;
    }

    private function issuedQuote(string $status = 'issued', ?string $expires = null): int
    {
        return (int) DB::table('sales_quotations')->insertGetId([
            'reference' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'store_id' => $this->wholesaleStore,
            'channel' => 'b2b',
            'customer_id' => 1,
            'customer_name_snapshot' => 'Wholesale Contract Customer',
            'created_by' => $this->maker->id,
            'issued_by' => $this->maker->id,
            'status' => $status,
            'currency' => 'EGP',
            'subtotal' => 100,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 100,
            'pricing_snapshot' => json_encode(['items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => 100]]], JSON_THROW_ON_ERROR),
            'expires_at' => $expires ?? today()->addDays(7)->toDateString(),
            'issued_at' => $status === 'issued' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(int $quotation): array
    {
        return [
            'quotation_id' => $quotation,
            'starts_on' => today()->toDateString(),
            'ends_on' => today()->addDays(30)->toDateString(),
            'signed_document' => UploadedFile::fake()->createWithContent(
                'customer-contract.pdf',
                "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
            ),
        ];
    }
}
