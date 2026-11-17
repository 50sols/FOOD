# V2 #3950 — Catalog workspace tabs and authoritative route preservation

## Scope and placement
- Parent UX #2750 (G009) and master #2503. Source leaf #3950 on `Foodex_V2` only; FEATURE FREEZE, no new feature, sidebar, route, module, product pricing service, or public API.
- Domain: Catalog & Pricing, operating Catalog Management `GET /admin/catalog`; canonical controller `CatalogManagementController::index`; canonical view `admin.catalog-management`.
- Role and authority: current `catalog.view`, channel, tenant/store and support-access checks remain server-owned through `visibleStoreIds()/catalogReadableStoreIds()`, plus all existing mutation guards. No role, entitlement or permission granted.
- Decision: **KEEP_EXISTING_TABS / refine**, not a second tab system. Six independently revisit-able existing GET `tab` siblings: Overview, Products, App Products, Categories, Attributes, Import. They are a PAGE_TAB workspace and remain one server-rendered panel per request, not six simultaneously displayed hidden forms.
- Product Add/Edit/Price/Image remains modal/record-context within Products. Catalog ZIP Import is a dependent **Preview → Validate → Commit** workflow inside Import and MUST NOT become disconnected tabs. Pricing override, discount, profitability and Draft/Publish gates are authoritative backend/service decisions, never reconstructed in the client.
- `Brands & Units` goes to the existing separate `admin.lookups.index` route and permitted Inventory goes to an existing module route. These are **context actions, not misleading Catalog page tabs**. No new menu item or direct-link-only capability.

## Path-by-path source review / no-overlap
| Source | Decision | Scope |
| --- | --- | --- |
| `backend/resources/views/admin/catalog-management.blade.php` | MODIFY | Reuse `.foodex-tabs/.foodex-tab` for existing six Catalog tabs, horizontally scroll without multirow wrapping, 44px min target, native link focus and `aria-current=page`, preserve normalized query filters and authorized `store_id/support_access` without record detail or stale paginator cursors; external links outside tab rail |
| `backend/app/Http/Controllers/Admin/CatalogManagementController.php` | MODIFY | Reject non-string array-like `tab` with 404 before any tab view; existing scalar allowlist/legacy store redirect, query/data/ownership and all POST handlers untouched |
| `backend/tests/Feature/V2CatalogTabsContextTest.php` | NEW | Authenticated AR/EN RTL/LTR six-tab HTTP and one active tab, store/search/filter URL retention, mobile structural CSS, exact import preview vs commit, forged-array tab, foreign-store, roleless/inactive deny, read-only no financial/product writes |
| `backend/resources/views/admin/commercial-dashboard.blade.php` | KEEP_EXISTING_TABS / NO EDIT | Existing Sales Control / Flash Offers / price lists navigation; #2559 / PR #3326 has active `CommercialDashboardController` ownership. Do not implement profit/discount policies in UX leaf |
| `backend/resources/views/admin/lookup-management.blade.php` | KEEP_EXISTING_TABS / NO EDIT | Existing Brands / Units tabs; single create modal and dependent lookup form remain together, with existing role/store/scope checks |
| `backend/resources/views/admin/system-lookups.blade.php` | NO_TABS_SINGLE_FLOW / NO EDIT | Existing system master-data administration, not a large independently revisit-able page grid |
| Pricing overrides, Discounts, Catalog pricing POST, Draft/Publish | EXISTING AUTHORITATIVE FLOWS / NO EDIT | No new controller, API, database decision, price calculation, discount or publish transition |

## UI/UX and security invariants
- Related Catalog workspaces use one row of existing FOODEX tabs. Native anchors work with keyboard/tab order and preserve visible focus; selected link carries `aria-current=page`. No JS tab switching or hidden pending financial/product actions.
- Responsive static CSS targets mobile 320/360/390/412/430, tablet 768/1024 and desktop 1280 via contained horizontal rail. Actual browser/device acceptance remains **NOT RUN**, deferred under #2750.
- Query links carry only **server-normalized** `product_*` and `category_*` filter values plus already selected `scopeParams`. Do not carry `product_detail`, `category_detail`, pagination cursor, import-preview token or POST action on tab switch. Server remains sole authority for store scope, sensitive fields, pricing and writes.
- AR/EN uses existing `catalog_completion.tabs.*` translations or existing explicit bilingual App Products text. RTL/LTR controlled by document direction. No new translation keys or untranslated status labels.
- No mobile-app source/API contract touched: Customer **NO_DIRECT_IMPACT**, Driver **NO_DIRECT_IMPACT**, Van **NO_DIRECT_IMPACT**. No claim of running Flutter tests; existing B2B VAN ONLY / B2C DRIVER ONLY invariant unaffected.
- `REVIEW_RISK: HIGH` (Catalog/pricing-adjacent), `TECHNICAL_REVIEW: PENDING exact-head tests and audit`. Not a claim that backend security/pricing workflows themselves are fully certified.

## Acceptance gates
- Positive and negative focused Laravel HTTP and request-query regressions, full affected backend CI, localization/structural contract, Repository Policy, V2 Isolation, Required CI **PENDING** until exact-final-HEAD verification.
- No source-complete/merged statement while CI or review remains pending. Merge only to `Foodex_V2` through original issue-scoped PR, then record tested SHA, run URLs, final integration SHA and `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` for #2750/#2503.
