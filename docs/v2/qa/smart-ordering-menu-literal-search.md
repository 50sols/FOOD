# V2 #3623 — Smart Ordering Menu literal search

**Source:** P082 authorized View/Search; parent #2818 and V2 master #2503. **Integration only** `Foodex_V2`. No main, release, tag, cPanel or production action.

## Original defect and solution

The existing Smart Ordering Menu Builder exposes two authorized read-only search entry points:
- `admin.smart-ordering.menu.products` (JSON product picker): fixed `products.name`, `sku`, `barcode`, scoped by active same-store B2C catalog and `store_products`.
- `admin.smart-ordering.menu.index?q=...` (draft editor focus list): fixed localized names and public IDs across the owning site's draft revision menus/categories/items/modifier groups/options.

Both previously passed `%<query>%` as a bound SQL LIKE value. Binding prevented SQL identifier injection but did not prevent `%` and `_` from acting as wildcard characters, so literal search yielded same-scope false positives. Backslash depended on SQL dialect implicit escape behavior. Query semantics now use a single `literalLikeNeedle`: `! → !!`, `% → !%`, `_ → !_`; fixed-column parameter-bound `LIKE ? ESCAPE '!'`, portable on SQLite and MySQL/MariaDB. Backslash is a literal ordinary character with an explicit non-backslash escape marker. Column identifiers are source-owned, never user-controlled.

## Route, UX, authorization and impact

- **Domain:** Restaurant SaaS → Smart Ordering → Menu Builder; actor B2C restaurant-scoped authorized manager; entity active store product or draft menu object.
- **Placement:** REUSE existing Smart Ordering workspace/menu tab and product picker; no new route, Sidebar, modal, DTO, label, or permission. Search remains bounded: 25 product results; 6/6/6/4/4 per focus entity and overall 25 focus results. Sorting and draft/source visibility unchanged. Empty results unchanged.
- **Authorization:** existing `management.dashboard`, `RestaurantSaasStoreAccess`/site context, `smart_ordering.manage`, store/entitlement/readiness and draft revision ownership retained. Foreign store direct route denied; roleless denied. No mutation endpoints altered, no storage changes.
- **AR/EN / RTL/LTR / accessibility:** existing localized responsive editor retained; searches accept Arabic/English Unicode, no new text and no markup changes. No new UI primitives or navigation.
- **Customer:** no public API/DTO/Flutter or storefront behavior change; search is Dashboard-only. **Driver:** no mobile API/Flutter/dispatch change; B2C DRIVER ONLY unaffected. **Van:** no mobile API/Flutter/van assignment change; B2B VAN ONLY unaffected.

## Verification

New `backend/tests/Feature/V2SmartOrderingMenuLiteralSearchTest.php` exercises the actual authenticated HTTP routes with RefreshDatabase, AR/EN, literal percent/underscore/bang/backslash, ordinary Arabic/English, same-store lookalikes, unrelated store and roleless denial, exact picker IDs/draft revision focus IDs, and no read-side product/menu/audit mutations. Existing SmartOrderingMenuDomainTest and V2DigitalMenuRestaurantAuthorityTest establish reference route/site/permission and responsive UI behavior.

**Acceptance required before merge:** PHP syntax, Pint, static analysis, security/OpenAPI, full Laravel tests, localization AR/EN, MySQL/Redis where applicable, Repository Policy, V2 Isolation, Required CI on exact **final** PR HEAD all terminal SUCCESS, source inspection/review and SHA-guarded merge only to `Foodex_V2`. Never mistake top-level statuses alone for child job completion; never claim local tests or signed artifacts unless actually verified.

After verified integration, close only leaf #3623 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`, with merge SHA, exact tested SHA and run links. Keep #2818/#2503 OPEN for source-wide security, manual/staging UAT and final acceptance. No production release, installer or APK work in this search-only leaf.
