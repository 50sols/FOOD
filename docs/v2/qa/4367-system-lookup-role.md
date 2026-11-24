# V2 #4367 — System Lookup archived-role and persisted identity revocation

Atomic P031 backend security leaf under #2782 / #2503. Target `Foodex_V2` only.

## Defect
`SystemLookupController::authorizeView` allowed a store-role fallback based on the active role and `lookups.view` but omitted an archived-role check, role scope check, and persisted active actor check. A stale loaded actor or an archived-but-active role could retain the read admission.

## Fix and tests
- Before checking SUPER_ADMIN, Gate or scoped fallback, require an active persisted User plus the active loaded actor, to prevent revoked cached identities from entering the surface.
- Scoped fallback also requires an active, unarchived `roles.scope=store` role and `lookups.view` permission.
- `V2ArchivedSystemLookupAdmissionTest`: seeded Retail assignment, positive access, DB archive (is_active kept true), stale/fresh denial, persisted user deactivation/reactivation and stale SUPER_ADMIN denial, no audit modifications in archival scenario. Uses direct authorization method invocation to isolate the controller authorization guard without coupling to sidebar/middleware.

## Acceptance
HIGH technical security review plus exact-head Repository Policy, V2 Isolation and full Required CI including Laravel 4 shards and MySQL/Redis; protected SHA-guarded squash merge followed by source verification. No Flutter, main, release, tag or production changes. Mobile impact: Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT.
