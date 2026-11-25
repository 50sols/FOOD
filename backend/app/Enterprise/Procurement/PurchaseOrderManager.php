<?php

namespace App\Enterprise\Procurement;

use App\Models\EnterprisePurchaseOrder;
use App\Models\EnterprisePurchaseRequest;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PurchaseOrderManager
{
    private const VIEW_PERMISSION = 'procurement.purchase_orders.view';

    private const MANAGE_PERMISSION = 'procurement.purchase_orders.manage';

    private const ISSUE_PERMISSION = 'procurement.purchase_orders.issue';

    private const STATUSES = ['draft', 'issued', 'cancelled'];

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{q?:string,status?:string}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseOrder>
     */
    public function paginate(
        User $actor,
        ResolvedTenantContext $context,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);
        $validated = Validator::make($filters, [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ])->validate();

        $perPage = max(10, min(100, $perPage));
        $term = trim((string) ($validated['q'] ?? ''));
        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        $orders = $this->visibleQuery($scope)
            ->with('supplier:id,code,name_en,name_ar,status')
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                $term !== '',
                fn (Builder $query): Builder => $query->whereRaw("order_number LIKE ? ESCAPE '!'", [$pattern]),
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        // The COUNT, page SELECT and eager-loaded supplier rows are separate
        // reads. A persisted view DENY may be committed after preflight but
        // before the paginator is returned; never release that stale snapshot.
        // Re-check the immutable persisted caller through the existing scope
        // authorizer (which fetches the currently active user and overrides).
        $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $orders;
    }

    public function detail(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        $order = $this->findVisible($scope, $orderId)
            ->load([
                'supplier:id,code,name_en,name_ar,status',
                'purchaseRequest:id,request_number,status',
                'lines' => fn ($query) => $query->orderBy('line_no'),
            ]);

        // A purchase order can move stores between the scoped header SELECT
        // and the separate supplier/request/line reads. Never return stale
        // financial or supplier detail to its former owner after reassignment.
        $this->findVisible($scope, $orderId);

        // The relationship queries and second store check can run after a
        // persisted VIEW revocation. Re-read the same original active actor's
        // current effective permission before releasing priced lines or supplier data.
        $this->authorizedScope($actor, $context, self::VIEW_PERMISSION);

        return $order;
    }

    public function createFromApprovedRequest(
        User $actor,
        ResolvedTenantContext $context,
        int $requestId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $context, $scope, $requestId): EnterprisePurchaseOrder {
            $request = $this->visibleRequestQuery($scope)
                ->whereKey($requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = $this->visibleQuery($scope)
                ->where('purchase_request_id', $request->id)
                ->first();

            if ($existing instanceof EnterprisePurchaseOrder) {
                $existing->load('lines');
                $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

                return $existing;
            }

            if ($request->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_request_id' => [
                        sprintf(
                            'Purchase request %s must be approved before creating a purchase order.',
                            $request->request_number,
                        ),
                    ],
                ]);
            }

            if ($request->supplier_id === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['An approved purchase request must have a supplier before PO creation.'],
                ]);
            }

            $supplier = $this->activeVisibleSupplier($scope, (int) $request->supplier_id);
            $lines = $request->lines()->orderBy('line_no')->get();
            abort_if($lines->isEmpty(), 409, 'A purchase order must contain at least one line.');

            // A revoked operator cannot create a PO after supplier/line preflight.
            $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

            $publicId = (string) Str::uuid();
            $order = EnterprisePurchaseOrder::query()->create([
                'public_id' => $publicId,
                'order_number' => 'PO-'.strtoupper(substr(str_replace('-', '', $publicId), 0, 12)),
                'purchase_request_id' => $request->id,
                'source_request_number' => $request->request_number,
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'store_id' => $scope->storeId,
                'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code,
                'supplier_name_en' => $supplier->name_en,
                'supplier_name_ar' => $supplier->name_ar,
                'status' => 'draft',
                'currency_code' => $request->currency_code,
                'needed_by' => $request->getRawOriginal('needed_by'),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                $order->lines()->create([
                    'source_purchase_request_line_id' => $line->id,
                    'line_no' => $line->line_no,
                    'item_code' => $line->item_code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->estimated_unit_cost,
                ]);
            }

            // Roll back PO and all line inserts if access was revoked mid-write.
            $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

            $this->audit->record(
                'enterprise.purchase_order.created',
                $actor,
                $order,
                null,
                [
                    'order_number' => $order->order_number,
                    'source_request_number' => $order->source_request_number,
                    'purchase_request_id' => $request->id,
                    'supplier_id' => $supplier->id,
                    'status' => $order->status,
                    'organization_id' => $order->organization_id,
                    'company_id' => $order->company_id,
                    'store_id' => $order->store_id,
                    'line_count' => $lines->count(),
                ],
            );

            return $order->load('lines');
        }, 3);
    }

    public function issue(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::ISSUE_PERMISSION);

        return DB::transaction(function () use ($actor, $context, $scope, $orderId): EnterprisePurchaseOrder {
            $order = $this->lockVisible($scope, $orderId);
            if ($order->status === 'issued') {
                $order->refresh();
                $this->authorizedScope($actor, $context, self::ISSUE_PERMISSION);

                return $order;
            }

            $this->requireStatus($order, ['draft'], 'issued');
            $lines = $order->lines()->get();
            abort_if($lines->isEmpty(), 409, 'A purchase order must contain at least one line.');

            // A supplier can be blocked or lose qualification after the draft
            // PO was created. Revalidate under a row lock before issuing.
            $this->activeVisibleSupplier($scope, (int) $order->supplier_id);

            // The approved PR validates inputs at creation, but persisted PO
            // lines may have been altered by an import or legacy writer.
            // Reject invalid quantity/price before any contract commitment,
            // state transition or audit entry, whether or not a contract exists.
            foreach ($lines as $line) {
                if (
                    ! is_finite((float) $line->quantity)
                    || (float) $line->quantity <= 0
                    || ($line->unit_price !== null && (
                        ! is_finite((float) $line->unit_price)
                        || (float) $line->unit_price < 0
                    ))
                ) {
                    throw ValidationException::withMessages([
                        'lines' => ['Purchase order quantities must be positive and unit prices cannot be negative.'],
                    ]);
                }
            }

            if ($order->procurement_contract_id !== null) {
                $contract = DB::table('enterprise_procurement_contracts')
                    ->where('id', $order->procurement_contract_id)
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('store_id', $scope->storeId)
                    ->lockForUpdate()
                    ->first();

                abort_if($contract === null, 409, 'Assigned procurement contract is unavailable.');
                if (
                    $contract->status !== 'active'
                    || (int) $contract->supplier_id !== (int) $order->supplier_id
                    || $contract->currency_code !== $order->currency_code
                    || now()->toDateString() < (string) $contract->starts_on
                    || now()->toDateString() > (string) $contract->ends_on
                ) {
                    throw ValidationException::withMessages([
                        'procurement_contract_id' => [
                            'Assigned procurement contract is inactive, expired, or incompatible with this purchase order.',
                        ],
                    ]);
                }

                if ($lines->contains(fn ($line): bool => $line->unit_price === null)) {
                    throw ValidationException::withMessages([
                        'procurement_contract_id' => [
                            'Contract-backed purchase orders require a known unit price for every line.',
                        ],
                    ]);
                }

                $orderValue = round((float) $lines->sum(
                    fn ($line): float => (float) $line->unit_price * (float) $line->quantity,
                ), 3);
                $orderQuantity = round((float) $lines->sum('quantity'), 3);
                $nextValue = round((float) $contract->committed_value + $orderValue, 3);
                $nextQuantity = round((float) $contract->committed_quantity + $orderQuantity, 3);

                if ($contract->value_ceiling !== null && $nextValue > (float) $contract->value_ceiling) {
                    throw ValidationException::withMessages([
                        'procurement_contract_id' => ['Issuing this PO would exceed the contract value ceiling.'],
                    ]);
                }
                if ($contract->quantity_ceiling !== null && $nextQuantity > (float) $contract->quantity_ceiling) {
                    throw ValidationException::withMessages([
                        'procurement_contract_id' => ['Issuing this PO would exceed the contract quantity ceiling.'],
                    ]);
                }

                // Contract commitment must not be changed by a revoked issuer.
                $this->authorizedScope($actor, $context, self::ISSUE_PERMISSION);

                DB::table('enterprise_procurement_contracts')
                    ->where('id', $contract->id)
                    ->update([
                        'committed_value' => $nextValue,
                        'committed_quantity' => $nextQuantity,
                        'updated_by' => $actor->id,
                        'updated_at' => now(),
                    ]);
            }

            // Recheck after all supplier/line/contract reads; 403 rolls back
            // any previous commitment changes in this same transaction.
            $this->authorizedScope($actor, $context, self::ISSUE_PERMISSION);

            $before = $order->toArray();
            $order->forceFill([
                'status' => 'issued',
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_order.issued',
                $actor,
                $order,
                $before,
                $order->fresh()->toArray(),
            );

            return $order->refresh();
        }, 3);
    }

    public function cancel(
        User $actor,
        ResolvedTenantContext $context,
        int $orderId,
    ): EnterprisePurchaseOrder {
        $scope = $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

        return DB::transaction(function () use ($actor, $context, $scope, $orderId): EnterprisePurchaseOrder {
            $order = $this->lockVisible($scope, $orderId);
            if ($order->status === 'cancelled') {
                $order->refresh();
                $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

                return $order;
            }

            $this->requireStatus($order, ['draft', 'issued'], 'cancelled');

            // GRN posting locks the same PO and creates authoritative stock
            // movements. Do not release contractual commitments or cancel an
            // issued order once inventory has actually been received.
            if ($order->status === 'issued' && DB::table('enterprise_goods_receipts')
                ->where('purchase_order_id', $order->id)
                ->where('organization_id', $scope->organizationId)
                ->where('company_id', $scope->companyId)
                ->where('store_id', $scope->storeId)
                ->where(function ($receipts): void {
                    $receipts->where('status', 'posted')
                        ->orWhereExists(function ($lines): void {
                            $lines->selectRaw('1')
                                ->from('enterprise_goods_receipt_lines as received_lines')
                                ->whereColumn('received_lines.goods_receipt_id', 'enterprise_goods_receipts.id')
                                ->where(function ($handoff): void {
                                    $handoff->whereNotNull('received_lines.stock_movement_id')
                                        ->orWhereExists(function ($movements): void {
                                            $movements->selectRaw('1')
                                                ->from('stock_movements as received_stock')
                                                ->whereColumn('received_stock.reference_id', 'received_lines.id')
                                                ->whereColumn('received_stock.store_id', 'enterprise_goods_receipts.store_id')
                                                ->where('received_stock.reference_type', 'enterprise_goods_receipt_line')
                                                ->where('received_stock.type', 'purchase_receipt');
                                        });
                                });
                        });
                })
                ->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['A purchase order with posted receipts cannot be cancelled; use the authorized return or correction workflow.'],
                ]);
            }

            $before = $order->toArray();

            if ($order->status === 'issued' && $order->procurement_contract_id !== null) {
                $contract = DB::table('enterprise_procurement_contracts')
                    ->where('id', $order->procurement_contract_id)
                    ->where('organization_id', $scope->organizationId)
                    ->where('company_id', $scope->companyId)
                    ->where('store_id', $scope->storeId)
                    ->lockForUpdate()
                    ->first();

                // An issued order already reserved contract value/quantity.
                // A transferred or missing contract cannot be silently skipped:
                // cancellation would orphan that reserved financial balance.
                abort_if($contract === null, 409, 'Assigned procurement contract is unavailable for commitment release.');

                $lines = $order->lines()->get();
                $orderValue = round((float) $lines->sum(
                    fn ($line): float => (float) ($line->unit_price ?? 0) * (float) $line->quantity,
                ), 3);
                $orderQuantity = round((float) $lines->sum('quantity'), 3);
                // Releasing an issued contract commitment also requires
                // the caller's current persisted manage permission.
                $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

                DB::table('enterprise_procurement_contracts')
                    ->where('id', $contract->id)
                    ->update([
                        'committed_value' => max(0, round((float) $contract->committed_value - $orderValue, 3)),
                        'committed_quantity' => max(0, round((float) $contract->committed_quantity - $orderQuantity, 3)),
                        'updated_by' => $actor->id,
                        'updated_at' => now(),
                    ]);
            }

            // Protect the non-contract path and roll back any earlier
            // commitment release if a permission was revoked during reads.
            $this->authorizedScope($actor, $context, self::MANAGE_PERMISSION);

            $order->forceFill([
                'status' => 'cancelled',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(
                'enterprise.purchase_order.cancelled',
                $actor,
                $order,
                $before,
                $order->fresh()->toArray(),
            );

            return $order->refresh();
        }, 3);
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
        string $permission,
    ): EnterpriseDataScope {
        // Direct-service Eloquent actors must be tied to the originally loaded
        // persisted account, not an unsaved replacement of the primary key.
        // Otherwise order issuance/cancellation could be attributed to a
        // different authorized user without authenticating that user.
        $persistedActorId = $actor->getRawOriginal($actor->getKeyName());
        abort_unless(
            $actor->exists
                && $persistedActorId !== null
                && (string) $actor->getKey() === (string) $persistedActorId,
            403,
        );

        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        // The supplied Eloquent User may predate a committed permission DENY.
        // Authorize the same immutable persisted principal, but with today's
        // active status and effective override flag, never a stale snapshot.
        $currentActor = User::query()
            ->whereKey($persistedActorId)
            ->where('is_active', true)
            ->first();
        abort_unless($currentActor instanceof User, 403);

        $scope = $context->enterpriseScope;
        $this->authorizer->authorize(
            $currentActor,
            $context,
            $permission,
            'company',
            $scope->companyId,
        );

        return $scope;
    }

    /** @return Builder<EnterprisePurchaseOrder> */
    private function visibleQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseOrder::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterprisePurchaseRequest> */
    private function visibleRequestQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseRequest::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    private function findVisible(
        EnterpriseDataScope $scope,
        int $orderId,
    ): EnterprisePurchaseOrder {
        return $this->visibleQuery($scope)->whereKey($orderId)->firstOrFail();
    }

    private function lockVisible(
        EnterpriseDataScope $scope,
        int $orderId,
    ): EnterprisePurchaseOrder {
        return $this->visibleQuery($scope)
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function activeVisibleSupplier(
        EnterpriseDataScope $scope,
        int $supplierId,
    ): EnterpriseSupplier {
        return EnterpriseSupplier::query()
            ->whereKey($supplierId)
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('status', 'active')
            ->where('qualification_status', '!=', 'unqualified')
            ->where(function (Builder $query) use ($scope): void {
                $query
                    ->where('scope_type', 'company')
                    ->orWhere(function (Builder $store) use ($scope): void {
                        $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId);
                    });
            })
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param list<string> $allowed */
    private function requireStatus(
        EnterprisePurchaseOrder $order,
        array $allowed,
        string $action,
    ): void {
        if (in_array((string) $order->status, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [
                sprintf(
                    'Purchase order %s cannot be %s from status %s.',
                    $order->order_number,
                    $action,
                    $order->status,
                ),
            ],
        ]);
    }
}
