<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Enterprise\Procurement\ProcurementCompletionManager;
use App\Enterprise\Procurement\PurchaseOrderManager;
use App\Enterprise\Procurement\PurchaseRequestManager;
use App\Enterprise\Procurement\SupplierDirectory;
use App\Enterprise\Reporting\Jobs\ProcessReportExportChunk;
use App\Enterprise\Reporting\ReportBuilderExecutor;
use App\Enterprise\Reporting\ReportQuery;
use App\Enterprise\Reporting\ReportRegistry;
use App\Enterprise\Reporting\ScheduledReportManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ResolvedTenantContext;
use App\Support\TenantContextResolver;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ProcurementSecondApprover;
use Tests\Support\SyntheticErpWorkflowEvidence;
use Tests\TestCase;

final class EnterpriseProcurementCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticErpWorkflowEvidence::install();
    }

    public function test_scoped_rfq_search_preserves_literal_prefix_and_title_punctuation_without_mutation(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-RFQ-LITERAL');
        [$otherActor, , $otherContext] = $this->scopedUser($this->allPermissions(), 'PROC-RFQ-FOREIGN');
        [$roleless, , $rolelessContext] = $this->scopedUser([], 'PROC-RFQ-ROLELESS');

        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);

        // Build genuine submitted Purchase Requests and their canonical RFQs.
        // Only the generated external numbers are replaced with synthetic
        // punctuation fixtures; production identities and write paths are not used.
        $make = function (
            User $owner,
            ResolvedTenantContext $ownerContext,
            string $rfqNumber,
            string $requestNumber,
            string $title
        ) use ($requests, $completion): int {
            $request = $requests->create($owner, $ownerContext, [
                'currency_code' => 'KWD',
                'reason' => 'V2 RFQ literal search regression',
                'lines' => [[
                    'item_code' => 'RFQ-LITERAL-ITEM',
                    'description' => 'Scoped RFQ search',
                    'quantity' => 1,
                    'unit' => 'each',
                    'estimated_unit_cost' => 2.000,
                ]],
            ]);
            $requests->submit($owner, $ownerContext, $request->id);
            $rfq = $completion->createRfq($owner, $ownerContext, $request->id, [
                'title' => $title,
            ]);
            DB::table('enterprise_purchase_requests')->where('id', $request->id)
                ->update(['request_number' => $requestNumber]);
            DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)
                ->update(['rfq_number' => $rfqNumber]);

            return (int) $rfq->id;
        };

        $numberId = $make($actor, $context, '%!_RFQ-01', 'REQ-LIT-01', 'Ordinary quotation');
        $titleId = $make($actor, $context, 'RFQ-TITLE-02', 'REQ-LIT-02', 'Vendor Ops_%!\\-Tier');
        $requestId = $make($actor, $context, 'RFQ-REQUEST-03', '_!%REQ-03', 'Source pricing');
        $decoyId = $make($actor, $context, 'RFQ-NORMAL-04', 'REQ-NORMAL-04', 'Vendor OpsABCD-Tier');
        $make($otherActor, $otherContext, '%!_RFQ-FOREIGN', '_!%REQ-FOREIGN', 'Vendor Ops_%!\\-Foreign');

        DB::table('enterprise_procurement_rfqs')->where('id', $titleId)->update(['status' => 'cancelled']);
        $before = [
            'rfqs' => DB::table('enterprise_procurement_rfqs')->count(),
            'requests' => DB::table('enterprise_purchase_requests')->count(),
            'quotes' => DB::table('enterprise_procurement_quotes')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        $matching = fn (string $q, array $other = []): array => $completion
            ->paginateRfqs($actor, $context, ['q' => $q] + $other)
            ->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame([$numberId], $matching('%!_'));
        $this->assertSame([$titleId], $matching('Ops_%!\\'));
        $this->assertSame([$requestId], $matching('_!%REQ'));
        $this->assertEqualsCanonicalizing([$numberId, $titleId], $matching('%'));
        $this->assertEqualsCanonicalizing([$titleId, $requestId], $matching('_'));
        $this->assertSame([$titleId], $matching('!'));
        $this->assertSame([$titleId], $matching('\\'));
        $this->assertSame([], $matching('OpsAB%D'));
        $this->assertSame([$decoyId], $matching('RFQ-NORMAL'));
        $this->assertSame([], $matching('Ops_%!\\', ['status' => 'draft']));
        $this->assertSame([$titleId], $matching('Ops_%!\\', ['status' => 'cancelled']));
        $this->assertCount(4, $matching(''));
        $this->assertSame(10, $completion->paginateRfqs($actor, $context, [], 1)->perPage());
        $this->assertSame(100, $completion->paginateRfqs($actor, $context, [], 1000)->perPage());

        try {
            $completion->paginateRfqs($roleless, $rolelessContext, ['q' => '%']);
            $this->fail('A role without RFQ read permission must be denied at the service boundary.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, [
            'rfqs' => DB::table('enterprise_procurement_rfqs')->count(),
            'requests' => DB::table('enterprise_purchase_requests')->count(),
            'quotes' => DB::table('enterprise_procurement_quotes')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ]);
    }

    public function test_scoped_supplier_contract_search_preserves_literal_numbers_titles_and_supplier_codes(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-CTR-LITERAL');
        [$foreignActor, , $foreignContext] = $this->scopedUser($this->allPermissions(), 'PROC-CTR-FOREIGN');
        [$roleless, , $rolelessContext] = $this->scopedUser([], 'PROC-CTR-ROLELESS');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);

        $make = function (
            User $owner,
            ResolvedTenantContext $ownerContext,
            string $contractNumber,
            string $supplierCode,
            string $title,
            string $status = 'draft',
            bool $expired = false,
        ) use ($directory, $completion): int {
            $supplier = $directory->create($owner, $ownerContext, [
                'scope_type' => 'store',
                'code' => 'CT-LITERAL-'.strtoupper(substr(hash('sha256', $contractNumber), 0, 12)),
                'name_en' => 'Contract search fixture',
            ]);
            $contract = $completion->createContract($owner, $ownerContext, [
                'supplier_id' => $supplier->id,
                'title' => $title,
                'currency_code' => 'KWD',
                'starts_on' => now()->subDays(30)->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
                'value_ceiling' => 20,
                'quantity_ceiling' => 5,
            ]);
            // Synthetic business references can contain punctuation even when
            // UI creation restricts fresh identifiers. Read filtering must be
            // correct for imported and historically valid legacy references.
            DB::table('enterprise_suppliers')->where('id', $supplier->id)
                ->update(['code' => $supplierCode]);
            DB::table('enterprise_procurement_contracts')->where('id', $contract->id)
                ->update([
                    'contract_number' => $contractNumber,
                    'status' => $status,
                    'ends_on' => $expired
                        ? now()->subDay()->toDateString()
                        : now()->addDays(30)->toDateString(),
                ]);

            return (int) $contract->id;
        };

        $numberId = $make($actor, $context, '%!_CTR-01', 'SUP-CTR-01', 'Ordinary agreement');
        $titleId = $make($actor, $context, 'CTR-TITLE-02', 'SUP-CTR-02', 'Annual Ops_%!\\-Tier', 'terminated');
        $supplierId = $make($actor, $context, 'CTR-SUP-03', '_%!SUP-03', 'Supplier agreement', 'active');
        $expiredId = $make($actor, $context, 'CTR-EXPIRED-04', 'SUP-CTR-04', 'Expired agreement', 'active', true);
        $decoyId = $make($actor, $context, 'CTR-NORMAL-05', 'SUP-NORMAL-05', 'Annual OpsABCD-Tier');
        $make($foreignActor, $foreignContext, '%!_CTR-FOREIGN', '_%!SUP-FOREIGN', 'Annual Ops_%!\\-Foreign');

        $before = [
            'contracts' => DB::table('enterprise_procurement_contracts')->count(),
            'suppliers' => DB::table('enterprise_suppliers')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];
        $matching = fn (string $q, array $more = []): array => $completion
            ->paginateContracts($actor, $context, ['q' => $q] + $more)
            ->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $this->assertSame([$numberId], $matching('%!_'));
        $this->assertSame([$titleId], $matching('Ops_%!\\'));
        $this->assertSame([$supplierId], $matching('_%!SUP'));
        $this->assertEqualsCanonicalizing([$numberId, $titleId], $matching('%'));
        $this->assertEqualsCanonicalizing([$titleId, $supplierId], $matching('_'));
        $this->assertSame([$titleId], $matching('!'));
        $this->assertSame([$titleId], $matching('\\'));
        $this->assertSame([], $matching('OpsAB%D'));
        $this->assertSame([$decoyId], $matching('CTR-NORMAL'));
        $this->assertSame([], $matching('Ops_%!\\', ['status' => 'draft']));
        $this->assertSame([$titleId], $matching('Ops_%!\\', ['status' => 'terminated']));
        $this->assertSame([$supplierId], $matching('_%!SUP', ['status' => 'active']));
        $this->assertSame([$expiredId], $matching('CTR-EXPIRED', ['status' => 'expired']));
        $this->assertSame([], $matching('CTR-EXPIRED', ['status' => 'active']));
        $this->assertCount(5, $matching(''));
        $this->assertSame(10, $completion->paginateContracts($actor, $context, [], 1)->perPage());
        $this->assertSame(100, $completion->paginateContracts($actor, $context, [], 1000)->perPage());

        try {
            $completion->paginateContracts($roleless, $rolelessContext, ['q' => '%']);
            $this->fail('A role without supplier contract read permission must get HTTP 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, [
            'contracts' => DB::table('enterprise_procurement_contracts')->count(),
            'suppliers' => DB::table('enterprise_suppliers')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ]);
    }

    public function test_rfq_portal_quote_award_and_savings_preserve_canonical_procurement_chain(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-COMP-RFQ');

        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-RFQ-SUP',
            'name_en' => 'RFQ Supplier',
            'name_ar' => 'مورد عرض السعر',
        ]);

        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'currency_code' => 'KWD',
            'reason' => 'RFQ lifecycle',
            'lines' => [[
                'item_code' => 'PROC-RFQ-SKU',
                'description' => 'RFQ item',
                'quantity' => 2,
                'unit' => 'case',
                'estimated_unit_cost' => 5.000,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);

        $completion = app(ProcurementCompletionManager::class);
        $rfq = $completion->createRfq($actor, $context, $request->id, [
            'title' => 'RFQ lifecycle proof',
            'response_due_at' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
        $tokens = $completion->publishRfq($actor, $context, $rfq->id, [$supplier->id]);

        $this->assertCount(1, $tokens);
        $token = $tokens[0]['token'];
        $this->assertSame(
            hash('sha256', $token),
            DB::table('enterprise_procurement_rfq_invitations')
                ->where('rfq_id', $rfq->id)
                ->value('token_hash'),
        );
        $this->assertStringNotContainsString(
            $token,
            (string) DB::table('enterprise_procurement_rfq_invitations')
                ->where('rfq_id', $rfq->id)
                ->value('token_hash'),
        );

        $this->get(route('supplier.rfq.show', ['token' => $token]))
            ->assertOk()
            ->assertSee($rfq->rfq_number);

        $lineId = (int) DB::table('enterprise_procurement_rfq_lines')
            ->where('rfq_id', $rfq->id)
            ->value('id');

        $this->post(route('supplier.rfq.submit', ['token' => $token]), [
            'valid_until' => now()->addDays(14)->toDateString(),
            'lead_time_days' => 3,
            'notes' => 'Supplier quote',
            'attachment' => UploadedFile::fake()->create('supplier-quote.pdf', 12, 'application/pdf'),
            'lines' => [[
                'rfq_line_id' => $lineId,
                'unit_price' => 4.000,
                'lead_time_days' => 3,
            ]],
        ])->assertRedirect();

        $quote = DB::table('enterprise_procurement_quotes')
            ->where('rfq_id', $rfq->id)
            ->first();
        $this->assertNotNull($quote);
        $this->assertSame('submitted', $quote->status);
        $this->assertSame('8.000', number_format((float) $quote->total_amount, 3, '.', ''));

        $quoteDocument = DB::table('enterprise_procurement_quote_documents')
            ->where('quote_id', $quote->id)
            ->first();
        $this->assertNotNull($quoteDocument);
        Storage::disk('local')->assertExists($quoteDocument->storage_path);

        try {
            $completion->awardRfq($actor, $context, $rfq->id, $quote->id);
            $this->fail('The RFQ creator must not award their own request.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('decision', $exception->errors());
        }
        $this->assertDatabaseHas('enterprise_procurement_rfqs', [
            'id' => $rfq->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseMissing('enterprise_purchase_orders', [
            'procurement_rfq_id' => $rfq->id,
        ]);

        $order = $completion->awardRfq($checker, $checkerContext, $rfq->id, $quote->id);
        $this->assertSame($supplier->id, (int) $order->supplier_id);
        $this->assertSame($rfq->id, (int) $order->procurement_rfq_id);
        $this->assertSame($quote->id, (int) $order->awarded_quote_id);
        $this->assertDatabaseHas('enterprise_purchase_order_lines', [
            'purchase_order_id' => $order->id,
            'unit_price' => 4.000,
        ]);

        $savings = $completion->report($actor, $context, 'savings', [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);
        $this->assertCount(1, $savings['rows']);
        $this->assertSame('2', rtrim(rtrim(number_format((float) $savings['rows'][0]['savings'], 3, '.', ''), '0'), '.'));

        $dashboard = $completion->dashboard($actor, $context, [
            'from' => now()->subDay()->toDateString(),
            'to' => now()->addDay()->toDateString(),
        ]);
        $this->assertArrayHasKey('spend_by_category', $dashboard['charts']);
        $this->assertArrayHasKey('po_aging', $dashboard['charts']);
        $this->assertNotEmpty($dashboard['charts']['savings']);
    }

    public function test_contract_ceiling_commitment_private_documents_and_cancel_release_are_enforced(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-COMP-CTR');

        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-CTR-SUP',
            'name_en' => 'Contract Supplier',
        ]);
        $order = $this->approvedOrder($actor, $context, $supplier->id, 2, 5.000);

        $completion = app(ProcurementCompletionManager::class);
        // The real admin HTTP route is guarded by ERP readiness + Dashboard
        // global entry, independently of the service's scoped procurement RBAC.
        // Grant both only to this synthetic fixture, never to production roles.
        config()->set(
            'enterprise_module_readiness.certified_versions.procurement',
            app(ModuleRegistry::class)->manifest('procurement')->version,
        );
        $this->grantPlatformErpAccess($actor);
        [$contractChecker, $contractCheckerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $contract = $completion->createContract($actor, $context, [
            'supplier_id' => $supplier->id,
            'title' => 'Annual supply agreement',
            'currency_code' => 'KWD',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDays(30)->toDateString(),
            'value_ceiling' => 20,
            'quantity_ceiling' => 5,
        ]);
        // A creator cannot release their own supplier contract. Validate the
        // real Dashboard HTTP route in both locales, not just a hidden button.
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor)
                ->post(route('admin.erp.procurement.contracts.activate', [
                    'contract' => $contract->id,
                ]), [
                    'store_id' => $store->id,
                ])
                ->assertRedirect()
                ->assertSessionHasErrors([
                    'approver' => __('enterprise_procurement.errors.contract_self_approval'),
                ]);
        }
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contract->id,
            'status' => 'draft',
            'activated_by' => null,
        ]);

        $completion->activateContract($contractChecker, $contractCheckerContext, $contract->id);
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contract->id,
            'status' => 'active',
            'activated_by' => $contractChecker->id,
        ]);
        $completion->assignContractToOrder($actor, $context, $order->id, $contract->id);

        $document = $completion->addContractDocument(
            $actor,
            $context,
            $contract->id,
            ['title' => 'Signed agreement', 'reference' => 'CTR-DOC-1'],
            UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf'),
        );
        $this->assertNotNull($document->storage_path);
        Storage::disk('local')->assertExists($document->storage_path);

        $orders = app(PurchaseOrderManager::class);
        $orders->issue($actor, $context, $order->id);
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contract->id,
            'status' => 'active',
            'committed_value' => 10,
            'committed_quantity' => 2,
        ]);

        $orders->cancel($actor, $context, $order->id);
        $this->assertDatabaseHas('enterprise_procurement_contracts', [
            'id' => $contract->id,
            'committed_value' => 0,
            'committed_quantity' => 0,
        ]);

        [$otherActor, , $otherContext] = $this->scopedUser($this->allPermissions(), 'PROC-COMP-OTHER');
        try {
            $completion->contractDocument($otherActor, $otherContext, $contract->id, $document->id);
            $this->fail('Contract documents must be invisible outside the authorized store scope.');
        } catch (RecordNotFoundException) {
            $this->assertTrue(true);
        }

        $smallContract = $completion->createContract($actor, $context, [
            'supplier_id' => $supplier->id,
            'title' => 'Small ceiling',
            'currency_code' => 'KWD',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDays(30)->toDateString(),
            'value_ceiling' => 9,
        ]);
        $completion->activateContract($contractChecker, $contractCheckerContext, $smallContract->id);
        $secondOrder = $this->approvedOrder($actor, $context, $supplier->id, 2, 5.000);
        $completion->assignContractToOrder($actor, $context, $secondOrder->id, $smallContract->id);

        try {
            $orders->issue($actor, $context, $secondOrder->id);
            $this->fail('Contract value ceilings must fail closed at PO issue.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('procurement_contract_id', $exception->errors());
        }
    }

    public function test_procurement_reports_are_first_class_enterprise_bi_exports_and_schedules(): void
    {
        Queue::fake();
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-COMP-BI');

        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-BI-SUP',
            'name_en' => 'BI Supplier',
        ]);
        $this->approvedOrder($actor, $context, $supplier->id, 3, 2.500);

        $this->enableBi($store);

        $scope = new EntitlementScope(EntitlementScope::STORE, $store->id);
        $result = app(ReportBuilderExecutor::class)->execute(
            $actor,
            'procurement.open_orders',
            [
                'dimensions' => ['order_number', 'supplier_reference', 'status', 'currency'],
                'measures' => ['order_value', 'days_overdue'],
                'group_by' => ['order_number', 'supplier_reference', 'status', 'currency'],
                'per_page' => 25,
            ],
            $scope,
        );

        $this->assertSame('procurement.open_orders', $result['report']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('PROC-BI-SUP', $result['rows'][0]['supplier_reference']);
        $this->assertSame('7.500', $result['rows'][0]['order_value']);

        $export = app(ReportBuilderExecutor::class)->requestExport(
            $actor,
            'procurement.open_orders',
            [
                'dimensions' => ['order_number', 'status'],
                'measures' => ['order_value'],
                'group_by' => ['order_number', 'status'],
            ],
            $scope,
            5001,
            'procurement-large-export',
        );
        $this->assertTrue($export['large_export']);
        Queue::assertPushed(
            ProcessReportExportChunk::class,
            fn (ProcessReportExportChunk $job): bool => $job->exportId === $export['export_id'],
        );

        $definition = app(ReportRegistry::class)->require('procurement.open_orders');
        $query = ReportQuery::fromArray($definition, [
            'dimensions' => ['order_number', 'status'],
            'measures' => ['order_value'],
            'group_by' => ['order_number', 'status'],
        ]);
        $scheduleId = app(ScheduledReportManager::class)->schedule(
            $actor,
            $query,
            $scope,
            1,
            now()->addHour(),
            1440,
            'procurement-daily-open-orders',
        );
        $this->assertDatabaseHas('enterprise_scheduled_reports', [
            'id' => $scheduleId,
            'report_code' => 'procurement.open_orders',
            'scope_type' => EntitlementScope::STORE,
            'scope_id' => $store->id,
            'enabled' => true,
        ]);
    }

    public function test_completion_migration_is_reversible_without_leaving_parallel_authority(): void
    {
        $migration = require database_path(
            'migrations/2026_10_17_123500_complete_enterprise_procurement_module.php',
        );

        $this->assertTrue(Schema::hasTable('enterprise_procurement_rfqs'));
        $this->assertTrue(Schema::hasTable('enterprise_procurement_contracts'));
        $this->assertTrue(Schema::hasTable('enterprise_procurement_match_reviews'));
        $this->assertTrue(Schema::hasColumns('enterprise_purchase_orders', [
            'procurement_contract_id',
            'procurement_rfq_id',
            'awarded_quote_id',
        ]));

        $migration->down();

        $this->assertFalse(Schema::hasTable('enterprise_procurement_rfqs'));
        $this->assertFalse(Schema::hasTable('enterprise_procurement_contracts'));
        $this->assertFalse(Schema::hasTable('enterprise_procurement_match_reviews'));
        $this->assertFalse(Schema::hasColumn('enterprise_purchase_orders', 'procurement_contract_id'));
        $this->assertFalse(Schema::hasColumn('enterprise_suppliers', 'qualification_status'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('enterprise_procurement_quote_documents'));
        $this->assertTrue(Schema::hasTable('enterprise_procurement_contract_documents'));
        $this->assertTrue(Schema::hasColumns('enterprise_suppliers', [
            'qualification_status',
            'blocked_reason',
            'blocked_at',
        ]));
    }

    private function enableProcurement(): void
    {
        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('procurement', ModuleState::ENABLING);
        $lifecycle->transition('procurement', ModuleState::ENABLED);
    }

    private function enableBi(Store $store): void
    {
        app(ModuleRegistry::class)->syncManifest('enterprise_bi');

        $lifecycle = app(ModuleLifecycleManager::class);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLING);
        $lifecycle->transition('enterprise_bi', ModuleState::ENABLED);

        app(ModuleEntitlementManager::class)->set(
            'enterprise_bi',
            new EntitlementScope(EntitlementScope::STORE, $store->id),
            true,
            ['reports.read', 'reports.export'],
        );
    }

    private function approvedOrder(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        float $quantity,
        float $unitPrice,
    ) {
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'supplier_id' => $supplierId,
            'currency_code' => 'KWD',
            'needed_by' => now()->addDays(5)->toDateString(),
            'reason' => 'Completion test source',
            'lines' => [[
                'item_code' => 'CMP-'.substr(md5((string) microtime(true)), 0, 8),
                'description' => 'Completion item',
                'quantity' => $quantity,
                'unit' => 'each',
                'estimated_unit_cost' => $unitPrice,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $requests->approve($checker, $checkerContext, $request->id);

        return app(PurchaseOrderManager::class)->createFromApprovedRequest($actor, $context, $request->id);
    }

    /** @return list<string> */
    private function allPermissions(): array
    {
        return [
            'procurement.suppliers.view',
            'procurement.suppliers.manage',
            'procurement.purchase_requests.view',
            'procurement.purchase_requests.manage',
            'procurement.purchase_requests.approve',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.manage',
            'procurement.purchase_orders.issue',
            'procurement.goods_receipts.view',
            'procurement.goods_receipts.manage',
            'procurement.goods_receipts.post',
            'procurement.purchase_invoices.view',
            'procurement.purchase_invoices.manage',
            'procurement.purchase_invoices.handoff',
            'procurement.purchase_returns.view',
            'procurement.purchase_returns.manage',
            'procurement.purchase_returns.post',
            'procurement.supplier_statements.view',
            'reports.view',
            'reports.export',
        ];
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User,Store,ResolvedTenantContext}
     */
    private function scopedUser(array $permissions, string $storeCode): array
    {
        $role = Role::query()->create([
            'code' => 'ROLE_'.str_replace('-', '_', $storeCode),
            'name' => $storeCode,
            'scope' => 'store',
            'is_active' => true,
            'is_system' => false,
        ]);

        foreach ($permissions as $permissionCode) {
            $permission = Permission::query()->firstOrCreate(
                ['code' => $permissionCode],
                ['name' => $permissionCode],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create(['is_active' => true]);
        $store = $this->createRetailStore($storeCode);

        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $user,
            $store,
            app(TenantContextResolver::class)->retail($user, $store->id),
        ];
    }

    private function createRetailStore(string $code): Store
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        if ($typeId < 1) {
            $typeId = (int) DB::table('store_types')->insertGetId([
                'code' => 'B2C',
                'name' => 'Retail',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
        app(OrganizationHierarchyService::class)->ensureDefaultScopeForStore($store);

        return $store;
    }
}
