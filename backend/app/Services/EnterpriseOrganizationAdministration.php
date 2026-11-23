<?php

namespace App\Services;

use App\Enterprise\Organization\OrganizationHierarchyService;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\Store;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EnterpriseOrganizationAdministration
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationHierarchyService $hierarchy,
    ) {}

    public function createOrganization(array $data, Authenticatable $actor): EnterpriseOrganization
    {
        // Service callers, not only HTTP controllers, must respect the same
        // editable field boundary on unguarded Eloquent hierarchy models.
        $this->assertWritableRecordFields($data);

        return DB::transaction(function () use ($data, $actor): EnterpriseOrganization {
            $organization = EnterpriseOrganization::query()->create($data);
            $this->audit->record('enterprise.organization.created', $actor, $organization, null, $this->snapshot($organization));

            return $organization->refresh();
        });
    }

    public function updateOrganization(EnterpriseOrganization $organization, array $data, Authenticatable $actor): EnterpriseOrganization
    {
        $this->assertUpdateAllowed($organization, $data);

        return $this->update($organization, $data, $actor, 'enterprise.organization.updated');
    }

    public function createCompany(EnterpriseOrganization $organization, array $data, Authenticatable $actor): EnterpriseCompany
    {
        // Service callers, not only HTTP controllers, must respect the same
        // editable field boundary on unguarded Eloquent hierarchy models.
        $this->assertWritableRecordFields($data);

        $this->assertCreationParentActive($organization);

        return DB::transaction(function () use ($organization, $data, $actor): EnterpriseCompany {
            $company = $organization->companies()->create($data);
            $this->audit->record('enterprise.company.created', $actor, $company, null, $this->snapshot($company));

            return $company->refresh();
        });
    }

    public function updateCompany(EnterpriseCompany $company, array $data, Authenticatable $actor): EnterpriseCompany
    {
        $this->assertUpdateAllowed($company, $data);

        return $this->update($company, $data, $actor, 'enterprise.company.updated');
    }

    public function createLegalEntity(EnterpriseCompany $company, array $data, Authenticatable $actor): EnterpriseLegalEntity
    {
        // Service callers, not only HTTP controllers, must respect the same
        // editable field boundary on unguarded Eloquent hierarchy models.
        $this->assertWritableRecordFields($data);

        $this->assertCreationParentActive($company);

        return DB::transaction(function () use ($company, $data, $actor): EnterpriseLegalEntity {
            $entity = $company->legalEntities()->create($data);
            $this->audit->record('enterprise.legal_entity.created', $actor, $entity, null, $this->snapshot($entity));

            return $entity->refresh();
        });
    }

    public function updateLegalEntity(EnterpriseLegalEntity $entity, array $data, Authenticatable $actor): EnterpriseLegalEntity
    {
        $this->assertUpdateAllowed($entity, $data);

        return $this->update($entity, $data, $actor, 'enterprise.legal_entity.updated');
    }

    public function createBusinessUnit(EnterpriseLegalEntity $entity, array $data, Authenticatable $actor): EnterpriseBusinessUnit
    {
        // Service callers, not only HTTP controllers, must respect the same
        // editable field boundary on unguarded Eloquent hierarchy models.
        $this->assertWritableRecordFields($data);

        $this->assertCreationParentActive($entity);

        return DB::transaction(function () use ($entity, $data, $actor): EnterpriseBusinessUnit {
            $unit = $entity->businessUnits()->create($data);
            $this->audit->record('enterprise.business_unit.created', $actor, $unit, null, $this->snapshot($unit));

            return $unit->refresh();
        });
    }

    public function updateBusinessUnit(EnterpriseBusinessUnit $unit, array $data, Authenticatable $actor): EnterpriseBusinessUnit
    {
        $this->assertUpdateAllowed($unit, $data);

        return $this->update($unit, $data, $actor, 'enterprise.business_unit.updated');
    }

    public function assignStore(Store $store, EnterpriseBusinessUnit $unit, Authenticatable $actor): void
    {
        if (! $unit->is_active) {
            throw ValidationException::withMessages(['store' => [__('enterprise_organization.inactive_parent')]]);
        }

        // A live unit does not make an inactive legal entity, company or
        // organization eligible for new store scope assignments.
        $this->assertCreationParentActive($unit->legalEntity);

        try {
            $this->hierarchy->assignStore($store, $unit, $actor);
        } catch (\LogicException) {
            throw ValidationException::withMessages(['store' => [__('enterprise_organization.cross_scope_denied')]]);
        }
    }

    /**
     * Check parent and ancestor activation before both no-write Preview and
     * persisted child creation. Do not promise a successful setup preview for
     * a child that the authoritative Save service would reject.
     */
    public function assertCreationParentActive(Model $parent): void
    {
        if ($parent instanceof EnterpriseLegalEntity) {
            $parent->loadMissing('company.organization');
            $this->assertActive($parent);
            $this->assertActive($parent->company);
            $this->assertActive($parent->company->organization);

            return;
        }

        if ($parent instanceof EnterpriseCompany) {
            $parent->loadMissing('organization');
            $this->assertActive($parent);
            $this->assertActive($parent->organization);

            return;
        }

        if ($parent instanceof EnterpriseOrganization) {
            $this->assertActive($parent);

            return;
        }

        throw new \InvalidArgumentException('Unsupported enterprise organization parent');
    }

    /**
     * Enforce identical update preconditions for guided no-write Preview
     * and actual Save, including active descendants and assigned stores.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertUpdateAllowed(Model $model, array $data): void
    {
        $this->assertDefaultRoutingIdentityPreserved($model, $data);

        // A record's legal organization ancestry is immutable in a metadata
        // update. All three Eloquent models are unguarded, so a direct service
        // caller must not be able to re-parent a company, legal entity or
        // business unit by passing its foreign-key column to fill($data).
        $parentKey = match (true) {
            $model instanceof EnterpriseCompany => 'organization_id',
            $model instanceof EnterpriseLegalEntity => 'company_id',
            $model instanceof EnterpriseBusinessUnit => 'legal_entity_id',
            default => null,
        };
        $this->assertWritableRecordFields($data, $parentKey);
        if ($parentKey !== null && array_key_exists($parentKey, $data)) {
            // Comparing integer casts is not sufficient: values such as
            // "123suffix", "123.5" or 123.5 cast to 123, yet fill() would
            // persist the original untrusted value into an unguarded model.
            // Only the exact canonical positive identity can be accepted as
            // an unchanged parent for historical service callers.
            $suppliedParent = $data[$parentKey];
            $isCanonical = is_int($suppliedParent)
                || (is_string($suppliedParent)
                    && preg_match('/^[1-9][0-9]*$/D', $suppliedParent) === 1);

            if (! $isCanonical
                || (string) $suppliedParent !== (string) $model->getAttribute($parentKey)) {
                throw ValidationException::withMessages([
                    'parent' => [__('enterprise_organization.cross_scope_denied')],
                ]);
            }
        }

        // A direct service caller may rename an inactive legacy record
        // without submitting activation. Only harmless display metadata with
        // unchanged identity is eligible; all other payloads retain the
        // conservative hierarchy activation preconditions.
        if (! array_key_exists('is_active', $data)
            && array_diff(array_keys($data), ['name', 'code']) === []
            && (! array_key_exists('code', $data)
                || (string) $data['code'] === (string) $model->getAttribute('code'))) {
            return;
        }

        if ((bool) ($data['is_active'] ?? true)) {
            // An inactive child cannot be reactivated beneath a disabled
            // parent or ancestor. Preview and Save share this preflight, and
            // direct service callers must not bypass it.
            $parent = match (true) {
                $model instanceof EnterpriseCompany => $model->organization,
                $model instanceof EnterpriseLegalEntity => $model->company,
                $model instanceof EnterpriseBusinessUnit => $model->legalEntity,
                default => null,
            };

            if (! $model instanceof EnterpriseOrganization) {
                if (! $parent instanceof Model) {
                    throw ValidationException::withMessages([
                        'parent' => [__('enterprise_organization.inactive_parent')],
                    ]);
                }

                $this->assertCreationParentActive($parent);
            }

            return;
        }

        // Legacy imports can contain inactive intermediate parents with active
        // descendants. Refuse deactivation until the entire lower hierarchy is
        // inactive; checking only immediate children would orphan live scopes.
        // An inactive legacy unit may still own store assignments, which also
        // prohibit deactivating any ancestor until the scope is reassigned.
        $hasActiveChildren = match (true) {
            $model instanceof EnterpriseOrganization => $model->companies()
                ->where(function ($query): void {
                    $query->where('is_active', true)
                        ->orWhereHas('legalEntities', function ($entities): void {
                            $entities->where('is_active', true)
                                ->orWhereHas('businessUnits', fn ($units) => $units->where('is_active', true)
                                    ->orWhereHas('storeScopes'));
                        });
                })->exists(),
            $model instanceof EnterpriseCompany => $model->legalEntities()
                ->where(function ($query): void {
                    $query->where('is_active', true)
                        ->orWhereHas('businessUnits', fn ($units) => $units->where('is_active', true)
                            ->orWhereHas('storeScopes'));
                })->exists(),
            $model instanceof EnterpriseLegalEntity => $model->businessUnits()
                ->where(fn ($query) => $query->where('is_active', true)->orWhereHas('storeScopes'))
                ->exists(),
            default => false,
        };

        if ($hasActiveChildren) {
            throw ValidationException::withMessages(['is_active' => [__('enterprise_organization.active_children')]]);
        }

        if ($model instanceof EnterpriseBusinessUnit && $model->storeScopes()->exists()) {
            throw ValidationException::withMessages(['is_active' => [__('enterprise_organization.assigned_stores')]]);
        }
    }

    /**
     * These four hierarchy models are intentionally unguarded for import/
     * seed relations. Service methods must never mass-assign primary keys,
     * timestamps, tenant metadata or other unreviewed fields. Updates may
     * present their unchanged parent key for compatibility, but the
     * ancestry invariant above still rejects any actual reparenting.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertWritableRecordFields(array $data, ?string $unchangedParentKey = null): void
    {
        $writable = ['code', 'name', 'is_active'];
        if ($unchangedParentKey !== null) {
            $writable[] = $unchangedParentKey;
        }

        if (array_diff(array_keys($data), $writable) !== []) {
            throw ValidationException::withMessages([
                'record' => [__('enterprise_organization.cross_scope_denied')],
            ]);
        }
    }

    /**
     * Keep the migrated default assignment path reachable while allowing
     * operators to correct scaffold display names in Administration Hub.
     * Apply this to the service too: non-HTTP callers may not bypass it.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertDefaultRoutingIdentityPreserved(Model $model, array $data): void
    {
        $protected = $model instanceof EnterpriseOrganization
            && $model->code === OrganizationHierarchyService::DEFAULT_ORGANIZATION;

        if ($model instanceof EnterpriseBusinessUnit
            && $model->code === OrganizationHierarchyService::DEFAULT_BUSINESS_UNIT) {
            $model->loadMissing('legalEntity.company.organization');
            $protected = $model->legalEntity->company->organization->code
                === OrganizationHierarchyService::DEFAULT_ORGANIZATION;
        }

        if (! $protected) {
            return;
        }

        if (isset($data['code']) && $data['code'] !== (string) $model->getAttribute('code')) {
            throw ValidationException::withMessages([
                'code' => [__('enterprise_organization.default_routing_identity_protected')],
            ]);
        }

        if (! (bool) ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages([
                'is_active' => [__('enterprise_organization.default_routing_identity_protected')],
            ]);
        }
    }

    private function assertActive(Model $model): void
    {
        if (! (bool) $model->getAttribute('is_active')) {
            throw ValidationException::withMessages(['parent' => [__('enterprise_organization.inactive_parent')]]);
        }
    }

    private function update(Model $model, array $data, Authenticatable $actor, string $event): mixed
    {
        return DB::transaction(function () use ($model, $data, $actor, $event) {
            $before = $this->snapshot($model);
            $model->fill($data)->save();
            $this->audit->record($event, $actor, $model, $before, $this->snapshot($model));

            return $model->refresh();
        });
    }

    private function snapshot(Model $model): array
    {
        return [
            'code' => (string) $model->getAttribute('code'),
            'name' => (string) $model->getAttribute('name'),
            'is_active' => (bool) $model->getAttribute('is_active'),
        ];
    }
}
