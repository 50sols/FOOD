<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Finance\AccountsReceivableService;
use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\CollectionAccount;
use App\Models\Customer;
use App\Models\EnterpriseAccountsReceivableAllocation;
use App\Models\EnterpriseAccountsReceivableDocument;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\CollectionCustodyService;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class V2ReceivableSettlementWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);

        config()->set(
            'enterprise_module_readiness.certified_versions.finance',
            app(ModuleRegistry::class)->manifest('finance')->version,
        );
    }

    public function test_bilingual_ar_draft_refuses_unverified_collection_posting_without_journal(): void
    {
        $fixture = $this->fixture('P303-SETTLEMENT');
        $maker = $fixture['maker'];
        $poster = $fixture['poster'];
        $store = $fixture['store'];
        $document = $fixture['document'];

        foreach (['en', 'ar'] as $locale) {
            $maker->forceFill(['locale' => $locale])->save();
            $this->actingAs($maker->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'receivables',
                    'settlement_document_id' => $document->id,
                ]))
                ->assertOk()
                ->assertSee('data-v2-ar-settlements', false)
                ->assertSee('data-v2-ar-settlement-create', false)
                ->assertSee(__('finance_workbench.receivables.settlements', [], $locale))
                // P303 is not receipt-certified: both locales must disclose that
                // a manually entered reference is NOT proof of collected funds.
                ->assertSee(__('finance_workbench.receivables.reference_notice', [], $locale));
        }

        $payload = [
            'store_id' => $store->id,
            'idempotency_key' => 'p303-doc-collection-001',
            'allocation_date' => '2027-01-12',
            'amount' => '40.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'reference' => 'P303-RECEIPT-TEST-001',
        ];

        $this->actingAs($maker)->post(
            route('admin.erp.finance.receivables.settlements.store', $document->id),
            $payload,
        )->assertRedirect();

        $allocation = EnterpriseAccountsReceivableAllocation::query()->sole();
        $this->assertSame('draft', $allocation->status);
        $this->assertSame($maker->id, $allocation->created_by);

        // Replaying the identical request cannot create another allocation.
        $this->actingAs($maker)->post(
            route('admin.erp.finance.receivables.settlements.store', $document->id),
            $payload,
        )->assertRedirect();
        $this->assertDatabaseCount('enterprise_ar_allocations', 1);

        $this->actingAs($maker)->post(
            route('admin.erp.finance.receivables.settlements.post', $allocation->id),
            ['store_id' => $store->id],
        )->assertSessionHasErrors('poster');
        $this->assertSame('draft', $allocation->fresh()->status);

        foreach (['en', 'ar'] as $locale) {
            $poster->forceFill(['locale' => $locale])->save();
            $this->actingAs($poster->fresh())
                ->get(route('admin.erp.finance.index', [
                    'store_id' => $store->id,
                    'tab' => 'receivables',
                    'settlement_document_id' => $document->id,
                ]))
                ->assertOk()
                ->assertSee('data-v2-ar-source-unverified', false)
                ->assertSee(__('finance_workbench.receivables.verification_pending', [], $locale))
                ->assertDontSee('data-v2-ar-settlement-post', false);
        }

        // A manually supplied receipt reference is NOT collection provenance.
        // Direct HTTP must also reject attempts even by an authorized poster.
        foreach (['en', 'ar'] as $locale) {
            $poster->forceFill(['locale' => $locale])->save();
            $this->actingAs($poster->fresh())->post(
                route('admin.erp.finance.receivables.settlements.post', $allocation->id),
                ['store_id' => $store->id],
            )->assertSessionHasErrors([
                'collection_source' => __('finance_workbench.receivables.verification_pending', [], $locale),
            ]);
        }

        $this->assertSame('draft', $allocation->fresh()->status);
        $this->assertNull($allocation->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.finance.ar_allocation_posted',
            'auditable_id' => $allocation->id,
        ]);

        $this->actingAs($poster)->post(
            route('admin.erp.finance.receivables.settlements.post', $allocation->id),
            ['store_id' => $store->id],
        )->assertSessionHasErrors('collection_source');
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    public function test_receivable_account_cannot_be_its_own_settlement_account_without_financial_mutation(): void
    {
        $fixture = $this->fixture('P303-SELF-NET');
        $receivables = app(AccountsReceivableService::class);
        $context = app(TenantContextResolver::class)->retail($fixture['maker'], $fixture['store']->id);
        $document = $fixture['document'];
        $payload = [
            'idempotency_key' => 'p303-selfnet-rejected',
            'allocation_date' => '2027-01-12',
            'amount' => '15.000',
            'settlement_account_id' => $document->receivable_account_id,
            'reference' => 'NOT-A-COLLECTION',
        ];

        // Debiting and crediting the identical AR account would reduce the
        // receivable balance in the settlement model without collected cash.
        try {
            $receivables->createAllocation($fixture['maker'], $context, $document->id, $payload);
            $this->fail('An AR account must not self-settle its own balance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement_account_id', $exception->errors());
        }

        foreach (['en', 'ar'] as $locale) {
            $fixture['maker']->forceFill(['locale' => $locale])->save();
            $this->actingAs($fixture['maker']->fresh())->post(
                route('admin.erp.finance.receivables.settlements.store', $document->id),
                ['store_id' => $fixture['store']->id, ...$payload],
            )->assertSessionHasErrors([
                'settlement_account_id' => __('finance_workbench.receivables.settlement_account_same_as_ar', [], $locale),
            ]);
        }

        $this->assertDatabaseCount('enterprise_ar_allocations', 0);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertSame('posted', $document->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.finance.ar_allocation_created',
        ]);

        // Existing legitimate cash-account drafts stay usable.
        $cash = $receivables->createAllocation(
            $fixture['maker'],
            $context,
            $document->id,
            [...$payload, 'settlement_account_id' => $fixture['cash_account_id']],
        );
        $this->assertSame('draft', $cash->status);
        $this->assertSame((int) $fixture['cash_account_id'], (int) $cash->settlement_account_id);
        $this->assertDatabaseCount('enterprise_ar_allocations', 1);
    }

    public function test_legacy_self_netting_allocation_is_rejected_before_journal_posting(): void
    {
        $fixture = $this->fixture('P303-LEGACY-SELF-NET');
        $allocation = EnterpriseAccountsReceivableAllocation::query()->create([
            'ar_document_id' => $fixture['document']->id,
            'idempotency_key' => 'legacy-self-netting-draft',
            'request_hash' => hash('sha256', 'legacy-self-netting-draft'),
            'allocation_date' => '2027-01-12',
            'amount' => '15.000',
            'settlement_account_id' => $fixture['document']->receivable_account_id,
            'reference' => 'UNVERIFIED',
            'status' => 'draft',
            'created_by' => $fixture['maker']->id,
        ]);
        $posterContext = app(TenantContextResolver::class)->retail(
            $fixture['poster'],
            $fixture['store']->id,
        );

        try {
            app(AccountsReceivableService::class)->postAllocation(
                $fixture['poster'],
                $posterContext,
                $allocation->id,
            );
            $this->fail('A legacy AR self-netting draft must not be posted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement_account_id', $exception->errors());
        }

        $this->assertSame('draft', $allocation->fresh()->status);
        $this->assertNull($allocation->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'enterprise.finance.ar_allocation_posted',
            'auditable_id' => $allocation->id,
        ]);
    }

    public function test_allocation_post_locks_parent_document_before_allocation_to_match_draft_order(): void
    {
        $fixture = $this->fixture('P303-LOCK-ORDER');
        $ar = app(AccountsReceivableService::class);
        $makerContext = app(TenantContextResolver::class)->retail(
            $fixture['maker'],
            $fixture['store']->id,
        );
        $posterContext = app(TenantContextResolver::class)->retail(
            $fixture['poster'],
            $fixture['store']->id,
        );

        $allocation = $ar->createAllocation(
            $fixture['maker'],
            $makerContext,
            $fixture['document']->id,
            [
                'idempotency_key' => 'p303-parent-first-post-lock',
                'allocation_date' => '2027-01-12',
                'amount' => '15.000',
                'settlement_account_id' => $fixture['cash_account_id'],
                'reference' => 'NO-VERIFIED-RECEIPT',
            ],
        );

        $baseTransactionLevel = DB::transactionLevel();
        $readOrder = [];
        DB::listen(function (QueryExecuted $event) use (&$readOrder, $baseTransactionLevel): void {
            if (DB::transactionLevel() <= $baseTransactionLevel) {
                return;
            }

            $sql = strtolower(ltrim($event->sql));
            if (! str_starts_with($sql, 'select')) {
                return;
            }

            if (preg_match('/\\bfrom\\s+["`]?enterprise_ar_allocations["`]?\\s/i', $sql)) {
                $readOrder[] = 'allocation';
            } elseif (preg_match('/\\bfrom\\s+["`]?enterprise_ar_documents["`]?\\s/i', $sql)) {
                $readOrder[] = 'document';
            }
        });

        try {
            $ar->postAllocation(
                $fixture['poster'],
                $posterContext,
                $allocation->id,
                requiresVerifiedCollectionSource: true,
            );
            $this->fail('Unverified P303 receipt must not authorize posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection_source', $exception->errors());
        }

        // A non-locking ID probe may precede both locks, but the actual
        // locking sequence must acquire the document first and allocation
        // second; createAllocation already uses the parent-first order.
        $this->assertSame(['allocation', 'document', 'allocation'], array_slice($readOrder, 0, 3));
        $this->assertSame('draft', $allocation->fresh()->status);
        $this->assertNull($allocation->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    public function test_draft_allocation_checks_and_writes_share_a_document_transaction_with_safe_retries(): void
    {
        $fixture = $this->fixture('P303-ATOMIC-DRAFT');
        $ar = app(AccountsReceivableService::class);
        $context = app(TenantContextResolver::class)->retail($fixture['maker'], $fixture['store']->id);
        $baseTransactionLevel = DB::transactionLevel();
        $documentQueries = [];
        $allocationInserts = [];

        // The seeded Retail fixture is already wrapped in RefreshDatabase's
        // outer test transaction. An additional level is required so that the
        // document lock, idempotency lookup, draft write and audit are atomic.
        DB::listen(function (QueryExecuted $event) use (&$documentQueries, &$allocationInserts): void {
            $sql = strtolower(ltrim($event->sql));
            if (str_starts_with($sql, 'select') && str_contains($sql, 'enterprise_ar_documents')) {
                $documentQueries[] = ['sql' => $sql, 'level' => DB::transactionLevel()];
            }
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'enterprise_ar_allocations')) {
                $allocationInserts[] = DB::transactionLevel();
            }
        });

        $payload = [
            'idempotency_key' => 'p303-atomic-draft-001',
            'allocation_date' => '2027-01-12',
            'amount' => '20.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'reference' => 'UNVERIFIED-NOT-A-RECEIPT',
        ];

        $draft = $ar->createAllocation($fixture['maker'], $context, $fixture['document']->id, $payload);
        $this->assertSame('draft', $draft->status);
        $this->assertNotEmpty($documentQueries);
        $this->assertContains($baseTransactionLevel + 1, array_column($documentQueries, 'level'));
        $this->assertContains($baseTransactionLevel + 1, $allocationInserts);
        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(
                collect($documentQueries)->contains(
                    fn (array $query): bool => str_contains($query['sql'], 'for update')
                        && $query['level'] > $baseTransactionLevel,
                ),
                'MySQL must lock the AR document within the draft transaction.',
            );
        }
        $this->assertSame($baseTransactionLevel, DB::transactionLevel());

        $retry = $ar->createAllocation($fixture['maker'], $context, $fixture['document']->id, $payload);
        $this->assertSame($draft->id, $retry->id);

        try {
            $ar->createAllocation(
                $fixture['maker'],
                $context,
                $fixture['document']->id,
                [...$payload, 'amount' => '21.000'],
            );
            $this->fail('A conflicting idempotency replay must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertSame($baseTransactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('enterprise_ar_allocations', 1);
        $this->assertSame('20.000', (string) $draft->fresh()->amount);
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('event', 'enterprise.finance.ar_allocation_created')
                ->where('auditable_id', $draft->id)
                ->count(),
        );
    }

    public function test_posted_legacy_allocation_cannot_be_replayed_as_verified_collection(): void
    {
        $fixture = $this->fixture('P303-POSTED-REPLAY');
        $tenants = app(TenantContextResolver::class);
        $ar = app(AccountsReceivableService::class);
        $makerContext = $tenants->retail($fixture['maker'], $fixture['store']->id);
        $posterContext = $tenants->retail($fixture['poster'], $fixture['store']->id);
        $allocation = $ar->createAllocation($fixture['maker'], $makerContext, $fixture['document']->id, [
            'idempotency_key' => 'p303-posted-replay',
            'allocation_date' => '2027-01-12',
            'amount' => '10.000',
            'settlement_account_id' => $fixture['cash_account_id'],
        ]);
        $posted = $ar->postAllocation($fixture['poster'], $posterContext, $allocation->id);
        $this->assertSame('posted', $posted->status);
        $journalId = $posted->journal_id;

        try {
            $ar->postAllocation($fixture['poster'], $posterContext, $allocation->id, requiresVerifiedCollectionSource: true);
            $this->fail('A posted legacy AR allocation must not be accepted as receipt-verified.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('collection_source', $exception->errors());
        }

        // Even a legitimately posted legacy AR allocation cannot be
        // replayed through the P303 Admin route as a verified collection.
        // Both locales must give the same fail-closed denial, with no
        // financial journal duplication or mutation of the old posting.
        foreach (['ar', 'en'] as $locale) {
            $fixture['poster']->forceFill(['locale' => $locale])->save();
            $this->actingAs($fixture['poster']->fresh())->post(
                route('admin.erp.finance.receivables.settlements.post', $allocation->id),
                ['store_id' => $fixture['store']->id],
            )->assertSessionHasErrors([
                'collection_source' => __('finance_workbench.receivables.verification_pending', [], $locale),
            ]);
        }

        $this->assertSame('posted', $posted->fresh()->status);
        $this->assertSame($journalId, $posted->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 2);
    }

    public function test_direct_http_denies_cross_store_and_over_allocation_without_financial_mutation(): void
    {
        $fixture = $this->fixture('P303-BOUNDARY');
        $store = $fixture['store'];
        $document = $fixture['document'];
        $maker = $fixture['maker'];
        $foreign = $this->retailStore('P303-OTHER');
        $actor = $this->userForStore($foreign, 'P303-FOREIGN');

        $this->actingAs($actor)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $store->id,
                'tab' => 'receivables',
                'settlement_document_id' => $document->id,
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('admin.erp.finance.receivables.settlements.store', $document->id), [
                'store_id' => $store->id,
                'idempotency_key' => 'p303-cross-store',
                'allocation_date' => '2027-01-12',
                'amount' => '1.000',
                'settlement_account_id' => $fixture['cash_account_id'],
            ])->assertNotFound();

        $this->actingAs($maker)
            ->post(route('admin.erp.finance.receivables.settlements.store', $document->id), [
                'store_id' => $store->id,
                'idempotency_key' => 'p303-overallocate',
                'allocation_date' => '2027-01-12',
                'amount' => '101.000',
                'settlement_account_id' => $fixture['cash_account_id'],
            ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('enterprise_ar_allocations', 0);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    public function test_retail_store_cannot_settle_sibling_store_document_in_the_same_legal_entity(): void
    {
        $fixture = $this->fixture('P303-ONE-STORE');
        $sibling = $this->retailStore('P303-SIBLING');
        $siblingMaker = $this->userForStore($sibling, 'P303-SIBLING-MAKER');
        $siblingPoster = $this->userForStore($sibling, 'P303-SIBLING-POSTER');
        $tenants = app(TenantContextResolver::class);
        $currentContext = $tenants->retail($fixture['maker'], $fixture['store']->id);
        $siblingContext = $tenants->retail($siblingMaker, $sibling->id);
        $siblingPosterContext = $tenants->retail($siblingPoster, $sibling->id);

        // Two stores intentionally share the default enterprise legal entity.
        $this->assertSame(
            $currentContext->enterpriseScope->legalEntityId,
            $siblingContext->enterpriseScope->legalEntityId,
        );

        $ar = app(AccountsReceivableService::class);
        $foreignDocument = $ar->createDraft($siblingMaker, $siblingContext, [
            'idempotency_key' => 'p303-sibling-doc',
            'store_id' => $sibling->id,
            'customer_id' => $fixture['document']->customer_id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '30.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $fixture['document']->receivable_account_id,
            'offset_account_id' => $fixture['document']->offset_account_id,
        ]);
        $ar->post($siblingPoster, $siblingPosterContext, $foreignDocument->id);
        $foreignAllocation = $ar->createAllocation($siblingMaker, $siblingContext, $foreignDocument->id, [
            'idempotency_key' => 'p303-sibling-allocation',
            'allocation_date' => '2027-01-12',
            'amount' => '10.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'reference' => 'P303-SIBLING',
        ]);

        // A previously posted legacy allocation must still be scoped on
        // idempotent retries, not just on the initial financial transition.
        $postedSiblingAllocation = $ar->postAllocation($siblingPoster, $siblingPosterContext, $foreignAllocation->id);
        $this->assertSame('posted', $postedSiblingAllocation->status);
        $postedSiblingJournalId = $postedSiblingAllocation->journal_id;

        $current = $fixture['maker'];
        $currentStoreId = $fixture['store']->id;
        $this->actingAs($current)
            ->get(route('admin.erp.finance.index', ['store_id' => $currentStoreId, 'tab' => 'receivables']))
            ->assertOk()
            ->assertDontSee($foreignDocument->document_number);
        $this->actingAs($current)
            ->get(route('admin.erp.finance.index', [
                'store_id' => $currentStoreId,
                'tab' => 'receivables',
                'settlement_document_id' => $foreignDocument->id,
            ]))
            ->assertNotFound();

        $this->actingAs($current)->post(
            route('admin.erp.finance.receivables.settlements.store', $foreignDocument->id),
            [
                'store_id' => $currentStoreId,
                'idempotency_key' => 'p303-cross-store-allocation',
                'allocation_date' => '2027-01-12',
                'amount' => '1.000',
                'settlement_account_id' => $fixture['cash_account_id'],
            ],
        )->assertNotFound();

        $this->actingAs($current)->post(
            route('admin.erp.finance.receivables.settlements.post', $foreignAllocation->id),
            ['store_id' => $currentStoreId],
        )->assertNotFound();

        // The legitimate sibling-store poster already committed this legacy
        // allocation. A foreign-store HTTP attempt must not alter its status,
        // journal link or consume an additional receipt.
        $this->assertSame('posted', $foreignAllocation->fresh()->status);
        $this->assertSame($postedSiblingJournalId, $foreignAllocation->fresh()->journal_id);
        $this->assertDatabaseMissing('enterprise_ar_allocations', [
            'ar_document_id' => $foreignDocument->id,
            'idempotency_key' => 'p303-cross-store-allocation',
        ]);

        // The controller already denies this posted ID. Direct service calls
        // must also reject cross-store posted replays before returning status.
        $this->expectException(ModelNotFoundException::class);
        $ar->postAllocation($current, $currentContext, $foreignAllocation->id);
    }

    public function test_invoice_to_ar_source_link_requires_exact_store_customer_currency_amount_and_does_not_post(): void
    {
        $fixture = $this->fixture('P303-INVOICE-LINK');
        $maker = $fixture['maker'];
        $poster = $fixture['poster'];
        $store = $fixture['store'];
        $original = $fixture['document'];
        $context = app(TenantContextResolver::class)->retail($maker, $store->id);
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);
        $ar = app(AccountsReceivableService::class);

        $invoice = [
            'invoice_number' => 'P303-INVOICE-SOURCE-1',
            'customer_id' => $original->customer_id,
            'store_id' => $store->id,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => '40.000',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $invoiceId = DB::table('invoices')->insertGetId($invoice);
        $payload = [
            'idempotency_key' => 'p303-invoice-source-draft',
            'store_id' => $store->id,
            'customer_id' => $original->customer_id,
            'source_invoice_id' => $invoiceId,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '40.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $original->receivable_account_id,
            'offset_account_id' => $original->offset_account_id,
        ];

        $linked = $ar->createDraft($maker, $context, $payload);
        $this->assertSame($invoiceId, (int) $linked->source_invoice_id);
        $this->assertSame('draft', $linked->status);
        $this->assertNull($original->source_invoice_id);
        $this->assertSame($linked->id, $ar->createDraft($maker, $context, $payload)->id);

        // Identity linkage is NOT enough to certify revenue/AR accounting.
        // Even an entitled independent poster cannot duplicate a journal.
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            try {
                $ar->post($poster, $posterContext, $linked->id);
                $this->fail('An invoice link must not bypass certified accounting handoff.');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    __('finance_workbench.receivables.source_invoice_invalid', [], $locale),
                    $exception->errors()['source_invoice_id'][0] ?? null,
                );
            }
        }
        $this->assertSame('draft', $linked->fresh()->status);
        $this->assertNull($linked->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);

        $sequence = 0;
        $reject = function (array $changes = []) use ($ar, $maker, $context, $payload, &$sequence): void {
            $sequence++;
            try {
                $ar->createDraft($maker, $context, array_merge(
                    $payload,
                    ['idempotency_key' => 'p303-source-negative-'.$sequence],
                    $changes,
                ));
                $this->fail('Unverified invoice source must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('source_invoice_id', $exception->errors());
            }
        };

        // A second receivable cannot claim the same invoice even with a new key.
        $reject();

        $baseline = [
            'customer_id' => $original->customer_id,
            'store_id' => $store->id,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => '40.000',
            'voided_at' => null,
        ];
        $foreign = $this->retailStore('P303-FOREIGN-INV');
        $otherCustomer = Customer::query()->create([
            'type' => 'b2b',
            'name' => 'Other Invoice Customer',
            'email' => 'other-invoice@example.test',
        ]);
        foreach ([
            ['store_id' => $foreign->id],
            ['customer_id' => $otherCustomer->id],
            ['currency' => 'USD'],
            ['total' => '39.000'],
            ['status' => 'draft'],
            ['voided_at' => now()],
        ] as $tamper) {
            DB::table('invoices')->where('id', $invoiceId)->update(array_merge($baseline, $tamper));
            $reject();
        }

        DB::table('invoices')->where('id', $invoiceId)->update($baseline);
        $reject(['source_invoice_id' => $invoiceId + 10000]);
        $this->assertDatabaseCount('enterprise_ar_documents', 2);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    public function test_typed_collection_receipt_link_is_transactional_unique_and_never_posts_unauthorized_gl(): void
    {
        $fixture = $this->fixture('P303-COLLECTION-LINK');
        $store = $fixture['store'];
        $document = $fixture['document'];
        $maker = $fixture['maker'];
        $poster = $fixture['poster'];
        $context = app(TenantContextResolver::class)->retail($maker, $store->id);
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);

        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'P303-COLLECTION-INVOICE',
            'store_id' => $store->id,
            'customer_id' => $document->customer_id,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => '100.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // This is a test-only existing-posted AR lineage fixture. Runtime
        // invoice-linked AR posting is still gated pending certified GL handoff.
        $document->forceFill(['source_invoice_id' => $invoiceId])->save();

        $account = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $maker->id,
            'store_id' => $store->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $transaction = app(CollectionCustodyService::class)->collect(
            $account,
            $maker,
            'p303-proven-receipt-1',
            30.0,
            'KWD',
            'driver_app',
            [['invoice_id' => $invoiceId, 'amount' => 30.0]],
        );
        $sourceId = (int) DB::table('collection_allocations')
            ->where('collection_transaction_id', $transaction->id)
            ->value('id');

        $ar = app(AccountsReceivableService::class);
        $payload = [
            'idempotency_key' => 'p303-source-bound-draft',
            'allocation_date' => '2027-01-12',
            'amount' => '30.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'source_collection_allocation_id' => $sourceId,
        ];
        $draft = $ar->createAllocation($maker, $context, $document->id, $payload);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($sourceId, (int) $draft->source_collection_allocation_id);
        $this->assertSame($draft->id, $ar->createAllocation($maker, $context, $document->id, $payload)->id);
        $this->assertDatabaseCount('enterprise_ar_allocations', 1);

        foreach ([
            [...$payload, 'idempotency_key' => 'p303-source-double-claim'],
            [...$payload, 'idempotency_key' => 'p303-source-inflated', 'amount' => '31.000'],
        ] as $invalid) {
            try {
                $ar->createAllocation($maker, $context, $document->id, $invalid);
                $this->fail('Reusing or inflating a collection receipt must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('source_collection_allocation_id', $exception->errors());
            }
        }

        foreach (['en', 'ar'] as $locale) {
            $poster->forceFill(['locale' => $locale])->save();
            $this->actingAs($poster->fresh())->post(
                route('admin.erp.finance.receivables.settlements.post', $draft->id),
                ['store_id' => $store->id],
            )->assertSessionHasErrors([
                'collection_source' => __('finance_workbench.receivables.verification_pending', [], $locale),
            ]);
        }

        // A linked operational receipt is not proof that Finance may debit
        // Cash again. P303 remains fail-closed until existing accounting
        // recognition and reversal are reconciled, without double journals.
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->journal_id);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
        $this->assertDatabaseCount('enterprise_ar_allocations', 1);
    }

    public function test_reversed_and_cross_store_collection_sources_cannot_be_claimed(): void
    {
        $fixture = $this->fixture('P303-COLLECTION-NEG');
        $store = $fixture['store'];
        $document = $fixture['document'];
        $maker = $fixture['maker'];
        $context = app(TenantContextResolver::class)->retail($maker, $store->id);
        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_number' => 'P303-COLLECTION-NEG-INV',
            'store_id' => $store->id,
            'customer_id' => $document->customer_id,
            'status' => 'issued',
            'currency' => 'KWD',
            'total' => '100.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document->forceFill(['source_invoice_id' => $invoiceId])->save();
        $account = CollectionAccount::query()->create([
            'actor_type' => 'driver',
            'actor_id' => $maker->id,
            'store_id' => $store->id,
            'currency' => 'KWD',
            'status' => 'active',
        ]);
        $transaction = app(CollectionCustodyService::class)->collect(
            $account, $maker, 'p303-negative-collect', 20.0, 'KWD',
            'driver_app', [['invoice_id' => $invoiceId, 'amount' => 20.0]],
        );
        $sourceId = (int) DB::table('collection_allocations')
            ->where('collection_transaction_id', $transaction->id)
            ->value('id');
        $payload = [
            'allocation_date' => '2027-01-12',
            'amount' => '20.000',
            'settlement_account_id' => $fixture['cash_account_id'],
            'source_collection_allocation_id' => $sourceId,
        ];
        $ar = app(AccountsReceivableService::class);
        $reject = function (string $key) use ($ar, $maker, $context, $document, $payload): void {
            try {
                $ar->createAllocation(
                    $maker, $context, $document->id,
                    [...$payload, 'idempotency_key' => $key],
                );
                $this->fail('A foreign, reversed, or inactive receipt cannot be bound.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('source_collection_allocation_id', $exception->errors());
            }
        };

        DB::table('collection_accounts')->where('id', $account->id)
            ->update(['store_id' => $this->retailStore('P303-FOREIGN-RECEIPT')->id]);
        $reject('p303-foreign-receipt');
        DB::table('collection_accounts')->where('id', $account->id)
            ->update(['store_id' => $store->id, 'status' => 'disabled']);
        $reject('p303-inactive-receipt');
        DB::table('collection_accounts')->where('id', $account->id)
            ->update(['status' => 'active']);
        DB::table('collection_transactions')->where('id', $transaction->id)
            ->update(['status' => 'reversed']);
        $reject('p303-reversed-receipt');
        $this->assertDatabaseCount('enterprise_ar_allocations', 0);
        $this->assertDatabaseCount('enterprise_finance_journals', 1);
    }

    /** @return array{store:Store,maker:User,poster:User,document:EnterpriseAccountsReceivableDocument,cash_account_id:int} */
    private function fixture(string $prefix): array
    {
        $store = $this->retailStore($prefix);
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        foreach (['finance.view', 'finance.manage', 'finance.post'] as $code) {
            $permission = Permission::query()->firstOrCreate(['code' => $code], ['name' => $code]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $maker = $this->userForStore($store, $prefix.'-MAKER');
        $poster = $this->userForStore($store, $prefix.'-POSTER');
        $context = app(TenantContextResolver::class)->retail($maker, $store->id);
        $posterContext = app(TenantContextResolver::class)->retail($poster, $store->id);
        $this->enableFinance($maker, $context);

        $finance = app(FinanceFoundationService::class);
        $receivable = $finance->createAccount($maker, $context, [
            'code' => '1200', 'name' => 'Accounts Receivable',
            'type' => 'asset', 'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $revenue = $finance->createAccount($maker, $context, [
            'code' => '4000', 'name' => 'Sales Revenue',
            'type' => 'revenue', 'normal_balance' => 'credit', 'allow_posting' => true,
        ]);
        $cash = $finance->createAccount($maker, $context, [
            'code' => '1100', 'name' => 'Cash',
            'type' => 'asset', 'normal_balance' => 'debit', 'allow_posting' => true,
        ]);
        $year = $finance->createFiscalYear($maker, $context, [
            'code' => 'FY2027', 'name' => 'Fiscal year 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        ]);
        $finance->createFiscalPeriod($maker, $context, $year->id, [
            'sequence' => 1, 'code' => 'P01', 'name' => 'January 2027',
            'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31',
        ]);

        $customer = Customer::query()->create([
            'type' => 'b2b',
            'name' => $prefix.' Customer',
            'email' => strtolower($prefix).'@example.test',
        ]);
        $receivables = app(AccountsReceivableService::class);
        $document = $receivables->createDraft($maker, $context, [
            'idempotency_key' => $prefix.'-ar-doc',
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'entry_date' => '2027-01-05',
            'due_date' => '2027-02-05',
            'amount' => '100.000',
            'currency_code' => 'KWD',
            'receivable_account_id' => $receivable->id,
            'offset_account_id' => $revenue->id,
        ]);
        $receivables->post($poster, $posterContext, $document->id);

        return [
            'store' => $store,
            'maker' => $maker,
            'poster' => $poster,
            'document' => $document->fresh(),
            'cash_account_id' => $cash->id,
        ];
    }

    private function retailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }

    private function userForStore(Store $store, string $code): User
    {
        $role = Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail();
        /** @var User $user */
        $user = User::factory()->create([
            'email' => strtolower($code).'@example.test',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $this->grantPlatformErpAccess($user);
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function enableFinance(User $actor, ResolvedTenantContext $context): void
    {
        $scope = $context->enterpriseScope;
        $this->assertNotNull($scope);
        $registry = app(ModuleRegistry::class);
        if (! $registry->isEnabled(FinanceFoundationService::MODULE)) {
            $lifecycle = app(ModuleLifecycleManager::class);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLING, $actor);
            $lifecycle->transition(FinanceFoundationService::MODULE, ModuleState::ENABLED, $actor);
        }

        app(ModuleEntitlementManager::class)->set(
            FinanceFoundationService::MODULE,
            new EntitlementScope(EntitlementScope::LEGAL_ENTITY, $scope->legalEntityId),
            true,
            [
                'finance.foundation.read',
                'finance.foundation.manage',
                'finance.gl.read',
                'finance.gl.manage',
                'finance.gl.post',
                'finance.ar.read',
                'finance.ar.manage',
                'finance.ar.post',
            ],
            $actor,
        );
    }
}
