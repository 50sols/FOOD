<?php

namespace App\Enterprise\Finance;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\EntitlementScopeResolver;
use App\Enterprise\Entitlements\ModuleEntitlementResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Finance Workbench entitlement decisions use the exact operational store.
 *
 * The store lineage inherits legal-entity/company/platform grants but also
 * honors a more-specific store deny or capability restriction. Neither a
 * Sidebar link nor a broad legal-entity grant overrides that restriction.
 */
final class FinanceWorkbenchAccess
{
    /** @var list<string> */
    private const READ_CAPABILITIES = [
        'finance.foundation.read',
        'finance.gl.read',
        'finance.ar.read',
        'finance.ap.read',
    ];

    /**
     * AR_OFFICER is a receivables duty, not a general Finance actor. Its legacy
     * finance.manage grant must never become GL/AP/Foundation/Treasury authority
     * merely because a second role enables the B2B navigation context.
     *
     * Until incompatible multi-role combinations are resolved by the P127 SoD
     * policy, an active AR officer fails closed for other Finance capabilities.
     *
     * @var list<string>
     */
    private const AR_OFFICER_CAPABILITIES = [
        'finance.ar.read',
        'finance.ar.manage',
    ];

    public function __construct(
        private readonly ModuleEntitlementResolver $entitlements,
        private readonly EntitlementScopeResolver $scopes,
    ) {}

    public function canRead(User $actor, int $storeId): bool
    {
        if (! $actor->is_active
            || ! $this->hasFinancePermissionAtStore($actor, $storeId, 'finance.view')) {
            return false;
        }

        // A combined operational role must not turn the AR officer's shared
        // finance.view grant into a GL/AP/Foundation overview.
        if ($actor->hasRole('AR_OFFICER')) {
            // AR_OFFICER is a B2B company duty. A second Retail store role
            // cannot make that global duty a B2C receivables entitlement.
            return $this->isWholesaleStore($storeId)
                && $this->allowsAtStore($storeId, 'finance.ar.read');
        }

        foreach (self::READ_CAPABILITIES as $capability) {
            if ($this->allowsAtStore($storeId, $capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Finance tab or GET must be authorized for its exact capability, not
     * merely by the shared finance.view role grant. This is also the authority
     * used by a future AR-only controller/tab projection: an AR_OFFICER holding
     * a second Finance role never inherits GL/AP/Foundation disclosure.
     */
    public function canReadCapability(User $actor, int $storeId, string $capability): bool
    {
        if (! str_starts_with($capability, 'finance.')
            || ! str_ends_with($capability, '.read')
            || ! $this->canRead($actor, $storeId)) {
            return false;
        }

        if ($actor->hasRole('AR_OFFICER') && $capability !== 'finance.ar.read') {
            return false;
        }

        return $this->allowsAtStore($storeId, $capability);
    }

    public function canPerform(User $actor, int $storeId, string $permission, string $capability): bool
    {
        // P127 fail-closed duty boundary: finance.manage is shared with GL,
        // credit terms and Treasury, while AR_OFFICER is authorized for AR only.
        // Other role grants must not silently enlarge its Finance duties.
        if ($actor->hasRole('AR_OFFICER')
            && (! in_array($capability, self::AR_OFFICER_CAPABILITIES, true)
                || ! $this->isWholesaleStore($storeId))) {
            return false;
        }

        return $actor->is_active
            && $this->hasFinancePermissionAtStore($actor, $storeId, $permission)
            && $this->allowsAtStore($storeId, $capability);
    }

    /**
     * A standalone AR_OFFICER is deliberately excluded from the broad B2B
     * global-role allowlist. That should not erase its actual Receivables duty.
     * Accept the canonical global Finance permission only for this active
     * wholesale duty and only if neither a global nor store DENY applies.
     * This method never grants the actor any generic B2B, GL, AP, Treasury,
     * Retail or platform scope. Finance module and legal entity/store
     * entitlements are checked separately at the action capability boundary.
     */
    private function hasFinancePermissionAtStore(User $actor, int $storeId, string $permission): bool
    {
        if ($actor->hasPermission($permission, $storeId)) {
            return true;
        }

        if (! $actor->hasRole('AR_OFFICER')
            || ! in_array($permission, ['finance.view', 'finance.manage'], true)
            || ! $this->isWholesaleStore($storeId)
            || ! $actor->hasPermission($permission)) {
            return false;
        }

        return ($actor->permissionOverrideEffects($storeId)[$permission] ?? null) !== 'deny';
    }

    /**
     * An active B2B store is the only Finance context for a global AR_OFFICER.
     * Keep this check in the canonical Finance access service, not a Sidebar
     * projection, so direct HTTP callers cannot borrow Retail grants.
     */
    private function isWholesaleStore(int $storeId): bool
    {
        return $storeId > 0 && DB::table('stores as store')
            ->join('store_types as type', 'type.id', '=', 'store.store_type_id')
            ->where('store.id', $storeId)
            ->where('store.is_active', true)
            ->where('type.code', 'B2B')
            ->exists();
    }

    public function allowsAtStore(int $storeId, string $capability): bool
    {
        if ($storeId < 1) {
            return false;
        }

        $store = new EntitlementScope(EntitlementScope::STORE, $storeId);
        $legalEntity = null;

        foreach ($this->scopes->lineage($store) as $scope) {
            if ($scope->type === EntitlementScope::LEGAL_ENTITY) {
                $legalEntity = $scope;
                break;
            }
        }

        // Finance is a legal-entity ledger. A STORE-only entitlement must not
        // create Finance authority if the entity itself was never entitled.
        if ($legalEntity === null
            || ! $this->entitlements->allows(FinanceFoundationService::MODULE, $legalEntity, $capability)) {
            return false;
        }

        // A narrower STORE denial/restriction overrides parent entitlements.
        return $this->entitlements->allows(
            FinanceFoundationService::MODULE,
            $store,
            $capability,
        );
    }
}
