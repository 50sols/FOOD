<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OperationalTenantScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerSalesContractController extends Controller
{
    public function __construct(
        private readonly OperationalTenantScope $scope,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'quotation_id' => ['required', 'integer', 'min:1'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'signed_document' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ]);
        $quote = $this->quotation($actor, (int) $data['quotation_id'], 'orders.manage');
        $this->scope->assertStore($actor, (int) $quote->store_id, 'orders.view', (string) $quote->channel);
        if ($quote->status !== 'issued' || $quote->expires_at < today()->toDateString()) {
            throw ValidationException::withMessages(['quotation_id' => [__('sales_contracts.issued_quote_required')]]);
        }
        if (DB::table('customer_sales_contracts')->where('quotation_id', $quote->id)->exists()) {
            throw ValidationException::withMessages(['quotation_id' => [__('sales_contracts.already_registered')]]);
        }

        $document = $request->file('signed_document');
        $realPath = $document?->getRealPath();
        if (! is_string($realPath) || file_get_contents($realPath, false, null, 0, 5) !== '%PDF-') {
            throw ValidationException::withMessages(['signed_document' => [__('sales_contracts.invalid_document')]]);
        }

        // The path is server-generated on a private disk; no arbitrary browser-supplied path is persisted.
        $filename = (string) Str::uuid().'.pdf';
        $filePath = $document->storeAs('customer-sales-contracts/'.$quote->store_id, $filename, 'local');
        if (! is_string($filePath)) {
            throw ValidationException::withMessages(['signed_document' => [__('sales_contracts.storage_failure')]]);
        }
        $digest = hash_file('sha256', $realPath);

        try {
            DB::transaction(function () use ($request, $actor, $quote, $data, $filePath, $digest): void {
                $reference = (string) Str::uuid();
                $id = DB::table('customer_sales_contracts')->insertGetId([
                    'reference' => $reference,
                    'quotation_id' => $quote->id,
                    'store_id' => $quote->store_id,
                    'channel' => $quote->channel,
                    'customer_id' => $quote->customer_id,
                    'customer_name_snapshot' => $quote->customer_name_snapshot,
                    'created_by' => $actor->getKey(),
                    'status' => 'pending',
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'currency' => $quote->currency,
                    'grand_total' => $quote->grand_total,
                    'pricing_snapshot' => $quote->pricing_snapshot,
                    'evidence_path' => $filePath,
                    'evidence_sha256' => $digest,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit->record('sales.contract.submitted', $actor, null, null, [
                    'contract_id' => $id,
                    'quotation_id' => (int) $quote->id,
                    'store_id' => (int) $quote->store_id,
                    'channel' => (string) $quote->channel,
                    'status' => 'pending',
                    'evidence_sha256' => $digest,
                ], $request);
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($filePath);
            throw $e;
        }

        return redirect()->route('admin.sales-quotations.show', ['quotation' => $quote->id])
            ->with('status', __('sales_contracts.submitted'));
    }

    public function decide(Request $request, int $contract): RedirectResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'rejection_reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:500'],
        ]);
        $quoteId = DB::transaction(function () use ($request, $actor, $contract, $data): int {
            $record = $this->contract($actor, $contract, 'orders.approve', true);
            $this->scope->assertStore($actor, (int) $record->store_id, 'orders.view', (string) $record->channel);
            abort_if((int) $record->created_by === (int) $actor->getKey(), 403);
            if ($record->status !== 'pending') {
                throw ValidationException::withMessages(['status' => [__('sales_contracts.invalid_transition')]]);
            }
            if ($data['decision'] === 'approve') {
                $quote = DB::table('sales_quotations')->where('id', $record->quotation_id)->first();
                if ($quote === null || $quote->status !== 'issued' || $quote->expires_at < today()->toDateString()) {
                    throw ValidationException::withMessages(['quotation_id' => [__('sales_contracts.issued_quote_required')]]);
                }
                $this->assertEvidenceIntact($record);
            }
            $approved = $data['decision'] === 'approve';
            DB::table('customer_sales_contracts')->where('id', $record->id)->update([
                'status' => $approved ? 'active' : 'rejected',
                'approved_by' => $approved ? $actor->getKey() : null,
                'approved_at' => $approved ? now() : null,
                'rejection_reason' => $approved ? null : $data['rejection_reason'],
                'rejected_at' => $approved ? null : now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('sales.contract.'.($approved ? 'approved' : 'rejected'), $actor, null, [
                'contract_id' => (int) $record->id,
                'store_id' => (int) $record->store_id,
                'status' => 'pending',
            ], [
                'contract_id' => (int) $record->id,
                'store_id' => (int) $record->store_id,
                'status' => $approved ? 'active' : 'rejected',
            ], $request);

            return (int) $record->quotation_id;
        }, 3);

        return redirect()->route('admin.sales-quotations.show', ['quotation' => $quoteId])
            ->with('status', __('sales_contracts.updated'));
    }

    public function terminate(Request $request, int $contract): RedirectResponse
    {
        $actor = $this->actor($request);
        $quoteId = DB::transaction(function () use ($request, $actor, $contract): int {
            $record = $this->contract($actor, $contract, 'orders.approve', true);
            $this->scope->assertStore($actor, (int) $record->store_id, 'orders.view', (string) $record->channel);
            if ($record->status !== 'active') {
                throw ValidationException::withMessages(['status' => [__('sales_contracts.invalid_transition')]]);
            }
            DB::table('customer_sales_contracts')->where('id', $record->id)->update([
                'status' => 'terminated',
                'terminated_by' => $actor->getKey(),
                'terminated_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('sales.contract.terminated', $actor, null, [
                'contract_id' => (int) $record->id,
                'store_id' => (int) $record->store_id,
                'status' => 'active',
            ], [
                'contract_id' => (int) $record->id,
                'store_id' => (int) $record->store_id,
                'status' => 'terminated',
            ], $request);

            return (int) $record->quotation_id;
        }, 3);

        return redirect()->route('admin.sales-quotations.show', ['quotation' => $quoteId])
            ->with('status', __('sales_contracts.updated'));
    }

    public function evidence(Request $request, int $contract): StreamedResponse
    {
        $actor = $this->actor($request);
        $record = $this->contract($actor, $contract, 'orders.view');
        $this->assertEvidenceIntact($record);

        return Storage::disk('local')->download($record->evidence_path, 'customer-contract-'.$record->id.'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function assertEvidenceIntact(object $record): void
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists((string) $record->evidence_path), 409);
        $actual = hash_file('sha256', $disk->path((string) $record->evidence_path));
        abort_unless(is_string($actual) && hash_equals((string) $record->evidence_sha256, $actual), 409);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }

    private function quotation(User $actor, int $id, string $permission): object
    {
        abort_if($this->scope->allowedStoreIds($actor, $permission) === [], 403);
        $quote = DB::table('sales_quotations')->where('id', $id)->first();
        abort_if($quote === null, 404);
        $channel = $this->scope->assertStore($actor, (int) $quote->store_id, $permission);
        abort_unless($channel === $quote->channel, 404);

        return $quote;
    }

    private function contract(User $actor, int $id, string $permission, bool $lock = false): object
    {
        abort_if($this->scope->allowedStoreIds($actor, $permission) === [], 403);
        $query = DB::table('customer_sales_contracts')->where('id', $id);
        $record = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($record === null, 404);
        $channel = $this->scope->assertStore($actor, (int) $record->store_id, $permission);
        abort_unless($channel === $record->channel, 404);

        return $record;
    }
}
