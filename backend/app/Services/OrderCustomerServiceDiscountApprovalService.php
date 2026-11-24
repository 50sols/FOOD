<?php

namespace App\Services;

use App\Enterprise\Finance\FinanceFoundationService;
use App\Enterprise\Finance\FinanceWorkbenchAccess;
use App\Enterprise\Modules\ModuleRegistry;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderCustomerServiceDiscountApprovalService
{
    public function __construct(
        private readonly OrderCustomerServiceDiscountService $discounts,
        private readonly AuditLogger $audit,
        private readonly OperationalTenantScope $scope,
        private readonly FinanceWorkbenchAccess $financeAccess,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * A request is not a pricing mutation. The authoritative order is locked
     * again at review; stale paid/dispatched/taxed orders are rejected then.
     */
    public function request(
        Order $order,
        User $actor,
        float $percentage,
        string $reason,
        string $idempotencyKey,
        Request $request,
    ): bool {
        return DB::transaction(function () use ($order, $actor, $percentage, $reason, $idempotencyKey, $request): bool {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $storeId = (int) $locked->store_id;
            $channel = strtolower((string) $locked->channel);
            // Never rely solely on the HTTP controller: jobs and other direct
            // service callers must obey the same store-scoped CS authority.
            abort_unless($actor->is_active
                && $actor->hasPermission('orders.view', $storeId)
                && $actor->hasPermission('orders.customer_service_discount', $storeId), 403);
            $this->scope->assertStore($actor, $storeId, 'orders.customer_service_discount', $channel);
            if ($actor->hasRole('CUSTOMER_SERVICE_AGENT')
                && ! $actor->hasRole('CUSTOMER_SERVICE_MANAGER')
                && ! $actor->hasRole('CUSTOMER_SERVICE_SUPERVISOR')
                && ! $actor->hasRole('SUPER_ADMIN')) {
                abort_unless(DB::table('order_customer_service_assignments')
                    ->where('order_id', $locked->getKey())
                    ->where('assigned_user_id', $actor->getKey())
                    ->whereNull('unassigned_at')
                    ->exists(), 403);
            }

            // A direct service caller must pass the same normalized rationale
            // required by the HTTP entrypoint. Empty or padded reasons cannot
            // create an actionable financial approval or misleading audit row.
            $reason = trim($reason);
            $reasonLength = mb_strlen($reason);
            if ($reasonLength < 5 || $reasonLength > 500) {
                throw ValidationException::withMessages([
                    'reason' => [__('customer_service_discount.not_allowed')],
                ]);
            }

            // HTTP validation is not a trust boundary for direct service callers.
            // Reject NaN, infinity and percentages outside the mathematical
            // 0..100 range before inserting an actionable Finance request.
            if (! is_finite($percentage) || $percentage < 0 || $percentage > 100
                || abs($percentage - round($percentage, 3)) > 0.000000001) {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.invalid_totals')],
                ]);
            }

            // The HTTP UUID validator is not a trust boundary for jobs or
            // direct service clients. A missing/non-UUID key must never create
            // an actionable approval request that cannot be safely replayed.
            if (! Str::isUuid($idempotencyKey)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => [__('customer_service_discount.not_allowed')],
                ]);
            }

            $existing = DB::table('order_customer_service_discount_requests')
                ->where('order_id', $locked->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                // A retry may confirm the exact original request, never reuse
                // its key to silently suppress a different financial intent.
                if ((int) $existing->requested_by_user_id !== (int) $actor->getKey()
                    || abs((float) $existing->percentage - $percentage) > 0.000001
                    || (string) $existing->reason !== $reason) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => [__('customer_service_discount.not_allowed')],
                    ]);
                }

                return false;
            }

            // A request must be actionable when created. An Accountant will
            // recheck the same conditions under lock at review time.
            if (strtolower((string) $locked->status) !== 'pending'
                || $locked->commercial_locked_at !== null
                || Invoice::query()->where('order_id', $locked->getKey())->whereIn('status', ['issued', 'reissued'])->exists()
                || Payment::query()->where('order_id', $locked->getKey())->whereNotIn('status', ['pending', 'failed', 'cancelled'])->exists()
                || DB::table('order_dispatch_states')->where('order_id', $locked->getKey())
                    ->where('status', 'assigned')->whereNotNull('current_assignee_id')->exists()
                || DB::table('driver_assignments')->where('order_id', $locked->getKey())
                    ->whereNotIn('status', ['unassigned', 'reassigned', 'cancelled', 'delivered', 'failed'])->exists()
                || DB::table('order_van_assignments')->where('order_id', $locked->getKey())
                    ->where('status', 'active')->exists()) {
                throw ValidationException::withMessages([
                    'percentage' => [__('customer_service_discount.not_allowed')],
                ]);
            }

            DB::table('order_customer_service_discount_requests')->insert([
                'order_id' => $locked->getKey(),
                'requested_by_user_id' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
                'percentage' => $percentage,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record('order.customer_service_discount.requested', $actor, $locked, [], [
                'store_id' => $locked->store_id,
                'percentage' => $percentage,
                'reason' => $reason,
                'status' => 'pending',
            ], $request);

            return true;
        }, 3);
    }

    /**
     * Only an independently authorized reviewer may transition a pending
     * request. Both the approval and the real financial change are atomic.
     * Rejection never changes order pricing or notifies the customer.
     */
    public function review(
        Order $order,
        User $reviewer,
        int $requestId,
        bool $approved,
        ?string $reviewReason,
        Request $request,
    ): void {
        DB::transaction(function () use ($order, $reviewer, $requestId, $approved, $reviewReason, $request): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $storeId = (int) $locked->store_id;
            $channel = strtolower((string) $locked->channel);
            $accountingRole = match ($channel) {
                'b2b' => $reviewer->hasRole('FINANCE'),
                'b2c' => $reviewer->hasRole('RETAIL_FINANCE', $storeId),
                default => false,
            };
            abort_unless(
                $reviewer->is_active && $accountingRole
                && $reviewer->hasPermission('finance.view', $storeId)
                && $reviewer->hasPermission('finance.manage', $storeId),
                403,
            );
            $this->scope->assertStore($reviewer, $storeId, 'finance.view', $channel);
            $this->scope->assertStore($reviewer, $storeId, 'finance.manage', $channel);
            // Defense in depth: a direct service caller must not approve a
            // discount after Finance has been disabled or its store scope
            // revoked, regardless of the HTTP controller's checks.
            abort_unless(
                $this->modules->isEnabled(FinanceFoundationService::MODULE)
                    && $this->financeAccess->canPerform(
                        $reviewer, $storeId, 'finance.manage', 'finance.foundation.read',
                    ),
                403,
            );

            $record = DB::table('order_customer_service_discount_requests')
                ->where('id', $requestId)
                ->where('order_id', $locked->getKey())
                ->lockForUpdate()
                ->first();

            abort_if($record === null, 404);
            abort_if((string) $record->status !== 'pending', 409);
            abort_if((int) $record->requested_by_user_id === (int) $reviewer->getKey(), 403);

            // The HTTP controller is not a trust boundary. A direct job or
            // service caller cannot record an unreasoned Accounting rejection,
            // or persist malformed optional approval reasons into the audit.
            $reviewReason = $reviewReason === null ? null : trim($reviewReason);
            $reasonLength = $reviewReason === null ? 0 : mb_strlen($reviewReason);
            if ((! $approved && $reasonLength < 5)
                || ($reviewReason !== null && ($reasonLength < 5 || $reasonLength > 500))) {
                throw ValidationException::withMessages([
                    'review_reason' => [__('customer_service_discount.not_allowed')],
                ]);
            }

            if ($approved) {
                $applied = $this->discounts->apply(
                    $locked,
                    $reviewer,
                    (float) $record->percentage,
                    (string) $record->reason,
                    (string) $record->idempotency_key,
                    $request,
                );

                // Idempotency keys must not authorize a second approval.
                abort_unless($applied, 409);
            }

            $nextStatus = $approved ? 'approved' : 'rejected';
            DB::table('order_customer_service_discount_requests')
                ->where('id', $requestId)
                ->where('status', 'pending')
                ->update([
                    'status' => $nextStatus,
                    'reviewed_by_user_id' => $reviewer->getKey(),
                    'review_reason' => $reviewReason,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->audit->record('order.customer_service_discount.'.$nextStatus, $reviewer, $locked, [
                'request_id' => $requestId,
                'status' => 'pending',
            ], [
                'request_id' => $requestId,
                'status' => $nextStatus,
                'requested_by_user_id' => $record->requested_by_user_id,
                'percentage' => $record->percentage,
                'review_reason' => $reviewReason,
            ], $request);
        }, 3);
    }
}
