# V2 #3631 — literal scoped Smart Ordering Supply product search

**Source:** P082 View/Search authority, parent #2818, master #2503. **Integration target:** `Foodex_V2` only.

## Defect and bounded implementation

Existing Restaurant SaaS → Smart Ordering → Supply Intelligence GET `admin.smart-ordering.supply.index` reads `q` and delegates to `SupplyIntelligenceService::foodexProducts`. The prior parameter-bound `LIKE '%q%'` over fixed `products.name`, `products.sku`, `products.barcode` was injection-safe but interpreted user-supplied `%` and `_` as SQL wildcards, and implicit backslash semantics differ by database. Product picker matching is now a literal substring using a fixed, parameter-bound `LIKE ? ESCAPE '!'` and a single `str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query)` pattern, preserving literal backslashes.

No other `SupplyIntelligenceService` paths or calculations changed: `WholesalePrincipal::storeId` B2B platform store scope, `catalogs.channel=b2b`, active/quarantine filters, `store_products` active, ordering, 1–500 result bound, price and live stock still apply. No writes or new API parameters.

## Placement, role and authority

- **REUSE** existing Smart Ordering Supply Intelligence product picker/search, not another Sidebar or route. `q` remains a legitimate free-text filter.
- Existing `SmartOrderingSupplyController::authorizeSite` guards the Restaurant store/site + `smart_ordering.view`; `assertEntitled` remains the Supply Intelligence module gate, including direct requests. No changes to authorization, tenant selection, entitlement, readiness, finance, stock ownership, or mutating endpoints.
- Existing AR/EN and responsive RTL/LTR template is untouched. No text, keys, components, layouts or navigation changed.

## Automated verification

Extended existing `backend/tests/Feature/SmartOrderingSupplyIntelligenceTest.php` (real seeded `RefreshDatabase` B2B/B2C, products, catalogs, stores, stock and Supply service). Covers literal `%_!` in Arabic product name, `SKU_!` and `BC%!`, literal backslash, ordinary Arabic/English substrings, same-store wildcard lookalike, foreign Retail/B2C catalog and inactive product exclusion, non-mutating products/store_products/orders/audit table counts and bounded `limit=1`. Existing `SmartOrderingSupplyIntelligenceTest` covers supply stock/mappings/draft safety, and existing Restaurant SaaS route/entitlement suites retain authoritative HTTP guards; no permission code changed.

**Applicability:** PHP syntax, Pint, PHPStan, Composer security, OpenAPI, full Laravel feature tests, AR/EN and MySQL/Redis deployment acceptance are required when selected by diff/CI. Record exact final-head Policy, V2 Isolation, terminal Required CI and actual jobs after GitHub Actions finish. Never infer success from a pending or unrelated run.

## Surface impact matrix

| Surface | Classification | Reason |
| --- | --- | --- |
| Backend | Affected — read-only SQL matching | Bound B2B catalog product lookup, no change to stock/write/RBAC |
| Dashboard | Affected — results only | Existing product search returns literal text matches, no visual/route changes |
| Customer | Not affected | No public API/DTO, customer pricing or Flutter change |
| Driver | Not affected | No delivery/status/GPS/role/Flutter contract change |
| Van | Not affected | No B2B routing, consignment, inventory mutation, Van API or Flutter change |

B2B = VAN ONLY and B2C = DRIVER ONLY preserved.

## Closure gates

One original branch `fix/3631-v2-smart-supply-literal-search`, one issue-scoped PR to `Foodex_V2` exclusively; verify source/CI/reviews/branch protection, merge only with exact-final-head required checks terminal green and rules satisfied. After a verified merge, close #3631 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with source SHA, merge SHA and CI URLs. Parent #2818 and master #2503 remain OPEN for broader P082 coverage and consolidated staging/human UAT. No `main`, production, tags, release publication, APKs or cPanel actions.
