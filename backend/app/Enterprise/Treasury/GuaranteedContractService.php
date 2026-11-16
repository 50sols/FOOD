<?php

namespace App\Enterprise\Treasury;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\EnterpriseDataScope;
use App\Support\EnterpriseScopeAuthorizer;
use App\Support\ResolvedTenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GuaranteedContractService
{
    public function __construct(
        private readonly EnterpriseScopeAuthorizer $authorizer,
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Read-only list of documentary instruments. This register is deliberately
     * NOT a payment, bank guarantee issuance, claim, collateral release or GL journal.
     */
    public function paginate(User $actor, ResolvedTenantContext $context, array $filters = []): LengthAwarePaginator
    {
        $scope = $this->scope($actor, $context, 'finance.view', 'treasury.payments.read');
        $filters = Validator::make($filters, [
            'instrument_type' => ['nullable', Rule::in(['bank_guarantee', 'secured_commercial_contract'])],
        ])->validate();

        return $this->scopedQuery($scope)
            ->when($filters['instrument_type'] ?? null, fn ($query, $type) => $query->where('instrument_type', $type))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /** Registers a pending, non-operational draft; cannot post, claim or release funds. */
    public function createDraft(User $actor, ResolvedTenantContext $context, array $attributes): int
    {
        $scope = $this->scope($actor, $context, 'finance.manage', 'treasury.payments.manage');
        $data = Validator::make($attributes, [
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'],
            'instrument_type' => ['required', Rule::in(['bank_guarantee', 'secured_commercial_contract'])],
            'direction' => ['nullable', Rule::in(['issued', 'received']), 'required_if:instrument_type,bank_guarantee'],
            'reference' => ['required', 'string', 'max:120'],
            'underlying_reference' => ['required', 'string', 'max:120'],
            'issuer_name' => ['nullable', 'string', 'max:190', 'required_if:instrument_type,bank_guarantee'],
            'applicant_name' => ['required', 'string', 'max:190'],
            'beneficiary_name' => ['required', 'string', 'max:190'],
            'security_description' => ['nullable', 'string', 'max:500', 'required_if:instrument_type,secured_commercial_contract'],
            'currency_code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'face_amount' => ['required', 'regex:/^(?!0+(?:\\.0{1,3})?$)\\d{1,15}(?:\\.\\d{1,3})?$/'],
            'effective_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after_or_equal:effective_date'],
        ])->validate();
        $data['currency_code'] = strtoupper($data['currency_code']);
        $data['reference'] = trim($data['reference']);
        $data['underlying_reference'] = trim($data['underlying_reference']);
        $data['face_amount'] = number_format((float) $data['face_amount'], 3, '.', '');
        if ($data['instrument_type'] === 'secured_commercial_contract') {
            $data['direction'] = null;
            $data['issuer_name'] = null;
        } else {
            $data['security_description'] = null;
        }
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $scope, $data, $key, $hash): int {
            $existing = $this->scopedQuery($scope)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => [__('validation.unique', ['attribute' => 'request'])]]);
                }

                return (int) $existing->id;
            }

            $id = (int) DB::table('enterprise_guaranteed_contracts')->insertGetId([
                'organization_id' => $scope->organizationId,
                'company_id' => $scope->companyId,
                'legal_entity_id' => $scope->legalEntityId,
                'store_id' => $scope->storeId,
                ...$data,
                'status' => 'draft',
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'created_by' => $actor->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('enterprise.treasury.guarantee_draft_created', $actor, null, null, [
                'store_id' => $scope->storeId,
                'guaranteed_contract_id' => $id,
                'instrument_type' => $data['instrument_type'],
            ]);

            return $id;
        }, 3);
    }

    private function scopedQuery(EnterpriseDataScope $scope): Builder
    {
        return DB::table('enterprise_guaranteed_contracts')
            ->where('organization_id', $scope->organizationId)
            ->where('company_id', $scope->companyId)
            ->where('legal_entity_id', $scope->legalEntityId)
            ->where('store_id', $scope->storeId);
    }

    private function scope(User $actor, ResolvedTenantContext $context, string $permission, string $capability): EnterpriseDataScope
    {
        abort_if($context->enterpriseScope === null, 403);
        $scope = $context->enterpriseScope;
        abort_unless($this->entitlements->allows(
            'treasury', new EntitlementScope(EntitlementScope::STORE, $scope->storeId), $capability,
        ), 403);
        $this->authorizer->authorize($actor, $context, $permission, 'store', $scope->storeId);

        return $scope;
    }
}
