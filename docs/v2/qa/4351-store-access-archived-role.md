# V2 #4351 — archived active role revocation in StoreAccess

- Atomic P031 security leaf; parent #2782 / master #2503. Merge target Foodex_V2 ONLY.
- **Defect:** StoreAccess had three authorization paths requiring `roles.is_active` without requiring `roles.archived_at IS NULL`: global `hasGlobalStoreAccess`, scoped `canAccess`, and the shared `assignedStoreIdsQuery` used by `scopeStores`/`scopeStoreOwned`. This conflicts with canonical User::hasRole archived role semantics.
- **Fix:** all three StoreAccess role predicates now require `archived_at IS NULL`. Active-role access, B2C store boundary, persisted user status, Super Admin handling, and read-only semantics are preserved.
- **Seeded regression:** `V2StoreAccessArchivedRoleRevocationTest` proves active SUPER_ADMIN and B2C_STORE_ADMIN access, then DB archives the still-active role without refreshing actor and verifies denial via global authority, canAccess, scopeStores and scopeStoreOwned; verifies audit count unchanged.
- **Risk HIGH:** no schema or seed changes, no user/admin UI change. Requires exact-final-head Repository Policy, V2 Isolation, full Required CI incl Laravel shards and MySQL/Redis, technical security review and SHA-guarded merge before closing #4351.
- **Mobile impact:** Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No Flutter, main, release, tag, or production deployment.
