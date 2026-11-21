# V2 #3683 — B2B Sales Control product picker literal search

**Issue:** #3683; parents #2818 (P082), #2503. **Integration:** `Foodex_V2` only. No main, release, APK, tag, production, cPanel or deployment.

## Source evidence and bounded fix

Before this fix, the existing `CommercialDashboardController::searchSalesControlProducts` query interpolated the trimmed operator search `q` between SQL `%` wildcards and used implicit LIKE. Entering a literal percent or underscore returned unrelated authorized products rather than exact punctuation matches. This was a **search-filter correctness** defect, **not** a proven SQL injection, unauthorized-store access or data mutation. The existing store/channel filter remained authoritative.

The same canonical route `admin.commercial.sales-control.products` now composes a literal *substring* pattern by escaping `!`→`!!`, `%`→`!%`, and `_`→`!_`, preserving backslashes, then adds only intentional leading/trailing `%` anchors. Both fixed qualified columns, `products.name` and `products.sku`, use bound `LIKE ? ESCAPE '!'` predicates beneath the original parenthesized OR, store and `catalogs.channel = b2b` filters. The result remains ordered by product name and id, capped at 30, with the original JSON schema. Empty q is intentionally unfiltered within the authorized store.

The existing managed entity picker is reused through the shared Dashboard Entity Lookup Contract. This patch does not touch the picker JavaScript, Blade view, sidebar, locale strings, endpoint route, permission definition, schema or write flows.

## Real fixture regression

`backend/tests/Feature/CommercialDashboardContractTest.php::test_b2b_sales_control_product_picker_matches_literal_symbols_without_widening_store_scope`

- `RefreshDatabase` + `CoreReferenceSeeder`, B2B manager and real wholesale Store→Catalog→Product / StoreProduct rows.
- Literal punctuation fixture in a product **name** and a separate product **SKU**, with `%`, `_`, `!`, backslash; a normal decoy that must not appear in punctuation searches.
- Foreign B2B store and B2C Retail store products with matching text excluded by the explicitly selected B2B store/channel constraints; roleless and Retail actors denied by existing `catalog.view`/channel scope.
- Substring positive and negative matches across both columns, empty-query authorized results, no insert/update/audit rows caused by lookup requests.
- Run focused `php artisan test --filter=test_b2b_sales_control_product_picker_matches_literal_symbols_without_widening_store_scope` from `backend/`, then exact final SHA Required CI including full Laravel, PHP syntax, Pint, PHPStan, security, AR/EN, MySQL/Redis and immutable Enterprise Performance, plus separate Repository Policy and V2 Isolation Gate.

## Surface / security / release classification

| Surface | Classification |
| --- | --- |
| Backend | Existing Dashboard JSON read-only product lookup query corrected |
| Dashboard | Existing B2B Sales Control picker result correctness improved; no new visual/AR/EN/RTL/LTR/responsive elements |
| Customer | Unaffected: no Customer app, public API, identity, checkout or price model changes |
| Driver | Unaffected: no Driver Flutter/API/assignment change |
| Van | Unaffected: no Van Flutter/API/stock/dispatch change |

B2B fulfillment stays **VAN ONLY**, B2C fulfillment stays **DRIVER ONLY**. No new migration, content package, update ZIP or changed authentication/authorization. Read-only fix is revertible without altering persisted data.

**REVIEW_RISK: HIGH** due to tenant-authorized catalog reads. Require detailed source/test/scope technical `COMMENTED` review (same GitHub account does not impersonate independent approval), and **all** exact-final-head CI GREEN before SHA-guarded squash merge into `Foodex_V2`. Close #3683 only after validated merge as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; keep #2818 and #2503 open for consolidated manual/staging acceptance.
