# FOODEX V2 P031 / #4312 — fail-closed canonical store-scope writer

## Defect and scope
`OrganizationHierarchyService::assignStore()` is the canonical writer of `enterprise_store_scopes`; `ensureDefaultScopeForStore()` calls it too. The partial Administration Hub work in #2846 adds its own active-ancestor gate but cannot protect other direct callers. Before this change, a disabled business unit, legal entity, company or organization could still receive a store-scope assignment at this lower-level service.

## Enforcement
The canonical writer loads the business-unit → legal-entity → company → organization relation and rejects any inactive component with `LogicException` **before** checking/updating the existing scope or writing Audit. The service already enforces non-silent cross-organization moves; that check and the valid idempotent path are preserved. This change does not retrospectively delete or rewrite existing mappings and does not modify Dashboard, storefront, mobile API, fulfillment, or tenancy rules.

## Executable regression evidence
`V2InactiveHierarchyStoreAssignmentTest` covers:
- Direct writer denial for each inactive ancestry tier, with missing new scope and unchanged audit log count.
- Successful assignment with all tiers active and idempotent re-use without duplicate audit.
- Existing scope remains unchanged and no new audit is written when an ancestor is disabled.

Use exact-head Repository Policy, V2 Isolation, FOODEX Required CI (Laravel shards / MySQL+Redis / backend security), and file-level HIGH review. Merge only through guarded PR targeting `Foodex_V2`, verify integration and close this **leaf** only after all gates pass. Parent #2782/#2503 and partial #2846 remain open. Customer / Driver / Van: NO_DIRECT_IMPACT. No main, releases, production deployment.
