<?php

namespace App\Enterprise\Wms;

use App\Models\User;
use App\Services\OperationalTenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administrator-configurable future legal-title *rules*, never a title certificate.
 *
 * A reviewed policy describes what evidence should be required. It cannot
 * certify an inventory position without an independently verified signed
 * contract and an immutable event-by-event title/provenance attestation.
 */
final class InventoryOwnershipRuleService
{
    public function __construct(private readonly OperationalTenantScope $stores) {}

    public function propose(User $actor, array $input): object
    {
        $data = Validator::make($input, [
            'store_id' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:B2B,B2C'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'supplier_document_id' => ['nullable', 'integer', 'min:1'],
            'initial_owner_kind' => ['required', 'in:FOODEX,SUPPLIER,UNKNOWN'],
            'title_transfer_trigger' => ['required', 'in:NONE,RECEIPT,SALE,DISPATCH,PAYMENT,CONTRACT_EVENT'],
            'required_evidence_classes' => ['required', 'array', 'min:1', 'max:8'],
            'required_evidence_classes.*' => ['required', 'in:SIGNED_CONTRACT,CONSIGNMENT_AGREEMENT,TITLE_ADDENDUM,TRANSFER_CONFIRMATION'],
            // A policy maker must not nominate a weak generic permission (e.g.
            // inventory.view) as the legal-title checker authority.
            'checker_permission' => ['required', 'in:inventory_ownership_rules.review'],
            'effective_at' => ['required', 'date'],
            'request_uuid' => ['required', 'uuid'],
            'rule_uuid' => ['nullable', 'uuid'],
        ])->validate();

        $storeId = (int) $data['store_id'];
        $this->authorize($actor, $storeId, 'inventory_ownership_rules.manage');
        $companyId = $this->companyForStore($storeId);
        $this->assertChannel($storeId, $data['channel']);

        $supplierId = isset($data['supplier_id']) ? (int) $data['supplier_id'] : null;
        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        $documentId = isset($data['supplier_document_id']) ? (int) $data['supplier_document_id'] : null;
        if ($data['initial_owner_kind'] === 'SUPPLIER' && $supplierId === null) {
            throw ValidationException::withMessages(['supplier_id' => ['A supplier-owned rule requires a scoped supplier.']]);
        }
        if ($supplierId !== null) {
            abort_unless(DB::table('enterprise_suppliers')->where('id', $supplierId)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->where(function ($scope) use ($storeId): void {
                    $scope->where(function ($company): void {
                        $company->where('scope_type', 'company')->whereNull('store_id');
                    })->orWhere(function ($store) use ($storeId): void {
                        $store->where('scope_type', 'store')->where('store_id', $storeId);
                    });
                })->exists(), 404);
        }
        if ($warehouseId !== null) {
            abort_unless(DB::table('warehouses')->where('id', $warehouseId)
                ->where('store_id', $storeId)->where('is_active', true)->exists(), 404);
        }
        if ($documentId !== null) {
            abort_unless($supplierId !== null
                && DB::table('enterprise_supplier_documents')->where('id', $documentId)
                    ->where('supplier_id', $supplierId)->exists(), 404);
        }
        if ($data['initial_owner_kind'] !== 'SUPPLIER' && $supplierId !== null) {
            throw ValidationException::withMessages(['supplier_id' => ['Supplier scope requires supplier-owned title intent.']]);
        }
        if (strtotime($data['effective_at']) < now()->startOfDay()->timestamp) {
            throw ValidationException::withMessages(['effective_at' => ['Retroactive ownership rules are not permitted.']]);
        }

        $evidenceClasses = array_values(array_unique($data['required_evidence_classes']));
        sort($evidenceClasses);
        $snapshot = [
            'company_id' => $companyId,
            'store_id' => $storeId,
            'channel' => $data['channel'],
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouseId,
            'supplier_document_id' => $documentId,
            'initial_owner_kind' => $data['initial_owner_kind'],
            'title_transfer_trigger' => $data['title_transfer_trigger'],
            'required_evidence_classes' => $evidenceClasses,
            'checker_permission' => $data['checker_permission'],
            'effective_at' => date('Y-m-d H:i:s', strtotime($data['effective_at'])),
        ];
        $sha = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        $requestUuid = (string) $data['request_uuid'];
        $ruleUuid = (string) ($data['rule_uuid'] ?? Str::uuid());

        return DB::transaction(function () use ($actor, $snapshot, $sha, $requestUuid, $ruleUuid): object {
            $existing = DB::table('enterprise_inventory_ownership_rule_revisions')
                ->where('request_uuid', $requestUuid)->first();
            if ($existing !== null) {
                if ($existing->payload_sha256 !== $sha || (int) $existing->proposed_by !== (int) $actor->id) {
                    throw ValidationException::withMessages(['request_uuid' => ['Conflicting policy request replay.']]);
                }

                return $existing;
            }

            $latest = DB::table('enterprise_inventory_ownership_rule_revisions')
                ->where('rule_uuid', $ruleUuid)->orderByDesc('revision')->lockForUpdate()->first();
            if ($latest !== null && ((int) $latest->store_id !== $snapshot['store_id']
                || $latest->channel !== $snapshot['channel'])) {
                throw ValidationException::withMessages(['rule_uuid' => ['Policy revisions cannot move between stores or channels.']]);
            }

            $insert = [
                ...$snapshot,
                'required_evidence_classes' => json_encode($snapshot['required_evidence_classes'], JSON_THROW_ON_ERROR),
                'rule_uuid' => $ruleUuid,
                'revision' => ($latest === null ? 1 : (int) $latest->revision + 1),
                'status' => 'DRAFT',
                'request_uuid' => $requestUuid,
                'payload_sha256' => $sha,
                'proposed_by' => (int) $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            DB::table('enterprise_inventory_ownership_rule_revisions')->insertOrIgnore($insert);
            $created = DB::table('enterprise_inventory_ownership_rule_revisions')
                ->where('request_uuid', $requestUuid)->first();
            if ($created === null || $created->payload_sha256 !== $sha
                || (int) $created->proposed_by !== (int) $actor->id) {
                throw ValidationException::withMessages(['request_uuid' => ['Conflicting concurrent policy revision.']]);
            }

            return $created;
        });
    }

    public function review(User $checker, int $revisionId, bool $approve): object
    {
        return DB::transaction(function () use ($checker, $revisionId, $approve): object {
            $query = DB::table('enterprise_inventory_ownership_rule_revisions');
            $rule = $query->where('id', $revisionId)->lockForUpdate()->first();
            abort_if($rule === null, 404);
            $this->authorize($checker, (int) $rule->store_id, (string) $rule->checker_permission);
            // An approved policy cannot cross a later store legal-company or
            // channel reassignment even when its stored store_id is unchanged.
            abort_unless((int) $rule->company_id === $this->companyForStore((int) $rule->store_id), 409);
            $this->assertChannel((int) $rule->store_id, (string) $rule->channel);
            abort_if((int) $rule->proposed_by === (int) $checker->id, 403);
            $target = $approve ? 'APPROVED_RULE' : 'REJECTED';
            if ($rule->status !== 'DRAFT') {
                abort_unless($rule->status === $target && (int) $rule->reviewed_by === (int) $checker->id, 409);

                return $rule;
            }
            if ($approve) {
                // Serialize approvals for a store before checking competing
                // approved policies; concurrent reviewers must not both win.
                DB::table('stores')->where('id', $rule->store_id)->lockForUpdate()->first();
                abort_if(strtotime((string) $rule->effective_at) < now()->startOfDay()->timestamp, 409);
                // Different policy identities may not occupy an identical
                // scope. Same identity uses monotonically increasing revisions.
                $conflict = DB::table('enterprise_inventory_ownership_rule_revisions')
                    ->where('status', 'APPROVED_RULE')
                    ->where('store_id', $rule->store_id)
                    ->where('channel', $rule->channel)
                    ->where('rule_uuid', '<>', $rule->rule_uuid);
                foreach (['supplier_id', 'warehouse_id', 'supplier_document_id'] as $field) {
                    $conflict = $rule->$field === null ? $conflict->whereNull($field) : $conflict->where($field, $rule->$field);
                }
                abort_if($conflict->exists(), 409);
            }
            DB::table('enterprise_inventory_ownership_rule_revisions')->where('id', $revisionId)->update([
                'status' => $target,
                'reviewed_by' => (int) $checker->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('enterprise_inventory_ownership_rule_revisions')->where('id', $revisionId)->first();
        });
    }

    /**
     * Rules are *not* stock-title evidence. Return the proposed policy
     * separately and always deny authoritative certification from this source.
     *
     * @return array{state:string,certified:bool,policy:?object}
     */
    public function advisoryFor(User $actor, int $storeId, string $channel, ?int $supplierId, ?int $warehouseId): array
    {
        $this->authorize($actor, $storeId, 'inventory.view');
        // A store's legal entity can change after a rule was approved.
        // Never serve a previous company's policy as current title guidance.
        $this->assertChannel($storeId, $channel);
        $companyId = $this->companyForStore($storeId);
        $rules = DB::table('enterprise_inventory_ownership_rule_revisions')
            ->where('company_id', $companyId)
            ->where('store_id', $storeId)->where('channel', $channel)
            ->where('status', 'APPROVED_RULE')
            ->where('effective_at', '<=', now())
            ->where(function ($q) use ($supplierId): void {
                $q->whereNull('supplier_id');
                if ($supplierId !== null) {
                    $q->orWhere('supplier_id', $supplierId);
                }
            })->where(function ($q) use ($warehouseId): void {
                $q->whereNull('warehouse_id');
                if ($warehouseId !== null) {
                    $q->orWhere('warehouse_id', $warehouseId);
                }
            })->orderByDesc('revision')->limit(100)->get();

        $ranked = $rules->map(fn ($r) => [
            'rule' => $r,
            'specificity' => ($r->supplier_id !== null ? 2 : 0) + ($r->warehouse_id !== null ? 1 : 0),
        ])->sortByDesc('specificity')->values();
        $first = $ranked->first();
        $ambiguous = $first !== null && $ranked->count() > 1
            && $ranked[1]['specificity'] === $first['specificity']
            && $ranked[1]['rule']->rule_uuid !== $first['rule']->rule_uuid;

        return ['state' => $ambiguous ? 'CONFLICT' : 'UNKNOWN', 'certified' => false,
            'policy' => $ambiguous ? null : ($first['rule'] ?? null)];
    }

    private function authorize(User $actor, int $storeId, string $permission): void
    {
        abort_unless($actor->hasRole('SUPER_ADMIN') || $actor->hasPermission($permission, $storeId), 403);
        $this->stores->assertStore($actor, $storeId, 'inventory.view');
    }

    private function companyForStore(int $storeId): int
    {
        $companyId = DB::table('enterprise_store_scopes as scope')
            ->join('enterprise_business_units as unit', 'unit.id', '=', 'scope.business_unit_id')
            ->join('enterprise_legal_entities as entity', 'entity.id', '=', 'unit.legal_entity_id')
            ->where('scope.store_id', $storeId)->value('entity.company_id');
        abort_if($companyId === null, 422);

        return (int) $companyId;
    }

    private function assertChannel(int $storeId, string $channel): void
    {
        $storeType = strtoupper((string) DB::table('stores as store')
            ->join('store_types as kind', 'kind.id', '=', 'store.store_type_id')
            ->where('store.id', $storeId)->value('kind.code'));
        abort_unless(match ($channel) {
            'B2B' => in_array($storeType, ['WHOLESALE', 'B2B'], true),
            'B2C' => in_array($storeType, ['RETAIL', 'B2C'], true),
            default => false,
        }, 422);
    }
}
