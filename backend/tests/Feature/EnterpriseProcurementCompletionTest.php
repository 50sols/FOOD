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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_rfq_pagination_never_leaks_transferred_source_request_or_late_moved_rfq_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P135-RFQ-PAGE-LIVE');
        $foreignStore = $this->createRetailStore('P135-RFQ-PAGE-OTHER');
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $injected = false;
        $mode = '';
        $rfqId = null;
        $requestId = null;
        DB::listen(static function ($query) use (
            $foreignStore, &$armed, &$injected, &$mode, &$rfqId, &$requestId
        ): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected || $rfqId === null || $requestId === null
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_rfqs')
                || ! str_contains($sql, 'enterprise_purchase_requests')
                || ! str_contains($sql, 'order by')) {
                return;
            }

            // Deterministic post-page-select interleaving, NOT an independently
            // validated multi-session MySQL concurrency guarantee.
            $armed = false;
            $injected = true;
            $table = $mode === 'rfq'
                ? 'enterprise_procurement_rfqs'
                : 'enterprise_purchase_requests';
            $id = $mode === 'rfq' ? $rfqId : $requestId;
            DB::table($table)->where('id', $id)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'P135 scoped RFQ page '.$locale,
                'lines' => [[
                    'item_code' => 'P135-RFQ-PAGE-'.strtoupper($locale),
                    'description' => 'RFQ source transfer boundary',
                    'quantity' => 1,
                    'unit' => 'each',
                    'estimated_unit_cost' => 2.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Protected quotation '.$locale,
            ]);
            $filter = ['q' => $rfq->rfq_number];
            $this->assertSame(1, $completion->paginateRfqs($actor, $context, $filter)->total());

            $rfqId = (int) $rfq->id;
            $requestId = (int) $request->id;
            $auditBefore = DB::table('audit_logs')->count();
            $quotesBefore = DB::table('enterprise_procurement_quotes')->count();
            $rfqsBefore = DB::table('enterprise_procurement_rfqs')->count();
            foreach (['rfq', 'request'] as $mode) {
                $injected = false;
                $armed = true;
                try {
                    $completion->paginateRfqs($actor, $context, $filter);
                    $this->fail('A stale RFQ or foreign source request must not return joined data.');
                } catch (HttpException $exception) {
                    $this->assertTrue($injected, 'Actual RFQ page SQL must trigger transfer.');
                    $this->assertSame(404, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                // A transfer before the SELECT must also hide the joined
                // source request; no stale supplier or request number is safe.
                $this->assertSame(0, $completion->paginateRfqs($actor, $context, $filter)->total());
                DB::table($mode === 'rfq' ? 'enterprise_procurement_rfqs' : 'enterprise_purchase_requests')
                    ->where('id', $mode === 'rfq' ? $rfqId : $requestId)
                    ->update(['store_id' => $store->id]);

                $this->assertSame(1, $completion->paginateRfqs($actor, $context, $filter)->total());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame($quotesBefore, DB::table('enterprise_procurement_quotes')->count());
                $this->assertSame($rfqsBefore, DB::table('enterprise_procurement_rfqs')->count());
            }
        }
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

    public function test_contract_list_and_options_refuse_moved_supplier_scope_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P135-CTR-SUPPLIER-LIVE');
        $foreignStore = $this->createRetailStore('P135-CTR-SUPPLIER-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $moved = false;
        $targetSupplierId = null;
        DB::listen(static function ($query) use (&$armed, &$moved, &$targetSupplierId, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $moved || $targetSupplierId === null
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_contracts')
                || ! str_contains($sql, 'enterprise_suppliers')
                || ! str_contains($sql, 'order by')) {
                return;
            }
            // Simulate a concurrent persisted supplier transfer immediately
            // AFTER the scoped row SELECT, before the terminal live-owner check.
            // This does not claim an independent two-session MySQL race proof.
            $armed = false;
            $moved = true;
            DB::table('enterprise_suppliers')->where('id', $targetSupplierId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-CTR-SUPPLIER-'.strtoupper($locale),
                'name_en' => 'Private supplier contract '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Supplier contract scope '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            // The live selector exposes active agreements only. Fixture-only
            // status transition avoids unrelated checker workflow side effects.
            DB::table('enterprise_procurement_contracts')->where('id', $contract->id)
                ->update(['status' => 'active']);
            $filter = ['q' => $contract->contract_number];
            $targetSupplierId = (int) $supplier->id;
            $this->assertSame(1, $completion->paginateContracts($actor, $context, $filter)->total());
            $options = $completion->activeContractOptions($actor, $context, $supplier->id);
            $this->assertCount(1, $options);
            $this->assertSame((int) $contract->id, (int) $options[0]->id);
            $auditBefore = DB::table('audit_logs')->count();
            $contractsBefore = DB::table('enterprise_procurement_contracts')->count();

            foreach (['page', 'options'] as $read) {
                $moved = false;
                $armed = true;
                try {
                    if ($read === 'page') {
                        $completion->paginateContracts($actor, $context, $filter);
                    } else {
                        $completion->activeContractOptions($actor, $context, $supplier->id);
                    }
                    $this->fail('Contract supplier foreign-store transfer must block stale joined financial data.');
                } catch (HttpException $exception) {
                    $this->assertTrue($moved, 'Supplier movement must follow the actual joined SELECT.');
                    $this->assertSame(404, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }
                $this->assertSame(0, $completion->paginateContracts($actor, $context, $filter)->total());
                $this->assertSame([], $completion->activeContractOptions($actor, $context, $supplier->id));
                $this->assertDatabaseHas('enterprise_suppliers', [
                    'id' => $supplier->id,
                    'store_id' => $foreignStore->id,
                ]);
                DB::table('enterprise_suppliers')->where('id', $supplier->id)
                    ->update(['store_id' => $store->id]);
                $this->assertSame(1, $completion->paginateContracts($actor, $context, $filter)->total());
                $this->assertCount(1, $completion->activeContractOptions($actor, $context, $supplier->id));
            }
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($contractsBefore, DB::table('enterprise_procurement_contracts')->count());
            $targetSupplierId = null;
        }
    }

    public function test_contract_page_rejects_late_store_transfer_and_persisted_view_revoke_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P135-CTR-PAGE-LIVE');
        $foreignStore = $this->createRetailStore('P135-CTR-PAGE-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);
        $permissionId = Permission::query()->where('code', 'procurement.purchase_orders.view')->firstOrFail()->id;

        $armed = false;
        $injected = false;
        $mode = '';
        $targetContractId = null;
        DB::listen(static function ($query) use (
            $actor, $permissionId, $foreignStore, &$armed, &$injected, &$mode, &$targetContractId
        ): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected || $targetContractId === null
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_contracts')
                || ! str_contains($sql, 'order by')) {
                return;
            }

            // Deliberate post-SELECT same-connection interleaving. This is
            // not independently verified two-session MySQL concurrency.
            $armed = false;
            $injected = true;
            if ($mode === 'transfer') {
                DB::table('enterprise_procurement_contracts')
                    ->where('id', $targetContractId)
                    ->update(['store_id' => $foreignStore->id]);
            } else {
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $actor->id,
                    'permission_id' => $permissionId,
                    'scope_key' => 'global',
                    'store_id' => null,
                    'effect' => 'deny',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => true]);
            }
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-CTR-PAGE-'.strtoupper($locale),
                'name_en' => 'Safe contract pagination '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Protected store contract '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $filter = ['q' => $contract->contract_number];
            $this->assertSame(1, $completion->paginateContracts($actor, $context, $filter)->total());

            $targetContractId = $contract->id;
            $auditBefore = DB::table('audit_logs')->count();
            $contractCount = DB::table('enterprise_procurement_contracts')->count();
            foreach (['transfer', 'revoke'] as $mode) {
                $injected = false;
                $armed = true;
                try {
                    $completion->paginateContracts($actor, $context, $filter);
                    $this->fail('Late loss of store scope or VIEW must never return cached contract financial data.');
                } catch (HttpException $exception) {
                    $this->assertTrue($injected, 'The denial must be injected after actual contract page SQL.');
                    $this->assertSame($mode === 'transfer' ? 404 : 403, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                if ($mode === 'transfer') {
                    $this->assertSame(0, $completion->paginateContracts($actor, $context, $filter)->total());
                    DB::table('enterprise_procurement_contracts')
                        ->where('id', $contract->id)
                        ->update(['store_id' => $store->id]);
                } else {
                    $this->assertFalse((bool) $actor->has_permission_overrides);
                    $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                    try {
                        $completion->paginateContracts($actor, $context, $filter);
                        $this->fail('Persisted VIEW DENY must block repeated reads.');
                    } catch (HttpException $exception) {
                        $this->assertSame(403, $exception->getStatusCode());
                    }
                    DB::table('user_permission_overrides')
                        ->where('user_id', $actor->id)
                        ->where('permission_id', $permissionId)
                        ->delete();
                    DB::table('users')->where('id', $actor->id)
                        ->update(['has_permission_overrides' => false]);
                }
                $this->assertSame($contractCount, DB::table('enterprise_procurement_contracts')->count());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame(1, $completion->paginateContracts($actor, $context, $filter)->total());
            }
            // Do not pollute the next locale's contract match.
            DB::table('enterprise_procurement_contracts')
                ->where('id', $contract->id)
                ->update(['store_id' => $foreignStore->id]);
        }
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

    public function test_supplier_governance_rechecks_module_shutdown_after_preflight_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-SUP-GOV-SHUTDOWN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'PROC-GOV-SHUTDOWN-'.strtoupper($locale),
                'name_en' => 'Shutdown Governance Supplier '.$locale,
            ]);
            $before = DB::table('enterprise_suppliers')->where('id', $supplier->id)->first();
            $auditBefore = DB::table('audit_logs')->count();
            $shutdownAfterPreflight = false;

            // Revoke the module immediately after ModuleRegistry's preflight
            // SELECT returned its enabled registration. The write transaction
            // must independently lock/recheck state before supplier mutation.
            DB::listen(static function ($query) use (&$shutdownAfterPreflight): void {
                $sql = strtolower((string) $query->sql);
                if ($shutdownAfterPreflight
                    || ! str_contains($sql, 'enterprise_modules')
                    || ! str_contains($sql, 'select')) {
                    return;
                }

                $shutdownAfterPreflight = true;
                DB::table('enterprise_modules')->where('code', 'procurement')
                    ->update(['state' => ModuleState::DISABLED->value]);
            });

            $attributes = [
                'qualification_status' => 'qualified',
                'blocked' => true,
                'blocked_reason' => 'Governance shutdown regression',
            ];
            try {
                $completion->updateSupplierGovernance($actor, $context, $supplier->id, $attributes);
                $this->fail('Supplier governance cannot write after procurement shuts down.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertTrue($shutdownAfterPreflight, 'Must disable after the enabled preflight.');
            $unchanged = DB::table('enterprise_suppliers')->where('id', $supplier->id)->first();
            $this->assertSame($before->status, $unchanged->status);
            $this->assertSame($before->qualification_status, $unchanged->qualification_status);
            $this->assertSame($before->blocked_reason, $unchanged->blocked_reason);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);
            $updated = $completion->updateSupplierGovernance($actor, $context, $supplier->id, $attributes);
            $this->assertSame('on_hold', $updated->status);
            $this->assertSame('qualified', $updated->qualification_status);
            $this->assertSame('Governance shutdown regression', $updated->blocked_reason);
            $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
        }
    }

    public function test_contract_assignment_rechecks_procurement_shutdown_after_preflight_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-CTR-ASSIGN-LIVE');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-CTR-ASSIGN-SUP',
            'name_en' => 'Contract Assignment Supplier',
        ]);
        $completion = app(ProcurementCompletionManager::class);
        $contract = $completion->createContract($actor, $context, [
            'supplier_id' => $supplier->id,
            'title' => 'Assignment lifecycle proof',
            'currency_code' => 'KWD',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addDays(30)->toDateString(),
        ]);
        [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
        $completion->activateContract($checker, $checkerContext, $contract->id);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $order = $this->approvedOrder($actor, $context, $supplier->id, 1, 2.000);
            $beforeAudit = DB::table('audit_logs')->count();
            $shutdownAfterPreflight = false;

            // ModuleRegistry's first SELECT supplies the enabled preflight.
            // Revoke it just after that SELECT returns; the final transaction
            // must independently lock/recheck state before linking the order.
            DB::listen(static function ($query) use (&$shutdownAfterPreflight): void {
                $sql = strtolower((string) $query->sql);
                if ($shutdownAfterPreflight
                    || ! str_contains($sql, 'enterprise_modules')
                    || ! str_contains($sql, 'select')) {
                    return;
                }

                $shutdownAfterPreflight = true;
                DB::table('enterprise_modules')->where('code', 'procurement')
                    ->update(['state' => ModuleState::DISABLED->value]);
            });

            try {
                $completion->assignContractToOrder($actor, $context, $order->id, $contract->id);
                $this->fail('Contract assignment must reject post-preflight procurement shutdown.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }

            $this->assertTrue($shutdownAfterPreflight, 'Test must revoke the module after the preflight SELECT.');
            $this->assertNull(DB::table('enterprise_purchase_orders')
                ->where('id', $order->id)->value('procurement_contract_id'));
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);
            $completion->assignContractToOrder($actor, $context, $order->id, $contract->id);
            $this->assertSame((int) $contract->id, (int) DB::table('enterprise_purchase_orders')
                ->where('id', $order->id)->value('procurement_contract_id'));
            $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
        }
    }

    public function test_rfq_detail_denies_late_foreign_source_request_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P135-RFQ-DETAIL-REQUEST-SCOPE');
        $foreignStore = $this->createRetailStore('P135-RFQ-DETAIL-REQUEST-OTHER');
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $injected = false;
        $requestId = null;
        DB::listen(static function ($query) use (&$armed, &$injected, &$requestId, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (! $armed || $injected || $requestId === null
                || ! str_contains($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_rfqs')
                || str_contains($sql, 'join')) {
                return;
            }
            // The linked request moves AFTER the initial authorized RFQ
            // SELECT; this is deterministic same-connection interleaving,
            // not evidence of an atomic two-session MySQL guarantee.
            $armed = false;
            $injected = true;
            DB::table('enterprise_purchase_requests')->where('id', $requestId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'RFQ detail linked request authorization '.$locale,
                'lines' => [[
                    'item_code' => 'RFQ-SOURCE-DETAIL-'.strtoupper($locale),
                    'description' => 'Protected linked source',
                    'quantity' => 1,
                    'unit' => 'case',
                    'estimated_unit_cost' => 5.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Private RFQ with independently owned source '.$locale,
            ]);
            $this->assertSame((int) $rfq->id, (int) $completion->rfqDetail($actor, $context, $rfq->id)['rfq']->id);
            $beforeAudit = DB::table('audit_logs')->count();
            $beforeQuotes = DB::table('enterprise_procurement_quotes')->count();

            $requestId = (int) $request->id;
            $injected = false;
            $armed = true;
            try {
                $completion->rfqDetail($actor, $context, $rfq->id);
                $this->fail('RFQ details must never expose a request transferred after the initial SELECT.');
            } catch (RecordNotFoundException $exception) {
                $this->assertTrue($injected);
            } finally {
                $armed = false;
            }
            $this->assertDatabaseHas('enterprise_purchase_requests', [
                'id' => $request->id,
                'store_id' => $foreignStore->id,
            ]);
            try {
                $completion->rfqDetail($actor, $context, $rfq->id);
                $this->fail('Repeated reads cannot expose a foreign source request.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            DB::table('enterprise_purchase_requests')->where('id', $request->id)
                ->update(['store_id' => $store->id]);
            $this->assertSame((int) $rfq->id, (int) $completion->rfqDetail($actor, $context, $rfq->id)['rfq']->id);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
            $this->assertSame($beforeQuotes, DB::table('enterprise_procurement_quotes')->count());
            $requestId = null;
        }
    }

    public function test_rfq_detail_rejects_quote_attachment_paths_after_store_transfer_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-RFQ-DETAIL-TRANSFER');
        $foreignStore = $this->createRetailStore('PROC-RFQ-DETAIL-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $moved = false;
        $targetRfqId = null;
        $documentQueries = [];
        // A listener survives both locales. Move the RFQ immediately after
        // the initially authorized RFQ SELECT, before detail children load.
        DB::listen(static function ($query) use (&$armed, &$moved, &$targetRfqId, &$documentQueries, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'enterprise_procurement_quote_documents')
                && str_contains($sql, 'enterprise_procurement_rfqs')
                && str_contains($sql, 'select')
                && str_contains($sql, 'join')) {
                $documentQueries[] = $sql;
            }

            if (! $armed || $moved || $targetRfqId === null
                || ! str_contains($sql, 'enterprise_procurement_rfqs')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'join')) {
                return;
            }

            $armed = false;
            $moved = true;
            DB::table('enterprise_procurement_rfqs')
                ->where('id', $targetRfqId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'PROC-RFQ-DETAIL-'.strtoupper($locale),
                'name_en' => 'RFQ detail attachment supplier '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'RFQ detail attachment store transfer '.$locale,
                'lines' => [[
                    'item_code' => 'RFQ-DETAIL-SKU',
                    'description' => 'Private quote detail',
                    'quantity' => 1,
                    'unit' => 'case',
                    'estimated_unit_cost' => 5.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Private RFQ detail '.$locale,
            ]);
            $invitations = $completion->publishRfq($actor, $context, $rfq->id, [$supplier->id]);
            $lineId = (int) DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)->value('id');

            $this->post(route('supplier.rfq.submit', ['token' => $invitations[0]['token']]), [
                'valid_until' => now()->addDays(14)->toDateString(),
                'attachment' => UploadedFile::fake()->create('private-rfq.pdf', 4, 'application/pdf'),
                'lines' => [[
                    'rfq_line_id' => $lineId,
                    'unit_price' => 4.000,
                ]],
            ])->assertRedirect();

            $quoteId = (int) DB::table('enterprise_procurement_quotes')
                ->where('rfq_id', $rfq->id)->value('id');
            $document = DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $quoteId)->firstOrFail();
            $beforeAudit = DB::table('audit_logs')->count();
            $documentQueries = [];

            $visible = $completion->rfqDetail($actor, $context, $rfq->id);
            $this->assertCount(1, $visible['quotes']);
            $this->assertCount(1, $visible['quoteDocuments']->get($quoteId));
            $this->assertSame($document->storage_path, $visible['quoteDocuments']->get($quoteId)->first()->storage_path);
            $this->assertCount(1, $documentQueries, 'The RFQ detail attachment SELECT must join its current RFQ.');
            $this->assertStringContainsString('organization_id', $documentQueries[0]);
            $this->assertStringContainsString('company_id', $documentQueries[0]);
            $this->assertStringContainsString('store_id', $documentQueries[0]);

            $targetRfqId = (int) $rfq->id;
            $moved = false;
            $armed = true;
            try {
                $completion->rfqDetail($actor, $context, $rfq->id);
                $this->fail('Transferred RFQ details must not expose the old store quote or document.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            } finally {
                $armed = false;
            }
            $this->assertTrue($moved, 'RFQ transfer was not triggered after its initial scoped read.');
            $this->assertDatabaseHas('enterprise_procurement_rfqs', [
                'id' => $rfq->id,
                'store_id' => $foreignStore->id,
            ]);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
            Storage::disk('local')->assertExists($document->storage_path);
            $targetRfqId = null;

            try {
                $completion->rfqDetail($actor, $context, $rfq->id);
                $this->fail('A transferred RFQ must remain hidden from its former store.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_quote_document_download_checks_current_rfq_store_in_one_scoped_query_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-QUOTE-DOC-ATOMIC');
        $foreignStore = $this->createRetailStore('PROC-QUOTE-DOC-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);
        $documentQueries = [];

        // A listener is registered only once: listeners persist across the
        // AR/EN loop, otherwise a shared captured array double-counts reads.
        DB::listen(static function ($query) use (&$documentQueries): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'enterprise_procurement_quote_documents')
                && str_contains($sql, 'enterprise_procurement_quotes')
                && str_contains($sql, 'enterprise_procurement_rfqs')
                && str_contains($sql, 'join')
                && str_contains($sql, 'select')) {
                $documentQueries[] = $sql;
            }
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'PROC-QUOTE-DOC-'.strtoupper($locale),
                'name_en' => 'Scoped RFQ quote supplier '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'Quote attachment scope '.$locale,
                'lines' => [[
                    'item_code' => 'QUOTE-DOC-SKU',
                    'description' => 'Quoted line',
                    'quantity' => 1,
                    'unit' => 'case',
                    'estimated_unit_cost' => 5.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Quote attachment atomic read '.$locale,
            ]);
            $invitations = $completion->publishRfq($actor, $context, $rfq->id, [$supplier->id]);
            $lineId = (int) DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)->value('id');

            $this->post(route('supplier.rfq.submit', ['token' => $invitations[0]['token']]), [
                'valid_until' => now()->addDays(14)->toDateString(),
                'attachment' => UploadedFile::fake()->create('quote.pdf', 4, 'application/pdf'),
                'lines' => [[
                    'rfq_line_id' => $lineId,
                    'unit_price' => 4.000,
                ]],
            ])->assertRedirect();

            $quoteId = (int) DB::table('enterprise_procurement_quotes')
                ->where('rfq_id', $rfq->id)->value('id');
            $document = DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $quoteId)->firstOrFail();
            $auditBefore = DB::table('audit_logs')->count();
            $documentQueries = [];

            $visible = $completion->quoteDocument($actor, $context, $rfq->id, $document->id);
            $this->assertSame((int) $document->id, (int) $visible->id);
            $this->assertSame($document->storage_path, $visible->storage_path);
            Storage::disk('local')->assertExists($visible->storage_path);
            $this->assertCount(2, $documentQueries, 'The first quote-document SELECT and final ownership recheck must both join the current RFQ.');
            $this->assertStringContainsString('organization_id', $documentQueries[0]);
            $this->assertStringContainsString('company_id', $documentQueries[0]);
            $this->assertStringContainsString('store_id', $documentQueries[0]);

            // Moving only the SOURCE request must also hide the attachment;
            // the RFQ itself can still physically reside in this store.
            DB::table('enterprise_purchase_requests')->where('id', $request->id)
                ->update(['store_id' => $foreignStore->id]);
            try {
                $completion->quoteDocument($actor, $context, $rfq->id, $document->id);
                $this->fail('A foreign source request must block quote-document access.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            DB::table('enterprise_purchase_requests')->where('id', $request->id)
                ->update(['store_id' => $store->id]);
            $this->assertSame((int) $document->id, (int) $completion->quoteDocument($actor, $context, $rfq->id, $document->id)->id);

            // A transfer must invalidate the former store's attachment read,
            // without deleting a quoted file or changing the original audit.
            DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)
                ->update(['store_id' => $foreignStore->id]);
            try {
                $completion->quoteDocument($actor, $context, $rfq->id, $document->id);
                $this->fail('A transferred RFQ attachment must not be visible to the former store.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }

            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            Storage::disk('local')->assertExists($visible->storage_path);
            $this->assertDatabaseHas('enterprise_procurement_rfqs', [
                'id' => $rfq->id,
                'store_id' => $foreignStore->id,
            ]);
        }
    }

    public function test_quote_attachment_download_rejects_late_rfq_store_transfer_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $ownStore, $context] = $this->scopedUser($this->allPermissions(), 'P135-QUOTE-LATE-OWNER');
        $foreignStore = $this->createRetailStore('P135-QUOTE-LATE-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $moved = false;
        $targetRfqId = 0;
        // The original SQL has already fetched a private storage path when
        // the callback runs. Only a subsequent live ownership read can stop
        // the in-flight result from being returned to the old store.
        DB::listen(static function ($query) use (
            $foreignStore, &$armed, &$moved, &$targetRfqId
        ): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $moved || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_quote_documents')
                || ! str_contains($sql, 'enterprise_procurement_quotes')
                || ! str_contains($sql, 'enterprise_procurement_rfqs')
                || ! str_contains($sql, 'join')) {
                return;
            }

            $armed = false;
            $moved = true;
            DB::table('enterprise_procurement_rfqs')->where('id', $targetRfqId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-QUOTE-LATE-'.strtoupper($locale),
                'name_en' => 'Private quote attachment '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'Quote late-store transfer '.$locale,
                'lines' => [[
                    'item_code' => 'QUOTE-LATE-'.strtoupper($locale),
                    'description' => 'Quoted private item',
                    'quantity' => 1,
                    'unit' => 'case',
                    'estimated_unit_cost' => 5.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Late-transfer RFQ '.$locale,
            ]);
            $invitations = $completion->publishRfq($actor, $context, $rfq->id, [$supplier->id]);
            $lineId = (int) DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)->value('id');
            $this->post(route('supplier.rfq.submit', ['token' => $invitations[0]['token']]), [
                'valid_until' => now()->addDays(14)->toDateString(),
                'attachment' => UploadedFile::fake()->create('private-quote.pdf', 4, 'application/pdf'),
                'lines' => [[
                    'rfq_line_id' => $lineId,
                    'unit_price' => 4.000,
                ]],
            ])->assertRedirect();

            $quoteId = (int) DB::table('enterprise_procurement_quotes')->where('rfq_id', $rfq->id)->value('id');
            $document = DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $quoteId)->firstOrFail();
            $read = static fn () => $completion->quoteDocument($actor, $context, $rfq->id, $document->id);
            $this->assertSame($document->storage_path, $read()->storage_path);
            $beforeAudit = DB::table('audit_logs')->count();
            $beforeDocuments = DB::table('enterprise_procurement_quote_documents')->count();
            $moved = false;
            $targetRfqId = (int) $rfq->id;
            $armed = true;
            try {
                $read();
                $this->fail('Late RFQ reassignment must not disclose a private quote attachment in '.$locale);
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            } finally {
                $armed = false;
            }
            $this->assertTrue($moved, 'Actual attachment SELECT must trigger a post-query RFQ transfer.');
            $this->assertDatabaseHas('enterprise_procurement_rfqs', [
                'id' => $rfq->id, 'store_id' => $foreignStore->id,
            ]);
            try {
                $read();
                $this->fail('A transferred RFQ must remain unreadable to its former store.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
            $this->assertSame($beforeDocuments, DB::table('enterprise_procurement_quote_documents')->count());
            Storage::disk('local')->assertExists($document->storage_path);

            DB::table('enterprise_procurement_rfqs')->where('id', $rfq->id)
                ->update(['store_id' => $ownStore->id]);
            $this->assertSame($document->storage_path, $read()->storage_path);
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
        }
    }

    public function test_quote_document_download_rejects_replaced_private_storage_path_after_select_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-QUOTE-PATH-IDENTITY');
        $directory = app(SupplierDirectory::class);
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $replaced = false;
        $targetDocumentId = 0;
        $replacementPath = '';
        // The initial SQL returns a previously valid storage_path. Change
        // that SAME document's path after SELECT, but before the final
        // live identity check. A store-only recheck would still incorrectly
        // authorize and return the obsolete private path.
        DB::listen(static function ($query) use (
            &$armed, &$replaced, &$targetDocumentId, &$replacementPath
        ): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $replaced || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_quote_documents')
                || ! str_contains($sql, 'enterprise_procurement_quotes')
                || ! str_contains($sql, 'enterprise_procurement_rfqs')
                || ! str_contains($sql, 'join')) {
                return;
            }

            $armed = false;
            $replaced = true;
            DB::table('enterprise_procurement_quote_documents')
                ->where('id', $targetDocumentId)
                ->update(['storage_path' => $replacementPath]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-QUOTE-ID-'.strtoupper($locale),
                'name_en' => 'Live quote document identity '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'Private document replacement '.$locale,
                'lines' => [[
                    'item_code' => 'P135-DOC-ID-'.strtoupper($locale),
                    'description' => 'Quote path replacement item',
                    'quantity' => 1,
                    'unit' => 'case',
                    'estimated_unit_cost' => 5.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Quote document identity '.$locale,
            ]);
            $invitations = $completion->publishRfq($actor, $context, $rfq->id, [$supplier->id]);
            $lineId = (int) DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)->value('id');
            $this->post(route('supplier.rfq.submit', ['token' => $invitations[0]['token']]), [
                'valid_until' => now()->addDays(14)->toDateString(),
                'attachment' => UploadedFile::fake()->create('private-quote.pdf', 4, 'application/pdf'),
                'lines' => [[
                    'rfq_line_id' => $lineId,
                    'unit_price' => 4.000,
                ]],
            ])->assertRedirect();

            $quoteId = (int) DB::table('enterprise_procurement_quotes')->where('rfq_id', $rfq->id)->value('id');
            $document = DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $quoteId)->firstOrFail();
            $read = static fn () => $completion->quoteDocument($actor, $context, $rfq->id, $document->id);
            $this->assertSame($document->storage_path, $read()->storage_path);
            $originalPath = $document->storage_path;
            $replacementPath = 'supplier-quotes/replaced-'.strtolower($locale).'-'.$document->id.'.pdf';
            Storage::disk('local')->put($replacementPath, 'Replacement belongs to the same tenant and quote.');
            $this->assertTrue(Storage::disk('local')->exists($replacementPath));
            $auditBefore = DB::table('audit_logs')->count();
            $documentCount = DB::table('enterprise_procurement_quote_documents')->count();
            $targetDocumentId = (int) $document->id;
            $replaced = false;
            $armed = true;
            try {
                $read();
                $this->fail('An attachment path replaced during SELECT must never return its stale private path.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            } finally {
                $armed = false;
            }
            $this->assertTrue($replaced, 'Actual document SELECT must trigger a post-query path replacement.');
            $this->assertDatabaseHas('enterprise_procurement_quote_documents', [
                'id' => $document->id,
                'quote_id' => $quoteId,
                'storage_path' => $replacementPath,
            ]);
            $this->assertSame($replacementPath, $read()->storage_path, 'A new call may read the new live path.');
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($documentCount, DB::table('enterprise_procurement_quote_documents')->count());
            Storage::disk('local')->assertExists($originalPath);
            Storage::disk('local')->assertExists($replacementPath);
        }
    }

    public function test_contract_detail_does_not_expose_attachment_paths_after_store_transfer_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-DETAIL-DOC-TRANSFER');
        $foreignStore = $this->createRetailStore('PROC-DETAIL-DOC-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);

        $transferContractId = null;
        $transferred = false;
        $documentQueries = [];
        // Listen once for both locales. Move the contract immediately after
        // the first scoped contract SELECT, before detail reads attachment
        // storage paths. A separate preflight must not authorize those paths.
        DB::listen(static function ($query) use (&$transferContractId, &$transferred, &$documentQueries, $foreignStore): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'enterprise_procurement_contract_documents')
                && str_contains($sql, 'enterprise_procurement_contracts')
                && str_contains($sql, 'join')
                && str_contains($sql, 'select')) {
                $documentQueries[] = $sql;
            }
            if ($transferContractId === null || $transferred
                || ! str_contains($sql, 'enterprise_procurement_contracts')
                || ! str_contains($sql, 'select')
                || str_contains($sql, 'join')) {
                return;
            }

            $transferred = true;
            DB::table('enterprise_procurement_contracts')
                ->where('id', $transferContractId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'PROC-DETAIL-TRANSFER-'.strtoupper($locale),
                'name_en' => 'Detail transfer supplier '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Attachment detail transfer '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $document = $completion->addContractDocument(
                $actor, $context, $contract->id,
                ['title' => 'Private contract detail '.$locale],
                UploadedFile::fake()->create('private-contract.pdf', 4, 'application/pdf'),
            );
            $this->assertNotNull($document->storage_path);
            $auditBefore = DB::table('audit_logs')->count();
            $documentQueries = [];

            $visible = $completion->contractDetail($actor, $context, $contract->id);
            $this->assertCount(1, $visible['documents']);
            $this->assertSame((int) $document->id, (int) $visible['documents'][0]->id);
            $this->assertSame($document->storage_path, $visible['documents'][0]->storage_path);
            $this->assertCount(1, $documentQueries, 'Attachment details must use one current-store JOIN.');
            $this->assertStringContainsString('organization_id', $documentQueries[0]);
            $this->assertStringContainsString('company_id', $documentQueries[0]);
            $this->assertStringContainsString('store_id', $documentQueries[0]);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            $transferContractId = (int) $contract->id;
            $transferred = false;
            $documentQueries = [];
            // The prior implementation returned the stale contract header
            // with an empty document list after the ownership transfer. No
            // contract metadata is now authorized for the former store.
            try {
                $completion->contractDetail($actor, $context, $contract->id);
                $this->fail('Transferred contract details must not return a stale header.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertTrue($transferred, 'Transfer must happen after contract preflight.');
            $this->assertCount(1, $documentQueries);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            Storage::disk('local')->assertExists($document->storage_path);
            $this->assertDatabaseHas('enterprise_procurement_contracts', [
                'id' => $contract->id,
                'store_id' => $foreignStore->id,
            ]);

            $transferContractId = null;
            try {
                $completion->contractDetail($actor, $context, $contract->id);
                $this->fail('A contract transferred to another store must now be 404.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    public function test_contract_detail_and_document_refuse_late_supplier_transfer_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $ownStore, $context] = $this->scopedUser($this->allPermissions(), 'P135-CTR-DETAIL-SUPPLIER');
        $foreignStore = $this->createRetailStore('P135-CTR-DETAIL-SUPPLIER-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $moved = false;
        $supplierId = 0;
        DB::listen(static function ($query) use (&$armed, &$moved, &$supplierId, $foreignStore): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $moved || $supplierId === 0
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_contract_documents')
                || ! str_contains($sql, 'enterprise_procurement_contracts')
                || ! str_contains($sql, 'join')) {
                return;
            }

            // Persist a transfer AFTER the private document SELECT. This is
            // a same-connection regression, not a multi-session race proof.
            $armed = false;
            $moved = true;
            DB::table('enterprise_suppliers')->where('id', $supplierId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-DETAIL-SUP-'.strtoupper($locale),
                'name_en' => 'Private document supplier '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Sensitive supplier contract '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $document = $completion->addContractDocument(
                $actor, $context, $contract->id,
                ['title' => 'Private attachment '.$locale],
                UploadedFile::fake()->create('private.pdf', 4, 'application/pdf'),
            );
            $supplierId = (int) $supplier->id;
            $detail = fn (): array => $completion->contractDetail($actor, $context, $contract->id);
            $attachment = fn (): object => $completion->contractDocument($actor, $context, $contract->id, $document->id);
            $this->assertSame($document->storage_path, $detail()['documents'][0]->storage_path);
            $this->assertSame($document->storage_path, $attachment()->storage_path);
            $auditBefore = DB::table('audit_logs')->count();
            $docCount = DB::table('enterprise_procurement_contract_documents')->count();

            foreach (['detail', 'attachment'] as $read) {
                $moved = false;
                $armed = true;
                try {
                    if ($read === 'detail') {
                        $detail();
                    } else {
                        $attachment();
                    }
                    $this->fail('Stale private contract data must not escape a supplier transfer.');
                } catch (ModelNotFoundException|RecordNotFoundException $exception) {
                    $this->assertNotEmpty($exception->getMessage());
                } finally {
                    $armed = false;
                }
                $this->assertTrue($moved, 'Supplier must move after the scoped document SELECT.');
                foreach ([$detail, $attachment] as $readAgain) {
                    try {
                        $readAgain();
                        $this->fail('A foreign-store supplier must deny repeated contract reads.');
                    } catch (ModelNotFoundException|RecordNotFoundException $exception) {
                        $this->assertNotEmpty($exception->getMessage());
                    }
                }
                DB::table('enterprise_suppliers')->where('id', $supplier->id)
                    ->update(['store_id' => $ownStore->id]);
                $this->assertSame($document->storage_path, $detail()['documents'][0]->storage_path);
                $this->assertSame($document->storage_path, $attachment()->storage_path);
            }

            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($docCount, DB::table('enterprise_procurement_contract_documents')->count());
            Storage::disk('local')->assertExists($document->storage_path);
            $supplierId = 0;
        }
    }

    public function test_contract_document_download_reads_live_store_and_document_in_one_scoped_query_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-DOC-READ-ATOMIC');
        $foreignStore = $this->createRetailStore('PROC-DOC-READ-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);
        $documentQueries = [];

        // Register a single listener across both locales. Registering inside
        // the loop leaves prior callbacks attached and double-counts the
        // English query through their shared by-reference collection.
        DB::listen(static function ($query) use (&$documentQueries): void {
            $sql = strtolower((string) $query->sql);
            // Module readiness may independently SELECT from this table.
            // Count only the document-identity lookup that joins its
            // current contract, rather than unrelated preflight probes.
            if (str_contains($sql, 'enterprise_procurement_contract_documents')
                && str_contains($sql, 'enterprise_procurement_contracts')
                && str_contains($sql, 'join')
                && str_contains($sql, 'select')) {
                $documentQueries[] = $sql;
            }
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'PROC-DOC-READ-'.strtoupper($locale),
                'name_en' => 'Scoped document download supplier '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Atomic attachment read '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $document = $completion->addContractDocument(
                $actor,
                $context,
                $contract->id,
                ['title' => 'Owned contract file '.$locale],
                UploadedFile::fake()->create('owned.pdf', 4, 'application/pdf'),
            );
            $auditBefore = DB::table('audit_logs')->count();
            $documentQueries = [];

            $visible = $completion->contractDocument($actor, $context, $contract->id, $document->id);
            $this->assertSame((int) $document->id, (int) $visible->id);
            $this->assertNotNull($visible->storage_path);
            $this->assertTrue(Storage::disk('local')->exists($visible->storage_path));
            $this->assertCount(2, $documentQueries, 'Attachment identity and final contract ownership must each resolve in a current-store joined SELECT.');
            $this->assertStringContainsString('join', $documentQueries[0]);
            $this->assertStringContainsString('enterprise_procurement_contracts', $documentQueries[0]);
            $this->assertStringContainsString('organization_id', $documentQueries[0]);
            $this->assertStringContainsString('company_id', $documentQueries[0]);
            $this->assertStringContainsString('store_id', $documentQueries[0]);

            // An existing document remains on disk but must become unreadable
            // to the former store immediately after the canonical transfer.
            DB::table('enterprise_procurement_contracts')
                ->where('id', $contract->id)
                ->update(['store_id' => $foreignStore->id]);
            try {
                $completion->contractDocument($actor, $context, $contract->id, $document->id);
                $this->fail('A transferred contract attachment must not be readable by its former store.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }

            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertTrue(Storage::disk('local')->exists($visible->storage_path));
            $this->assertDatabaseHas('enterprise_procurement_contracts', [
                'id' => $contract->id,
                'store_id' => $foreignStore->id,
            ]);
        }
    }

    public function test_contract_attachment_download_denies_late_store_transfer_or_path_replacement_in_ar_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $ownStore, $context] = $this->scopedUser($this->allPermissions(), 'P135-CTR-DOC-LATE-OWNER');
        $foreignStore = $this->createRetailStore('P135-CTR-DOC-LATE-FOREIGN');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);

        $armed = false;
        $injected = false;
        $mode = '';
        $targetContractId = 0;
        $targetDocumentId = 0;
        $newPath = '';
        // Interleave a persisted ownership or attachment identity change
        // after the real joined private-path SELECT, before the final recheck.
        // A single preflight JOIN + actor reauth would leak the stale path.
        DB::listen(static function ($query) use (
            $foreignStore, &$armed, &$injected, &$mode,
            &$targetContractId, &$targetDocumentId, &$newPath
        ): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $injected || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_contract_documents')
                || ! str_contains($sql, 'enterprise_procurement_contracts')
                || ! str_contains($sql, 'join')) {
                return;
            }

            $armed = false;
            $injected = true;
            if ($mode === 'transfer') {
                DB::table('enterprise_procurement_contracts')
                    ->where('id', $targetContractId)
                    ->update(['store_id' => $foreignStore->id]);
            } else {
                DB::table('enterprise_procurement_contract_documents')
                    ->where('id', $targetDocumentId)
                    ->update(['storage_path' => $newPath]);
            }
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-CTR-DOC-LATE-'.strtoupper($locale),
                'name_en' => 'Contract private attachment '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Late attachment transfer '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $document = $completion->addContractDocument(
                $actor, $context, $contract->id,
                ['title' => 'Private contract attachment '.$locale],
                UploadedFile::fake()->create('private-contract.pdf', 4, 'application/pdf'),
            );
            $read = static fn () => $completion->contractDocument($actor, $context, $contract->id, $document->id);
            $this->assertSame($document->storage_path, $read()->storage_path);
            $oldPath = $document->storage_path;
            $auditBefore = DB::table('audit_logs')->count();
            $documentCount = DB::table('enterprise_procurement_contract_documents')->count();
            $targetContractId = (int) $contract->id;
            $targetDocumentId = (int) $document->id;

            $mode = 'transfer';
            $injected = false;
            $armed = true;
            try {
                $read();
                $this->fail('Transferred contract must not disclose stale private attachment path.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            } finally {
                $armed = false;
            }
            $this->assertTrue($injected, 'Scoped document SELECT must trigger contract transfer.');
            try {
                $read();
                $this->fail('Repeated reads from the former store must be denied.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertDatabaseHas('enterprise_procurement_contracts', [
                'id' => $contract->id, 'store_id' => $foreignStore->id,
            ]);
            DB::table('enterprise_procurement_contracts')->where('id', $contract->id)
                ->update(['store_id' => $ownStore->id]);
            $this->assertSame($oldPath, $read()->storage_path);

            $newPath = 'private-contracts/replaced-'.strtolower($locale).'-'.$document->id.'.pdf';
            Storage::disk('local')->put($newPath, 'New same-store private contract document.');
            $mode = 'path';
            $injected = false;
            $armed = true;
            try {
                $read();
                $this->fail('Replaced contract document path must not leak obsolete file data.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            } finally {
                $armed = false;
            }
            $this->assertTrue($injected, 'Scoped document SELECT must trigger path replacement.');
            $this->assertDatabaseHas('enterprise_procurement_contract_documents', [
                'id' => $document->id, 'contract_id' => $contract->id, 'storage_path' => $newPath,
            ]);
            $this->assertSame($newPath, $read()->storage_path);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($documentCount, DB::table('enterprise_procurement_contract_documents')->count());
            Storage::disk('local')->assertExists($oldPath);
            Storage::disk('local')->assertExists($newPath);
        }
    }

    public function test_contract_document_rechecks_live_scope_after_preflight_and_removes_orphan_upload(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-CTR-DOC-SCOPE');
        $otherStore = $this->createRetailStore('PROC-CTR-DOC-OTHER');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-CTR-DOC-SUP',
            'name_en' => 'Scoped Document Supplier',
        ]);
        $completion = app(ProcurementCompletionManager::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Contract document scope '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $beforeDocuments = DB::table('enterprise_procurement_contract_documents')->count();
            $beforeAudit = DB::table('audit_logs')->count();
            $changed = false;

            // The production upload flow calls guessExtension() AFTER the
            // visibleContract preflight and BEFORE its locked write transaction.
            // Inject a scoped transfer exactly at that seam. A generic SQL
            // listener can accidentally fire on an unrelated SELECT instead.
            $fixture = UploadedFile::fake()->create('scope-proof.pdf', 4, 'application/pdf');
            $attachment = new class($fixture->getPathname(), $fixture->getClientOriginalName(), static function () use (&$changed, $contract, $otherStore): void {
                $changed = true;
                DB::table('enterprise_procurement_contracts')
                    ->where('id', $contract->id)
                    ->update(['store_id' => $otherStore->id]);
            },
            ) extends UploadedFile {

                public function __construct(string $path, string $name, private readonly \Closure $transfer)
                {
                    parent::__construct($path, $name, 'application/pdf', null, true);
                }

                public function guessExtension(): ?string
                {
                    ($this->transfer)();

                    return 'pdf';
                }
            };

            try {
                $completion->addContractDocument(
                    $actor,
                    $context,
                    $contract->id,
                    ['title' => 'Must reject stale contract scope'],
                    $attachment,
                );
                $this->fail('A transferred contract must reject document registration.');
            } catch (RecordNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }

            $this->assertTrue($changed, 'Race simulation must execute on preflight.');
            $this->assertSame($beforeDocuments, DB::table('enterprise_procurement_contract_documents')->count());
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());
            $this->assertSame(
                [],
                Storage::disk('local')->allFiles('procurement/contracts/'.$contract->public_id),
                'A rejected registration must delete the uploaded document.',
            );
            $this->assertDatabaseHas('enterprise_procurement_contracts', [
                'id' => $contract->id,
                'store_id' => $otherStore->id,
            ]);
        }
    }

    public function test_contract_creation_never_writes_when_procurement_module_is_disabled_in_both_locales(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'PROC-CTR-MODULE');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-CTR-MODULE-SUP',
            'name_en' => 'Module Lifecycle Supplier',
        ]);
        $completion = app(ProcurementCompletionManager::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::DISABLED->value]);
            $contractsBefore = DB::table('enterprise_procurement_contracts')->count();
            $auditBefore = DB::table('audit_logs')->count();

            try {
                $completion->createContract($actor, $context, [
                    'supplier_id' => $supplier->id,
                    'title' => 'Disabled module contract '.$locale,
                    'currency_code' => 'KWD',
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addDays(30)->toDateString(),
                ]);
                $this->fail('Disabled procurement cannot create supplier contracts.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
            $this->assertSame($contractsBefore, DB::table('enterprise_procurement_contracts')->count());
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('enterprise_modules')->where('code', 'procurement')
                ->update(['state' => ModuleState::ENABLED->value]);
            $created = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Enabled module contract '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $this->assertSame((int) $supplier->id, (int) $created->supplier_id);
            $this->assertSame($contractsBefore + 1, DB::table('enterprise_procurement_contracts')->count());
            $this->assertSame($auditBefore + 1, DB::table('audit_logs')->count());
        }
    }

    public function test_contract_creation_revalidates_live_supplier_scope_and_status_in_both_locales(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-CTR-LIVE');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'PROC-CTR-LIVE-SUP',
            'name_en' => 'Live Contract Supplier',
        ]);
        $foreignStore = $this->createRetailStore('PROC-CTR-FOREIGN');
        $completion = app(ProcurementCompletionManager::class);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $beforeContracts = DB::table('enterprise_procurement_contracts')->count();
            $beforeAudit = DB::table('audit_logs')->count();

            // The original supplier model may still say active, but the
            // canonical DB row must be read again before any contract write.
            DB::table('enterprise_suppliers')->where('id', $supplier->id)
                ->update(['status' => 'on_hold']);
            try {
                $completion->createContract($actor, $context, [
                    'supplier_id' => $supplier->id,
                    'title' => 'Disallowed held supplier '.$locale,
                    'currency_code' => 'KWD',
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addDays(30)->toDateString(),
                ]);
                $this->fail('A held supplier cannot back a new contract.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('supplier_id', $exception->errors());
            }
            $this->assertSame($beforeContracts, DB::table('enterprise_procurement_contracts')->count());
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

            DB::table('enterprise_suppliers')->where('id', $supplier->id)
                ->update(['status' => 'active', 'store_id' => $foreignStore->id, 'scope_type' => 'store']);
            try {
                $completion->createContract($actor, $context, [
                    'supplier_id' => $supplier->id,
                    'title' => 'Disallowed moved supplier '.$locale,
                    'currency_code' => 'KWD',
                    'starts_on' => now()->toDateString(),
                    'ends_on' => now()->addDays(30)->toDateString(),
                ]);
                $this->fail('A supplier outside the selected store scope cannot be contracted.');
            } catch (ModelNotFoundException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
            $this->assertSame($beforeContracts, DB::table('enterprise_procurement_contracts')->count());
            $this->assertSame($beforeAudit, DB::table('audit_logs')->count());

            DB::table('enterprise_suppliers')->where('id', $supplier->id)
                ->update(['store_id' => $store->id]);
            $created = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Valid supplier '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $this->assertSame((int) $supplier->id, (int) $created->supplier_id);
            $this->assertSame($beforeContracts + 1, DB::table('enterprise_procurement_contracts')->count());
            $this->assertSame($beforeAudit + 1, DB::table('audit_logs')->count());
        }
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

    public function test_procurement_reports_require_exact_source_document_permission_without_leaking_other_modules(): void
    {
        $this->enableProcurement();
        [$statementsActor, , $statementContext] = $this->scopedUser(
            ['procurement.supplier_statements.view', 'reports.export'],
            'P135-REPORT-STATEMENT-ONLY',
        );
        $completion = app(ProcurementCompletionManager::class);
        $sourcePermissions = [
            'open_orders' => 'procurement.purchase_orders.view',
            'supplier_performance' => 'procurement.purchase_orders.view',
            'contract_expiry' => 'procurement.purchase_orders.view',
            'spend' => 'procurement.purchase_invoices.view',
            'match_exceptions' => 'procurement.purchase_invoices.view',
            'savings' => 'procurement.purchase_requests.view',
        ];

        foreach (array_keys($sourcePermissions) as $report) {
            try {
                $completion->report($statementsActor, $statementContext, $report);
                $this->fail('Statement read/export must never reveal '.$report.' without its document permission.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        [$ordersActor, , $ordersContext] = $this->scopedUser(
            ['procurement.supplier_statements.view', 'procurement.purchase_orders.view'],
            'P135-REPORT-ORDERS-ONLY',
        );
        foreach (['open_orders', 'supplier_performance', 'contract_expiry'] as $report) {
            $result = $completion->report($ordersActor, $ordersContext, $report);
            $this->assertArrayHasKey('columns', $result);
            $this->assertSame([], $result['rows']);
        }
        foreach (['spend', 'match_exceptions', 'savings'] as $report) {
            try {
                $completion->report($ordersActor, $ordersContext, $report);
                $this->fail('Purchase order access is not blanket invoice/RFQ report authority: '.$report);
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        [$invoicesActor, , $invoicesContext] = $this->scopedUser(
            ['procurement.supplier_statements.view', 'procurement.purchase_invoices.view'],
            'P135-REPORT-INVOICES-ONLY',
        );
        foreach (['spend', 'match_exceptions'] as $report) {
            $this->assertSame([], $completion->report($invoicesActor, $invoicesContext, $report)['rows']);
        }
        foreach (['open_orders', 'savings'] as $report) {
            try {
                $completion->report($invoicesActor, $invoicesContext, $report);
                $this->fail('Invoice viewer must not see order/RFQ report: '.$report);
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        [$requestActor, , $requestContext] = $this->scopedUser(
            ['procurement.supplier_statements.view', 'procurement.purchase_requests.view'],
            'P135-REPORT-RFQ-ONLY',
        );
        $this->assertSame([], $completion->report($requestActor, $requestContext, 'savings')['rows']);

        [$noStatementActor, , $noStatementContext] = $this->scopedUser(
            ['procurement.purchase_orders.view', 'reports.export'],
            'P135-REPORT-NO-ENTRY',
        );
        try {
            $completion->report($noStatementActor, $noStatementContext, 'open_orders');
            $this->fail('Report document permission without the canonical Reports entry is insufficient.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, DB::table('enterprise_purchase_orders')->count());
        $this->assertSame(0, DB::table('enterprise_purchase_invoices')->count());
    }

    public function test_procurement_report_and_bi_bridge_ignore_foreign_company_rows_with_same_store_id(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser(
            $this->allPermissions(),
            'P135-REPORT-COMPANY-A',
        );
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P135-REPORT-ONLY-A',
            'name_en' => 'Scoped supplier',
        ]);
        $visible = $this->approvedOrder($actor, $context, $supplier->id, 2, 1.250);
        $foreignCompanyRow = $this->approvedOrder($actor, $context, $supplier->id, 3, 9.000);

        // A legacy/imported row may reference a valid store FK yet belong to
        // another legal company. A store-only aggregate must not count it.
        $foreignCompanyId = DB::table('enterprise_companies')->insertGetId([
            'organization_id' => $context->enterpriseScope->organizationId,
            'code' => 'p135_report_foreign_company',
            'name' => 'Unrelated Company',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('enterprise_purchase_orders')
            ->where('id', $foreignCompanyRow->id)
            ->update(['company_id' => $foreignCompanyId]);

        $manager = app(ProcurementCompletionManager::class);
        $report = $manager->report($actor, $context, 'open_orders');
        $this->assertSame([$visible->order_number], array_column($report['rows'], 'order_number'));
        $this->assertSame('2.500', $report['rows'][0]['order_value']);

        // The trusted BI bridge inherits the store's authoritative company
        // hierarchy even when a BI export is paged or invoked independently.
        $bridge = $manager->reportForStore('open_orders', $store->id, [], 25);
        $this->assertSame([$visible->order_number], array_column($bridge['rows'], 'order_number'));
        $this->assertSame('2.500', $bridge['rows'][0]['order_value']);

        // Dashboard KPI and status chart must inherit the same company/org
        // boundary. A row with an authorized store ID but foreign company ID
        // must not inflate even a read-only aggregate.
        $dashboard = $manager->dashboard($actor, $context);
        $openOrderMetric = collect($dashboard['kpis'])->firstWhere('key', 'open_orders');
        $this->assertNotNull($openOrderMetric);
        $this->assertSame(1, $openOrderMetric['value']);
        $statusChart = collect($dashboard['charts']['po_status'])->keyBy('label');
        $this->assertSame(1, $statusChart['draft']['value']);

        [$otherActor, , $otherContext] = $this->scopedUser(
            $this->allPermissions(),
            'P135-REPORT-COMPANY-B',
        );
        $this->assertSame([], $manager->report($otherActor, $otherContext, 'open_orders')['rows']);

        try {
            $manager->reportForStore(
                'open_orders', $store->id, [], 25, 0, $otherContext->enterpriseScope,
            );
            $this->fail('A caller cannot substitute a foreign tenant scope into a BI report.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(2, DB::table('enterprise_purchase_orders')->count());
        $this->assertSame(0, DB::table('enterprise_purchase_invoices')->count());
    }

    public function test_ordered_not_received_kpi_counts_partial_posted_grns_without_multiplying_po_lines(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser(
            $this->allPermissions(), 'P135-PARTIAL-REPORT',
        );
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P135-PARTIAL-SUP',
            'name_en' => 'Partial delivery supplier',
        ]);
        $order = $this->approvedOrder($actor, $context, $supplier->id, 5, 1.250);
        $orderLine = $order->lines()->firstOrFail();

        // Report-specific SQL fixture: status and GRN rows are deterministic.
        // This tests aggregation only; it is not evidence of physical posting.
        DB::table('enterprise_purchase_orders')->where('id', $order->id)->update([
            'status' => 'issued',
            'issued_by' => $actor->id,
            'issued_at' => now(),
        ]);
        $warehouseId = DB::table('warehouses')->insertGetId([
            'store_id' => $store->id,
            'code' => 'P135-PARTIAL-WH',
            'name' => 'Partial Receiving',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scope = $context->enterpriseScope;
        $addReceipt = function (string $number, string $status, string $received) use (
            $actor, $scope, $store, $order, $orderLine, $warehouseId
        ): void {
            $receiptId = DB::table('enterprise_goods_receipts')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'receipt_number' => $number,
                'purchase_order_id' => $order->id,
                'source_order_number' => $order->order_number,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $store->id,
                'supplier_id' => $order->supplier_id,
                'supplier_code' => $order->supplier_code,
                'supplier_name_en' => $order->supplier_name_en,
                'currency_code' => $order->currency_code,
                'status' => $status,
                'created_by' => $actor->id,
                'posted_by' => $status === 'posted' ? $actor->id : null,
                'posted_at' => $status === 'posted' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('enterprise_goods_receipt_lines')->insert([
                'goods_receipt_id' => $receiptId,
                'purchase_order_line_id' => $orderLine->id,
                'line_no' => 1,
                'item_code' => $orderLine->item_code,
                'description' => $orderLine->description,
                'ordered_quantity' => $orderLine->quantity,
                'received_quantity' => $received,
                'unit' => $orderLine->unit,
                'unit_price' => $orderLine->unit_price,
                'warehouse_id' => $warehouseId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };
        $manager = app(ProcurementCompletionManager::class);
        $outstanding = static function () use ($manager, $actor, $context): int {
            $dashboard = $manager->dashboard($actor, $context);

            return (int) collect($dashboard['kpis'])
                ->firstWhere('key', 'ordered_not_received')['value'];
        };

        $this->assertSame(1, $outstanding());
        $addReceipt('P135-DRAFT-IGNORED', 'draft', '5.000');
        $this->assertSame(1, $outstanding());
        $addReceipt('P135-PARTIAL-2', 'posted', '2.000');
        $this->assertSame(1, $outstanding());
        $addReceipt('P135-PARTIAL-3', 'posted', '3.000');
        $this->assertSame(0, $outstanding());
        $this->assertSame(0, DB::table('stock_movements')->count());
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

    public function test_procurement_completion_reloads_persisted_rbac_for_direct_reads_reports_and_kpis_in_both_locales(): void
    {
        $this->enableProcurement();
        $completion = app(ProcurementCompletionManager::class);
        $codes = ['procurement.purchase_requests.view', 'procurement.purchase_orders.view'];

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'PROC-RBAC-LIVE-'.strtoupper($locale));
            $this->assertFalse((bool) $actor->has_permission_overrides);

            // Real allowed operations in the same Retail store and module:
            // the empty collection and zero KPI are authorized, not hidden.
            $this->assertSame(0, $completion->paginateRfqs($actor, $context)->total());
            $this->assertArrayHasKey('rows', $completion->report($actor, $context, 'open_orders'));
            $initialDashboard = $completion->dashboard($actor, $context);
            $initialOrders = collect($initialDashboard['kpis'])->firstWhere('key', 'open_orders');
            $this->assertNotNull($initialOrders);
            $this->assertNotNull($initialOrders['value']);

            $auditBefore = DB::table('audit_logs')->count();
            foreach ($codes as $code) {
                $permissionId = Permission::query()->where('code', $code)->value('id');
                $this->assertNotNull($permissionId);
                DB::table('user_permission_overrides')->insert([
                    'user_id' => $actor->id,
                    'permission_id' => $permissionId,
                    'scope_key' => 'global',
                    'store_id' => null,
                    'effect' => 'deny',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('users')->where('id', $actor->id)->update(['has_permission_overrides' => true]);

            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            foreach ([
                'rfq_list' => fn () => $completion->paginateRfqs($actor, $context),
                'open_orders_report' => fn () => $completion->report($actor, $context, 'open_orders'),
            ] as $operation => $read) {
                try {
                    $read();
                    $this->fail("A persisted DENY must prevent {$operation} for a stale actor.");
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }

            // Supplier overview remains permitted, but a stale purchase-order
            // permission cannot disclose order/finance KPI or chart values.
            $dashboard = $completion->dashboard($actor, $context);
            $orders = collect($dashboard['kpis'])->firstWhere('key', 'open_orders');
            $this->assertNotNull($orders);
            $this->assertNull($orders['value']);
            $this->assertSame([], $dashboard['charts']['po_status']);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());

            DB::table('user_permission_overrides')->where('user_id', $actor->id)->delete();
            DB::table('users')->where('id', $actor->id)->update(['has_permission_overrides' => false]);

            // Existing same stale User object becomes authorized again only
            // when the persisted DENY is actually removed.
            $this->assertSame(0, $completion->paginateRfqs($actor, $context)->total());
            $this->assertArrayHasKey('rows', $completion->report($actor, $context, 'open_orders'));
            $restored = collect($completion->dashboard($actor, $context)['kpis'])->firstWhere('key', 'open_orders');
            $this->assertNotNull($restored['value']);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame((int) $store->id, (int) $context->storeId);
        }
    }

    public function test_rfq_reads_deny_persisted_permission_revoked_during_sql_in_both_locales(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-RFQ-MIDREAD-DENY');
        $requests = app(PurchaseRequestManager::class);
        $request = $requests->create($actor, $context, [
            'currency_code' => 'KWD',
            'reason' => 'RFQ read revocation regression',
            'lines' => [[
                'item_code' => 'P135-RFQ-MIDREAD',
                'description' => 'RFQ supplier privacy scope',
                'quantity' => 1,
                'unit' => 'each',
                'estimated_unit_cost' => 1.250,
            ]],
        ]);
        $requests->submit($actor, $context, $request->id);
        $completion = app(ProcurementCompletionManager::class);
        $rfq = $completion->createRfq($actor, $context, $request->id, [
            'title' => 'RFQ midread permission revocation',
        ]);
        $permission = Permission::query()
            ->where('code', 'procurement.purchase_requests.view')
            ->firstOrFail();

        $armed = false;
        $injected = false;
        DB::listen(static function ($query) use ($actor, $permission, &$armed, &$injected): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $injected || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_rfqs')) {
                return;
            }

            // A legitimate scoped RFQ SELECT has completed, but the actor's
            // effective permission changes before service data is returned.
            $armed = false;
            $injected = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permission->id,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['list', 'detail'] as $surface) {
                $read = static fn () => $surface === 'list'
                    ? $completion->paginateRfqs($actor, $context)
                    : $completion->rfqDetail($actor, $context, (int) $rfq->id);

                $positive = $read();
                if ($surface === 'list') {
                    $this->assertSame(1, $positive->total());
                } else {
                    $this->assertSame((int) $rfq->id, (int) $positive['rfq']->id);
                }

                $auditBefore = DB::table('audit_logs')->count();
                $requestsBefore = DB::table('enterprise_purchase_requests')->count();
                $rfqsBefore = DB::table('enterprise_procurement_rfqs')->count();
                $injected = false;
                $armed = true;
                try {
                    $read();
                    $this->fail('Midquery persisted RFQ read DENY must reject the data response.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                $this->assertTrue($injected, 'Scoped RFQ SELECT must trigger the persisted DENY.');
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                try {
                    $read();
                    $this->fail('Persisted RFQ view DENY must also reject the next read.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
                $this->assertSame($rfqsBefore, DB::table('enterprise_procurement_rfqs')->count());

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permission->id)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertFalse((bool) $actor->fresh()->has_permission_overrides);
                $this->assertNotNull($read());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            }
        }
    }

    public function test_contract_attachment_download_denies_midquery_persisted_view_revocation_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-CTR-PATH-REVOKE');
        $directory = app(SupplierDirectory::class);
        $completion = app(ProcurementCompletionManager::class);
        $permissionId = Permission::query()->where('code', 'procurement.purchase_orders.view')->firstOrFail()->id;
        $armed = false;
        $revoked = false;

        DB::listen(static function ($query) use ($actor, $permissionId, &$armed, &$revoked): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $revoked || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_contract_documents')
                || ! str_contains($sql, 'enterprise_procurement_contracts')
                || ! str_contains($sql, 'join')) {
                return;
            }

            $armed = false;
            $revoked = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-CTR-PATH-'.strtoupper($locale),
                'name_en' => 'Contract path revocation '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Protected path '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $document = $completion->addContractDocument(
                $actor,
                $context,
                $contract->id,
                ['title' => 'Protected attachment '.$locale],
                UploadedFile::fake()->create('contract.pdf', 4, 'application/pdf'),
            );
            $read = static fn () => $completion->contractDocument($actor, $context, $contract->id, $document->id);
            $this->assertSame((int) $document->id, (int) $read()->id);
            $this->assertTrue(Storage::disk('local')->exists($document->storage_path));
            $auditBefore = DB::table('audit_logs')->count();
            $documentCount = DB::table('enterprise_procurement_contract_documents')->count();

            $revoked = false;
            $armed = true;
            try {
                $read();
                $this->fail('A revoked contract viewer must not receive private attachment storage paths.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } finally {
                $armed = false;
            }
            $this->assertTrue($revoked, 'Actual scoped contract attachment SELECT must trigger DENY.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            try {
                $read();
                $this->fail('Repeated reads must deny persisted contract view permission loss.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($documentCount, DB::table('enterprise_procurement_contract_documents')->count());
            Storage::disk('local')->assertExists($document->storage_path);
            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)->where('permission_id', $permissionId)->delete();
            DB::table('users')->where('id', $actor->id)->update(['has_permission_overrides' => false]);
            $this->assertSame((int) $document->id, (int) $read()->id);
        }
    }

    public function test_quote_attachment_download_denies_midquery_persisted_view_revocation_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-QUOTE-PATH-REVOKE');
        $directory = app(SupplierDirectory::class);
        $requests = app(PurchaseRequestManager::class);
        $completion = app(ProcurementCompletionManager::class);
        $permissionId = Permission::query()->where('code', 'procurement.purchase_requests.view')->firstOrFail()->id;
        $armed = false;
        $revoked = false;

        DB::listen(static function ($query) use ($actor, $permissionId, &$armed, &$revoked): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $revoked || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_procurement_quote_documents')
                || ! str_contains($sql, 'enterprise_procurement_rfqs')
                || ! str_contains($sql, 'join')) {
                return;
            }

            $armed = false;
            $revoked = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-QUOTE-PATH-'.strtoupper($locale),
                'name_en' => 'Quote path supplier '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'Protected quote attachment '.$locale,
                'lines' => [[
                    'item_code' => 'QUOTE-PATH-SKU',
                    'description' => 'Quoted goods',
                    'quantity' => 1,
                    'unit' => 'case',
                    'estimated_unit_cost' => 5.000,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $requests->approve($checker, $checkerContext, $request->id);
            $rfq = $completion->createRfq($actor, $context, $request->id, [
                'title' => 'Protected quotation '.$locale,
            ]);
            $invitations = $completion->publishRfq($actor, $context, $rfq->id, [$supplier->id]);
            $lineId = (int) DB::table('enterprise_procurement_rfq_lines')
                ->where('rfq_id', $rfq->id)->value('id');
            $this->post(route('supplier.rfq.submit', ['token' => $invitations[0]['token']]), [
                'valid_until' => now()->addDays(14)->toDateString(),
                'attachment' => UploadedFile::fake()->create('quote.pdf', 4, 'application/pdf'),
                'lines' => [[
                    'rfq_line_id' => $lineId,
                    'unit_price' => 4.000,
                ]],
            ])->assertRedirect();
            $quoteId = (int) DB::table('enterprise_procurement_quotes')->where('rfq_id', $rfq->id)->value('id');
            $document = DB::table('enterprise_procurement_quote_documents')
                ->where('quote_id', $quoteId)->firstOrFail();
            $read = static fn () => $completion->quoteDocument($actor, $context, $rfq->id, $document->id);
            $this->assertSame((int) $document->id, (int) $read()->id);
            Storage::disk('local')->assertExists($document->storage_path);
            $auditBefore = DB::table('audit_logs')->count();
            $documentCount = DB::table('enterprise_procurement_quote_documents')->count();

            $revoked = false;
            $armed = true;
            try {
                $read();
                $this->fail('A revoked RFQ viewer must not receive private quote attachment storage paths.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } finally {
                $armed = false;
            }
            $this->assertTrue($revoked, 'Actual scoped quote attachment SELECT must trigger DENY.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            try {
                $read();
                $this->fail('Repeated reads must deny persisted RFQ view permission loss.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($documentCount, DB::table('enterprise_procurement_quote_documents')->count());
            Storage::disk('local')->assertExists($document->storage_path);
            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)->where('permission_id', $permissionId)->delete();
            DB::table('users')->where('id', $actor->id)->update(['has_permission_overrides' => false]);
            $this->assertSame((int) $document->id, (int) $read()->id);
        }
    }

    public function test_dashboard_denies_supplier_view_revoked_during_final_exception_query_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-DASH-SUP-REVOKE');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P135-DASH-SUP-REVOKE',
            'name_en' => 'Supplier exception security fixture',
        ]);
        DB::table('enterprise_suppliers')->where('id', $supplier->id)->update(['status' => 'on_hold']);

        $manager = app(ProcurementCompletionManager::class);
        $permissionId = Permission::query()
            ->where('code', 'procurement.suppliers.view')->firstOrFail()->id;
        $armed = false;
        $injected = false;

        // Inject a persisted revoke AFTER the final supplier exception COUNT
        // SELECT has executed, not during the initial permission preflight.
        DB::listen(static function ($query) use ($actor, $permissionId, &$armed, &$injected): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $injected || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_suppliers')
                || ! str_contains($sql, 'count(')) {
                return;
            }

            $injected = true;
            $armed = false;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $visible = $manager->dashboard($actor, $context);
            $this->assertSame(1, collect($visible['exceptions'])->firstWhere('key', 'blocked_suppliers')['count']);
            $auditBefore = DB::table('audit_logs')->count();
            $injected = false;
            $armed = true;

            try {
                $manager->dashboard($actor, $context);
                $this->fail('Revoking supplier VIEW during exception SQL must abort the in-flight dashboard.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } finally {
                $armed = false;
            }

            $this->assertTrue($injected, 'Real supplier exception COUNT SQL must trigger persisted DENY.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);

            // Unlike invoice VIEW, supplier VIEW is the required baseScope
            // for the entire procurement dashboard. Repeated requests must
            // fail closed, not reveal even otherwise granted PO indicators.
            try {
                $manager->dashboard($actor, $context);
                $this->fail('Persisted supplier VIEW DENY must reject repeated dashboard access.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame('on_hold', DB::table('enterprise_suppliers')
                ->where('id', $supplier->id)->value('status'));

            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)->where('permission_id', $permissionId)->delete();
            DB::table('users')->where('id', $actor->id)->update(['has_permission_overrides' => false]);
            $this->assertSame(1, collect($manager->dashboard($actor, $context)['exceptions'])
                ->firstWhere('key', 'blocked_suppliers')['count']);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }
    }

    public function test_dashboard_denies_invoice_permission_revoked_mid_kpi_query_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-DASHBOARD-MID-DENY');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P135-DASHBOARD-MID-SUP',
            'name_en' => 'Dashboard source supplier',
        ]);
        $order = $this->approvedOrder($actor, $context, $supplier->id, 2, 1.250);
        $this->assertNotNull($order->id);
        $manager = app(ProcurementCompletionManager::class);
        $permissionId = Permission::query()
            ->where('code', 'procurement.purchase_invoices.view')->firstOrFail()->id;

        $armed = false;
        $injected = false;
        DB::listen(static function ($query) use ($actor, $permissionId, &$armed, &$injected): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $injected || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_purchase_invoices')) {
                return;
            }

            $injected = true;
            $armed = false;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $positive = $manager->dashboard($actor, $context);
            $this->assertNotNull(collect($positive['kpis'])->firstWhere('key', 'open_orders')['value']);
            $this->assertArrayHasKey('spend_trend', $positive['charts']);
            $auditBefore = DB::table('audit_logs')->count();
            $ordersBefore = DB::table('enterprise_purchase_orders')->count();
            $injected = false;
            $armed = true;

            try {
                $manager->dashboard($actor, $context);
                $this->fail('An invoice VIEW revoked during KPI SQL must deny all hydrated financial metrics.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            } finally {
                $armed = false;
            }

            $this->assertTrue($injected, 'Financial KPI SQL must trigger the persisted DENY.');
            $this->assertFalse((bool) $actor->has_permission_overrides);
            $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
            // The dashboard is permission-aware: a subsequent request may still
            // show independently authorized KPIs, but must never hydrate invoice
            // metrics or charts after the persisted invoice VIEW DENY.
            $redacted = $manager->dashboard($actor, $context);
            $this->assertNotNull(collect($redacted['kpis'])->firstWhere('key', 'open_orders')['value']);
            $this->assertNull(collect($redacted['kpis'])->firstWhere('key', 'unmatched_invoices')['value']);
            $this->assertSame([], $redacted['charts']['spend_trend']);
            $this->assertSame([], $redacted['charts']['spend_by_supplier']);
            $this->assertSame([], $redacted['charts']['spend_by_category']);

            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            $this->assertSame($ordersBefore, DB::table('enterprise_purchase_orders')->count());
            DB::table('user_permission_overrides')
                ->where('user_id', $actor->id)->where('permission_id', $permissionId)->delete();
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => false]);
            $this->assertNotNull($manager->dashboard($actor, $context)['kpis']);
            $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        }
    }

    public function test_open_orders_report_denies_entry_or_document_view_revoked_after_sql_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-REPORT-MID-DENY');
        $supplier = app(SupplierDirectory::class)->create($actor, $context, [
            'scope_type' => 'store',
            'code' => 'P135-REPORT-MID-SUP',
            'name_en' => 'Report source supplier',
        ]);
        $order = $this->approvedOrder($actor, $context, $supplier->id, 2, 1.250);
        $manager = app(ProcurementCompletionManager::class);
        $permissionIds = [];
        foreach (['procurement.supplier_statements.view', 'procurement.purchase_orders.view'] as $code) {
            $permissionIds[$code] = Permission::query()->where('code', $code)->firstOrFail()->id;
        }
        $armed = false;
        $injected = false;
        $currentPermissionId = null;

        DB::listen(static function ($query) use ($actor, &$armed, &$injected, &$currentPermissionId): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $injected || $currentPermissionId === null
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'enterprise_purchase_orders')
                || ! str_contains($sql, 'order_value')) {
                return;
            }

            $armed = false;
            $injected = true;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $currentPermissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ($permissionIds as $permissionCode => $permissionId) {
                $read = static fn () => $manager->report($actor, $context, 'open_orders');
                $positive = $read();
                $this->assertCount(1, $positive['rows']);
                $this->assertSame($order->order_number, $positive['rows'][0]['order_number']);
                $auditBefore = DB::table('audit_logs')->count();
                $orderCount = DB::table('enterprise_purchase_orders')->count();
                $currentPermissionId = $permissionId;
                $injected = false;
                $armed = true;

                try {
                    $read();
                    $this->fail('A permission revoked midreport must not expose supplier and PO monetary details: '.$permissionCode);
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                $this->assertTrue($injected, 'The actual priced open-orders report SELECT must trigger DENY.');
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                try {
                    $read();
                    $this->fail('Repeat reporting under a persisted DENY must remain forbidden.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame($orderCount, DB::table('enterprise_purchase_orders')->count());

                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)
                    ->where('permission_id', $permissionId)
                    ->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertCount(1, $read()['rows']);
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            }
        }
    }

    public function test_direct_erp_option_reads_and_private_contract_detail_deny_midquery_view_revocation_in_ar_and_en(): void
    {
        Storage::fake('local');
        $this->enableProcurement();
        [$actor, , $context] = $this->scopedUser($this->allPermissions(), 'P135-OPTION-READ-LIVE-RBAC');
        $completion = app(ProcurementCompletionManager::class);
        $directory = app(SupplierDirectory::class);
        $requests = app(PurchaseRequestManager::class);
        $permissionIds = [
            'procurement.purchase_requests.view' => Permission::query()
                ->where('code', 'procurement.purchase_requests.view')->firstOrFail()->id,
            'procurement.purchase_orders.view' => Permission::query()
                ->where('code', 'procurement.purchase_orders.view')->firstOrFail()->id,
        ];
        $armed = false;
        $revoked = false;
        $target = '';
        $permissionId = 0;

        // One listener for the entire AR/EN matrix. The DENY is injected
        // after the actual scoped SQL returns, not at the preflight check.
        // It simulates ordering, NOT two independent MySQL sessions.
        DB::listen(static function ($query) use ($actor, &$armed, &$revoked, &$target, &$permissionId): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $revoked || ! str_starts_with($sql, 'select')) {
                return;
            }

            $isTarget = match ($target) {
                'request_options' => str_contains($sql, 'enterprise_purchase_requests')
                    && str_contains($sql, 'enterprise_procurement_rfqs')
                    && str_contains($sql, 'join'),
                'contract_options' => str_contains($sql, 'enterprise_procurement_contracts')
                    && str_contains($sql, 'enterprise_suppliers')
                    && str_contains($sql, 'join'),
                'contract_detail' => str_contains($sql, 'enterprise_procurement_contract_documents')
                    && str_contains($sql, 'enterprise_procurement_contracts')
                    && str_contains($sql, 'join'),
                default => false,
            };
            if (! $isTarget) {
                return;
            }

            $revoked = true;
            $armed = false;
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->id,
                'permission_id' => $permissionId,
                'scope_key' => 'global',
                'store_id' => null,
                'effect' => 'deny',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $actor->id)
                ->update(['has_permission_overrides' => true]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-OPTIONS-'.strtoupper($locale),
                'name_en' => 'Scoped option supplier '.$locale,
            ]);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'Scope option request '.$locale,
                'lines' => [[
                    'item_code' => 'OPTION-'.strtoupper($locale),
                    'description' => 'RFQ option input',
                    'quantity' => 1,
                    'unit' => 'each',
                    'estimated_unit_cost' => 1.250,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Protected option contract '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            $document = $completion->addContractDocument(
                $actor, $context, $contract->id, ['title' => 'Private option document '.$locale],
                UploadedFile::fake()->create('private-option.pdf', 4, 'application/pdf'),
            );
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $completion->activateContract($checker, $checkerContext, $contract->id);
            $this->assertSame('active', DB::table('enterprise_procurement_contracts')
                ->where('id', $contract->id)->value('status'));

            foreach ([
                'request_options' => [
                    'permission' => 'procurement.purchase_requests.view',
                    'read' => static fn () => $completion->rfqRequestOptions($actor, $context),
                    'expected_id' => $request->id,
                ],
                'contract_options' => [
                    'permission' => 'procurement.purchase_orders.view',
                    'read' => static fn () => $completion->activeContractOptions($actor, $context),
                    'expected_id' => $contract->id,
                ],
                'contract_detail' => [
                    'permission' => 'procurement.purchase_orders.view',
                    'read' => static fn () => $completion->contractDetail($actor, $context, $contract->id),
                    'expected_id' => $contract->id,
                ],
            ] as $name => $case) {
                $target = $name;
                $read = $case['read'];
                $visible = $read();
                if ($name === 'contract_detail') {
                    $this->assertSame((int) $case['expected_id'], (int) $visible['contract']->id);
                    $this->assertSame($document->storage_path, $visible['documents'][0]->storage_path);
                } else {
                    $this->assertContains((int) $case['expected_id'], array_map(
                        static fn ($record): int => (int) $record->id, $visible,
                    ));
                }

                $auditBefore = DB::table('audit_logs')->count();
                $documentsBefore = DB::table('enterprise_procurement_contract_documents')->count();
                $requestsBefore = DB::table('enterprise_purchase_requests')->count();
                $permissionId = (int) $permissionIds[$case['permission']];
                $revoked = false;
                $armed = true;

                try {
                    $read();
                    $this->fail('A persisted VIEW DENY during scoped SQL must block '.$name.' in '.$locale);
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                $this->assertTrue($revoked, 'Actual scoped '.$name.' SELECT must trigger the VIEW DENY.');
                $this->assertFalse((bool) $actor->has_permission_overrides);
                $this->assertTrue((bool) $actor->fresh()->has_permission_overrides);
                try {
                    $read();
                    $this->fail('Persisted VIEW DENY must also block repeated '.$name);
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }

                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame($documentsBefore, DB::table('enterprise_procurement_contract_documents')->count());
                $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
                Storage::disk('local')->assertExists($document->storage_path);
                DB::table('user_permission_overrides')
                    ->where('user_id', $actor->id)->where('permission_id', $permissionId)->delete();
                DB::table('users')->where('id', $actor->id)
                    ->update(['has_permission_overrides' => false]);
                $this->assertNotEmpty($read());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            }
        }
    }

    public function test_direct_procurement_options_deny_foreign_store_transfer_after_select_in_ar_and_en(): void
    {
        $this->enableProcurement();
        [$actor, $store, $context] = $this->scopedUser($this->allPermissions(), 'P135-OPTIONS-LIVE-OWNER');
        $foreignStore = $this->createRetailStore('P135-OPTIONS-FOREIGN-OWNER');
        $completion = app(ProcurementCompletionManager::class);
        $requests = app(PurchaseRequestManager::class);
        $directory = app(SupplierDirectory::class);

        $armed = false;
        $injected = false;
        $target = '';
        $targetId = 0;
        // Deterministic post-SELECT single-connection interleaving, not a
        // substitute for independent two-session MySQL / production UAT.
        DB::listen(static function ($query) use (
            $foreignStore, &$armed, &$injected, &$target, &$targetId
        ): void {
            $sql = strtolower(ltrim((string) $query->sql));
            if (! $armed || $injected || ! str_starts_with($sql, 'select')) {
                return;
            }
            $matched = match ($target) {
                'request' => str_contains($sql, 'enterprise_purchase_requests')
                    && str_contains($sql, 'enterprise_procurement_rfqs')
                    && str_contains($sql, 'order by'),
                'contract' => str_contains($sql, 'enterprise_procurement_contracts')
                    && str_contains($sql, 'enterprise_suppliers')
                    && str_contains($sql, 'order by'),
                default => false,
            };
            if (! $matched) {
                return;
            }
            $armed = false;
            $injected = true;
            DB::table($target === 'request'
                ? 'enterprise_purchase_requests'
                : 'enterprise_procurement_contracts')
                ->where('id', $targetId)
                ->update(['store_id' => $foreignStore->id]);
        });

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $request = $requests->create($actor, $context, [
                'currency_code' => 'KWD',
                'reason' => 'Protected request option '.$locale,
                'lines' => [[
                    'item_code' => 'OPTIONS-OWNER-'.strtoupper($locale),
                    'description' => 'Scoped request option',
                    'quantity' => 1,
                    'unit' => 'each',
                    'estimated_unit_cost' => 1.250,
                ]],
            ]);
            $requests->submit($actor, $context, $request->id);
            $supplier = $directory->create($actor, $context, [
                'scope_type' => 'store',
                'code' => 'P135-OPT-OWNER-'.strtoupper($locale),
                'name_en' => 'Scoped supplier '.$locale,
            ]);
            $contract = $completion->createContract($actor, $context, [
                'supplier_id' => $supplier->id,
                'title' => 'Scoped agreement '.$locale,
                'currency_code' => 'KWD',
                'starts_on' => now()->toDateString(),
                'ends_on' => now()->addDays(30)->toDateString(),
            ]);
            [$checker, $checkerContext] = ProcurementSecondApprover::forSameStore($actor, $context);
            $completion->activateContract($checker, $checkerContext, $contract->id);

            foreach ([
                'request' => [
                    'table' => 'enterprise_purchase_requests',
                    'id' => (int) $request->id,
                    'read' => static fn (): array => $completion->rfqRequestOptions($actor, $context),
                ],
                'contract' => [
                    'table' => 'enterprise_procurement_contracts',
                    'id' => (int) $contract->id,
                    'read' => static fn (): array => $completion->activeContractOptions($actor, $context),
                ],
            ] as $name => $case) {
                $target = $name;
                $targetId = $case['id'];
                $read = $case['read'];
                $ids = static fn (): array => array_map(
                    static fn ($row): int => (int) $row->id, $read(),
                );
                $this->assertContains($targetId, $ids());
                $auditBefore = DB::table('audit_logs')->count();
                $requestsBefore = DB::table('enterprise_purchase_requests')->count();
                $contractsBefore = DB::table('enterprise_procurement_contracts')->count();
                $injected = false;
                $armed = true;
                try {
                    $read();
                    $this->fail('Post-SELECT '.$name.' transfer must deny stale results in '.$locale);
                } catch (HttpException $exception) {
                    $this->assertSame(404, $exception->getStatusCode());
                } finally {
                    $armed = false;
                }

                $this->assertTrue($injected, 'Actual '.$name.' option SELECT did not trigger transfer.');
                $this->assertNotContains($targetId, $ids());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
                $this->assertSame($requestsBefore, DB::table('enterprise_purchase_requests')->count());
                $this->assertSame($contractsBefore, DB::table('enterprise_procurement_contracts')->count());

                DB::table($case['table'])->where('id', $targetId)->update(['store_id' => $store->id]);
                $this->assertContains($targetId, $ids());
                $this->assertSame($auditBefore, DB::table('audit_logs')->count());
            }
        }
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
