# FOODEX V2 P031 / #4343 — StoreAccess active-store privileged admission

## Security finding
`StoreAccess::canAccess(User, Store|int)` returned `true` immediately for users whose persisted roles include global active `SUPER_ADMIN`, without verifying that the requested store exists or remains active. This method is a store-access authorization predicate and must not grant authority to a disabled, deleted, or fabricated tenant ID.

## Scoped implementation
For the SUPER_ADMIN path only, validate the target store using a persisted `stores.id` + `is_active=true` query. Accept an Eloquent Store object or numeric ID and do not trust stale in-memory `Store::is_active`. Existing Super Admin global role activation check is unchanged; ordinary store-role path retains its existing persisted active user, role and store guards. This is a narrowly scoped security boundary, not a rewrite of `scopeStores()` or `scopeStoreOwned()`.

## Database-backed regression
`V2StoreAccessInactiveStoreAdmissionTest` seeds a real B2C store and SUPER_ADMIN, validates positive live store object and ID access, denies unknown store ID, directly deactivates persisted store while keeping stale active Eloquent object and confirms denial, reactivates and confirms access restored, verifies audit records unchanged. A normal B2C_STORE_ADMIN remains limited to assigned active stores and denies foreign/inactive stores.

## Acceptance and risk
REVIEW_RISK HIGH — privileged tenant admission. Run exact-final-HEAD Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate (Laravel shards, MySQL/Redis, localization) and security source review before SHA-guarded protected merge into `Foodex_V2`; close atomic #4343 only on verified postmerge code/tests/QA. Parent #2782 and master #2503 remain OPEN. MOBILE IMPACT — Customer: NO_DIRECT_IMPACT; Driver: NO_DIRECT_IMPACT; Van: NO_DIRECT_IMPACT. No Flutter, main, releases or production deployment.
