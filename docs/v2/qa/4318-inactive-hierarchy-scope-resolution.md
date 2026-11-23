# V2 P031 / #4318 — Effective store scope revocation on inactive lineage

## Threat and canonical boundary
The merged #4312 guards **writes** at `OrganizationHierarchyService::assignStore`, but existing `enterprise_store_scopes` rows persist if a company, legal entity, business unit or organization is subsequently disabled. Previously `scopeForStore` returned those rows regardless of active hierarchy status. This read helper feeds enterprise-scope decisions in `EnterpriseSecurityAccessResolver`, Treasury scope contexts and Finance event scope resolution.

## Implementation
Join `enterprise_organizations` through the persisted company ownership, and require `is_active = true` for the business unit, legal entity, company and organization before returning the existing mapping. If an ancestor is disabled, return `null` as the canonical **effective** scope; do not delete/reassign the stored historical mapping. This is fail-closed read-side authority only. Active mappings retain the original IDs and shape. No new permissions or routes; no mutation/audit on read.

## Direct database regression
`V2InactiveHierarchyScopeResolutionTest` seeds an actual B2C store, assigns the canonical default enterprise scope, checks all four hierarchy levels independently for disable → null effective scope → re-enable restoring identical original scope, verifies the original mapping remains and no audit records are written. Another test covers an unscoped store. This is a backend-only security leaf, not full #2782 security acceptance.

## Integration and safety
REVIEW_RISK HIGH: upstream scope consumers may now fail closed on disabled enterprise ancestors, by design; historical mappings remain available in raw storage for recovery/audit. Require file-level security review, exact-head Repository Policy, FOODEX V2 Isolation, Required CI Laravel four shards, MySQL/Redis and localization. Guarded SHA merge to `Foodex_V2` only, then verify and close #4318. Parent #2782 and master #2503 remain OPEN. **MOBILE IMPACT — Customer: NO_DIRECT_IMPACT; Driver: NO_DIRECT_IMPACT; Van: NO_DIRECT_IMPACT.** No Flutter, mobile API, dispatch, releases, main or production changes.
