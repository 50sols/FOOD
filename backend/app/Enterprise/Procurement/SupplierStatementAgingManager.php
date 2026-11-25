<?php

namespace App\Enterprise\Procurement;

use App\Enterprise\Modules\ModuleRegistry;
use App\Models\EnterprisePurchaseInvoice;
use App\Models\EnterpriseSupplier;
use App\Models\User;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupplierStatementAgingManager
{
    private const VIEW_PERMISSION = 'procurement.supplier_statements.view';

    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleRegistry $modules,
    ) {}

    /**
     * @param  array{from?:string,to?:string,currency_code?:string}  $filters
     * @return LengthAwarePaginator<int, EnterprisePurchaseInvoice>
     */
    public function statement(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $filters = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        $scope = $this->authorizedScope($actor, $context);
        $this->findVisibleSupplier($scope, $supplierId);

        $validated = Validator::make($filters, [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ])->validate();
        $window = $this->normalizedDateWindow(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        $perPage = max(10, min(100, $perPage));
        $currency = isset($validated['currency_code'])
            ? strtoupper((string) $validated['currency_code'])
            : null;

        $page = $this->visibleInvoiceQuery($scope)
            ->where('supplier_id', $supplierId)
            ->where('status', 'handed_off')
            ->where('invoice_date', '>=', $window['from'])
            ->where(
                'invoice_date',
                '<',
                CarbonImmutable::parse($window['to'])->addDay()->toDateString(),
            )
            ->when(
                $currency,
                fn (Builder $query, string $code): Builder => $query->where('currency_code', $code),
            )
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        // The supplier could transfer to a different store after the first
        // visible-supplier SELECT and before its invoices are fetched.
        $this->findVisibleSupplier($scope, $supplierId);
        // A user's report permission can also be revoked during pagination.
        // Rehydrate the original actor and reject late DENY/offboarding.
        $this->authorizedScope($actor, $context);

        return $page;
    }

    /**
     * @param  array{as_of:string,currency_code:string}  $filters
     * @return array{
     *     supplier:EnterpriseSupplier,
     *     as_of:string,
     *     currency_code:string,
     *     invoice_count:int,
     *     gross_exposure:string,
     *     buckets:array{
     *         not_due:string,
     *         due_0_30:string,
     *         due_31_60:string,
     *         due_61_90:string,
     *         due_over_90:string,
     *         no_due_date:string
     *     }
     * }
     */
    public function aging(
        User $actor,
        ResolvedTenantContext $context,
        int $supplierId,
        array $filters,
    ): array {
        $scope = $this->authorizedScope($actor, $context);
        $supplier = $this->findVisibleSupplier($scope, $supplierId);
        $validated = Validator::make($filters, [
            'as_of' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ])->validate();

        $asOf = CarbonImmutable::parse((string) $validated['as_of'])->startOfDay();
        $currency = strtoupper((string) $validated['currency_code']);

        $base = $this->visibleInvoiceQuery($scope)
            ->where('supplier_id', $supplierId)
            ->where('status', 'handed_off')
            ->where('currency_code', $currency)
            ->where('invoice_date', '<', $asOf->addDay()->toDateString());

        $nextDay = $asOf->addDay()->toDateString();
        $due30 = $asOf->subDays(30)->toDateString();
        $due60 = $asOf->subDays(60)->toDateString();
        $due90 = $asOf->subDays(90)->toDateString();

        // A financial exposure report must be ONE database statement snapshot.
        // Eight independent COUNT/SUM calls can observe different invoice
        // versions between buckets and report inconsistent gross exposure.
        // Binding date thresholds retains the same due-date semantics without
        // interpolating request values into SQL.
        $aggregate = $base->selectRaw(<<<'SQL'
COUNT(*) AS invoice_count,
SUM(total_amount) AS gross_exposure,
SUM(CASE WHEN due_date >= ? THEN total_amount ELSE 0 END) AS not_due,
SUM(CASE WHEN due_date >= ? AND due_date < ? THEN total_amount ELSE 0 END) AS due_0_30,
SUM(CASE WHEN due_date >= ? AND due_date < ? THEN total_amount ELSE 0 END) AS due_31_60,
SUM(CASE WHEN due_date >= ? AND due_date < ? THEN total_amount ELSE 0 END) AS due_61_90,
SUM(CASE WHEN due_date < ? THEN total_amount ELSE 0 END) AS due_over_90,
SUM(CASE WHEN due_date IS NULL THEN total_amount ELSE 0 END) AS no_due_date
SQL, [
            $nextDay,
            $due30, $nextDay,
            $due60, $due30,
            $due90, $due60,
            $due90,
        ])->firstOrFail();

        // Model properties are limited to declared schema columns; SQL aggregate
        // aliases belong to this single query result. Read raw attributes so
        // PHPStan can type-check them without losing exact DECIMAL strings.
        $result = [
            'supplier' => $supplier,
            'as_of' => $asOf->toDateString(),
            'currency_code' => $currency,
            'invoice_count' => (int) $aggregate->getRawOriginal('invoice_count'),
            'gross_exposure' => $this->amount($aggregate->getRawOriginal('gross_exposure')),
            'buckets' => [
                'not_due' => $this->amount($aggregate->getRawOriginal('not_due')),
                'due_0_30' => $this->amount($aggregate->getRawOriginal('due_0_30')),
                'due_31_60' => $this->amount($aggregate->getRawOriginal('due_31_60')),
                'due_61_90' => $this->amount($aggregate->getRawOriginal('due_61_90')),
                'due_over_90' => $this->amount($aggregate->getRawOriginal('due_over_90')),
                'no_due_date' => $this->amount($aggregate->getRawOriginal('no_due_date')),
            ],
        ];

        // Never return financial exposure for a supplier that left the
        // authorized store while the atomic aggregate was being fetched.
        $this->findVisibleSupplier($scope, $supplierId);
        // Reauthorize the current persisted actor after the aggregate query.
        // A permission revoked during the report must not return stale money.
        $this->authorizedScope($actor, $context);

        return $result;
    }

    private function authorizedScope(
        User $actor,
        ResolvedTenantContext $context,
    ): EnterpriseDataScope {
        // A mutated, unsaved Eloquent user ID must never grant access to
        // another principal's supplier invoices or aging balances.
        $persistedActorId = $actor->getRawOriginal($actor->getKeyName());
        abort_unless(
            $actor->exists
                && $persistedActorId !== null
                && (string) $actor->getKey() === (string) $persistedActorId,
            403,
        );

        abort_unless(
            $this->modules->isEnabled('procurement'),
            404,
            'Procurement module is disabled.',
        );
        abort_if($context->enterpriseScope === null, 409, 'Enterprise data scope is required.');

        $scope = $context->enterpriseScope;
        // A stale in-memory User may predate a persisted explicit DENY.
        // Authorize current database state, not its old permission flag.
        $currentActor = User::query()
            ->whereKey($persistedActorId)
            ->where('is_active', true)
            ->first();
        abort_unless($currentActor !== null, 403);
        $this->authorizer->authorize(
            $currentActor,
            $context,
            self::VIEW_PERMISSION,
            'company',
            $scope->companyId,
        );

        return $scope;
    }

    /** @return Builder<EnterprisePurchaseInvoice> */
    private function visibleInvoiceQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterprisePurchaseInvoice::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('store_id', $scope->storeId);
    }

    /** @return Builder<EnterpriseSupplier> */
    private function visibleSupplierQuery(EnterpriseDataScope $scope): Builder
    {
        return EnterpriseSupplier::query()
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where(function (Builder $query) use ($scope): void {
                $query
                    ->where('scope_type', 'company')
                    ->orWhere(function (Builder $store) use ($scope): void {
                        $store
                            ->where('scope_type', 'store')
                            ->where('store_id', $scope->storeId);
                    });
            });
    }

    private function findVisibleSupplier(
        EnterpriseDataScope $scope,
        int $supplierId,
    ): EnterpriseSupplier {
        return $this->visibleSupplierQuery($scope)->whereKey($supplierId)->firstOrFail();
    }

    /** @return array{from:string,to:string} */
    private function normalizedDateWindow(mixed $from, mixed $to): array
    {
        $start = $from === null
            ? null
            : CarbonImmutable::parse((string) $from)->startOfDay();
        $end = $to === null
            ? null
            : CarbonImmutable::parse((string) $to)->startOfDay();

        if ($start === null && $end === null) {
            $end = CarbonImmutable::today();
            $start = $end->subDays(366);
        } elseif ($start === null) {
            $start = $end->subDays(366);
        } elseif ($end === null) {
            $end = $start->addDays(366);
        }

        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'to' => ['Supplier statement date window cannot exceed 366 days.'],
            ]);
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
        ];
    }

    private function amount(mixed $value): string
    {
        // MySQL/MariaDB DECIMAL SUM returns an exact decimal string. Casting
        // that string to float rounds away the mills at large, valid values.
        // SQLite test aggregates can be float; normalize their small
        // aggregates without ever float-coercing the exact SQL string.
        $raw = is_float($value)
            ? sprintf('%.9F', $value)
            : (string) ($value ?? 0);
        if (! preg_match('/\A([+-]?)(\d+)(?:\.(\d+))?\z/D', $raw, $match)) {
            throw new \UnexpectedValueException('Invalid supplier-aging decimal aggregate.');
        }

        $integer = ltrim($match[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($match[3] ?? '', 4, '0');
        $mills = (int) substr($fraction, 0, 3);
        if ((int) $fraction[3] >= 5) {
            $mills++;
        }

        if ($mills === 1000) {
            $mills = 0;
            // Propagate a carry in decimal-string arithmetic: an aggregate
            // may be too large for a native PHP integer as well as a float.
            for ($index = strlen($integer) - 1; $index >= 0; $index--) {
                if ($integer[$index] !== '9') {
                    $integer[$index] = (string) ((int) $integer[$index] + 1);
                    break;
                }
                $integer[$index] = '0';
            }
            if ($index < 0) {
                $integer = '1'.$integer;
            }
        }

        $fractional = str_pad((string) $mills, 3, '0', STR_PAD_LEFT);
        $negative = $match[1] === '-' && ($integer !== '0' || $mills !== 0);

        return ($negative ? '-' : '').$integer.'.'.$fractional;
    }
}
