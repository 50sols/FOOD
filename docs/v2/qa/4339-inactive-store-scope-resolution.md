# FOODEX V2 / P031 #4339 — inactive store effective enterprise scope

## Independent authority gap
Merged #4318 makes `OrganizationHierarchyService::scopeForStore` fail closed when any enterprise ancestor is inactive. However the canonical read still returned a five-ID effective scope for an inactive **store** with a persisted `enterprise_store_scopes` mapping and active ancestors. That read is consumed by enterprise authorization and tenant context systems, which must not treat an inactive store as current scope authority.

## Implementation
Join the existing `stores` row through `enterprise_store_scopes.store_id` and require `stores.is_active = true` as part of the **same existing SQL SELECT**. Inactive or deleted stores yield `null`; no mappings or audit records are removed, mutated or recreated; active stores return identical scope IDs. No additional per-call database round trip or altered channel/roles/permissions. The four inactive-ancestor filters from #4318 remain intact.

## DB-backed acceptance
`V2InactiveStoreScopeResolutionTest` creates a real seeded B2C store, provisions its canonical enterprise mapping, verifies active resolution, directly deactivates the persisted store while keeping a stale Eloquent store in memory, requires null effective scope, checks historical mapping and audit count unchanged, reactivates and requires identical scope IDs, plus an unknown-store null case.

## Governance
REVIEW_RISK HIGH — tenant/ERP access. Require file-level technical review, exact-head Repository Policy, FOODEX V2 Isolation, FOODEX Required CI including four Laravel test shards, MySQL/Redis and localization. Only guarded SHA merge to `Foodex_V2`, then postmerge source evidence and close atomic #4339; #2782 and master #2503 remain OPEN. **MOBILE IMPACT:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT. No main, production, releases or Flutter changes.
