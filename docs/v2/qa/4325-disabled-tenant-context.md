# FOODEX V2 P031 / #4325 — revoked identity tenant context admission

## Security boundary
TenantContextResolver previously resolved Retail context via historical store-role assignments, enumerated Retail store IDs and allowed Wholesale context via global role assignments without a first-class User.is_active check. A disabled identity's historical role/store records must not be interpreted as an active tenant admission.

## Implementation
- `wholesale()` and `retail()` fail closed with 403 immediately for a disabled User, before any role/store/access-support lookup or audit.
- `retailStoreIds()` returns an empty list for a disabled User; `defaultRetailStoreId()` therefore returns null.
- No role/permission/store mapping is deleted, changed or recreated. Existing active-user access and explicit SUPER_ADMIN support audit remain.
- Pure backend security denial for previously revoked identities; no API response shapes, mobile UI, fulfillment paths or commercial permissions changed.

## Database-backed Feature regression
`V2DisabledTenantContextResolverTest` verifies seeded active Retail store user can resolve its context and enumerated/default store, then disabled user receives no store IDs/default and a 403 Retail context while persisted store-role assignment remains. Active B2B_ADMIN Wholesale role is permitted, disabled identity rejected with 403. Active SUPER_ADMIN explicit Retail support is allowed; after revocation support context 403, store enumeration empty and no additional audit records.

## Acceptance
REVIEW_RISK HIGH (identity and tenancy); require detailed file review, exact-final-head Repository Policy, V2 Isolation, Required CI including Laravel shards and MySQL/Redis. Protected SHA-guarded integration into `Foodex_V2` before closing #4325; parent #2782 and master #2503 stay OPEN. MOBILE IMPACT — Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT to Flutter source; only disabled identities receive stricter existing backend authorization. No `main`, releases, tags or production changes.

## Concurrent revocation and stale User models
The tenant entry points check both the supplied Eloquent User.is_active and the current persisted users.is_active row; a preloaded active model cannot remain an authority after offboarding through another DB writer. The regression `test_preloaded_active_actor_cannot_resolve_retail_after_database_revocation` deliberately performs a direct database revoke after constructing an active user, then asserts the stale actor gets no enumerated/default stores and cannot resolve Retail context (403). This preserves fail-closed behavior even before request/session caches are invalidated.
