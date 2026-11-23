# P031 / #4308 — cross-channel consistency on global Order search

## Boundary and failure mode
Global Orders search uses authoritative `OperationalTenantScope::allowedStoreIds(actor, 'orders.view')` to protect assigned stores and permission overrides. A persisted Order could nevertheless have `channel=b2b` while the assigned store's type is `B2C` (for example, imported inconsistent rows). Store scope alone would surface that order and produce a B2B operations URL for a Retail-only administrator. This is a read-side consistency and cross-channel isolation defect; repairing stored data is **not** in this leaf.

## Resolution
`OrderGlobalSearchProvider` joins the authoritative `store_types` relation and applies `UPPER(orders.channel) = store_types.code` within its SQL query, **before LIMIT**. No synthesized channel, no unsafe fallback and no mutation. Existing active-store and role-based permissions, Restaurant-profile `B2C_STORE_ADMIN` exclusion (#4303), Super Admin legitimate path, literal-LIKE escaping, sorting, and URLs remain unchanged. A mismatched row is excluded for all roles, including Super Admin, pending proper data cleanup by the authorized owner.

## HTTP evidence
`V2CrossChannelGlobalOrderSearchTest`: create assigned B2C Retail store, foreign Retail store, valid and simulated legacy wrong-channel order; signed-in Retail and Super Admin AR/EN HTTP searches. Assert invalid row not disclosed even by reference-ID lookup, valid Retail result/URL returned under limit=1, foreign row not disclosed, and no GET mutation. Existing `EnterpriseGlobalSearchTest` and `V2RestaurantGlobalOrderSearchAdmissionTest` retain literal-match and Restaurant authorization tests.

## Release fence
HIGH security review: source, tests, QA file-by-file, exact-head Repository Policy, FOODEX V2 Isolation, FOODEX Required CI including four Laravel shards/MySQL Redis, protected SHA-guarded merge to `Foodex_V2` and source verification. Only #4308 closes after full acceptance; #2782/#2503 remain open. Customer: NO_DIRECT_IMPACT; Driver: NO_DIRECT_IMPACT; Van: NO_DIRECT_IMPACT (Dashboard-only search GET; existing API, dispatch, mobile, fulfillment unchanged). No main, tags, production or release.
