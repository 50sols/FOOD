# V2 #3701 — Catalog ownership and category slug validation AR/EN

**Target:** Foodex_V2 ONLY. Parent #2503 stays OPEN. This is a scoped Dashboard localization leaf, not an ERP/catalog feature completion or production release.

## Source behavior

Before this patch `CatalogManagementController::assignProduct` rejected a foreign `store_id` correctly after `authorizeCatalogAction`, but displayed only English. `assertSlugAvailable` likewise returned an English-only duplicate slug error even to Arabic actors. Neither is an authorization vulnerability. The existing `management.dashboard` middleware `EnsureManagementDashboardAccess` already determines app locale from the authenticated user's `ar/en` setting, and the controller already defines `msg($ar, $en)` for many other catalog messages.

Changed only the two `ValidationException` message expressions to use `msg`; unchanged validation keys (`store_id`, `slug`), HTTP 422, tenant/store scope, unit/product/category ownership, category uniqueness, status, payload and persistence flow. No route, view, Sidebar, database, migration, permission or service changes.

## Real regression and no-write controls

In existing `RetailAdminCatalogAuthorizationTest` with `RefreshDatabase`, create one genuine B2C store-owned product and category, send AR and EN authenticated POST JSON to the existing assign and category-create routes, verify exact localized HTTP 422 field errors, absence of a foreign product/store assignment or duplicate category, and assert a store-B-only actor cannot mutate store-A's product (HTTP 403). Existing positive B2C catalog CRUD and B2B→B2C denial tests remain.

## App impact matrix

- **Customer:** No public API, Flutter, product data/model or storefront behavior changed. Catalog owner invariant unchanged.
- **Driver:** No Driver app/API/dispatch changes, B2C DRIVER ONLY intact.
- **Van:** No Van app/API/custody/fulfillment changes, B2B VAN ONLY intact.
- Dashboard: Only user-facing validation messages change. Arabic/English parity improved; no RTL/LTR/accessibility/layout code changes.

REVIEW_RISK: HIGH (tenant ownership validation path): file-by-file same-account engineering `COMMENTED` audit, not independent GitHub APPROVED. Exact-final-HEAD Repository Policy, FOODEX V2 Isolation, PHP/Pint/PHPStan/security, Laravel full suite, AR/EN and MySQL/Redis fresh-install/upgrade/recovery Required CI terminal SUCCESS are mandatory prior to merge. Final staging/device/owner acceptance remains #2503. No main, tag, release or production.
