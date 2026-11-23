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

## Default-scoping regression
`test_default_scope_provisioning_rejects_inactive_default_organization` disables the seeded default organization and calls `ensureDefaultScopeForStore` for a new B2B store. It asserts a fail-closed exception with no `enterprise_store_scopes` or audit write, proving the automatic path cannot bypass the canonical active-ancestry writer. This case must pass in the exact-head Laravel and MySQL acceptance workflows before closeout.

## Fresh-authority checks for stale in-memory Eloquent relations
The canonical writer now refreshes the BusinessUnit row and loads the complete legal entity/company/organization chain from persistent storage before deciding eligibility. `loadMissing()` was insufficient for callers that had cached active parent relations before an administrator or another connection disabled an ancestor. `test_preloaded_active_ancestry_cannot_bypass_a_later_database_deactivation` and `test_stale_active_unit_object_cannot_bypass_current_disabled_unit` exercise both stale-parent and stale-unit bypass vectors without any scope/audit write. Require new exact-head CI and HIGH security review; no attempt to rewrite historical scopes.
