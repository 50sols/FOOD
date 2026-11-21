# V2 #3910 — Public Retail store discovery query validation

Parent: #2733; master: #2503; target base: `Foodex_V2` ONLY.

## Source / placement
The existing public `GET /api/v1/stores` route in `backend/routes/api.php` serves guest Retail/B2C discovery. Reuse the exact route and `GuestStoreController::index`; no Dashboard/Sidebar/new screen or application navigation change. Preserve store-type join, active-store constraint, effective active service-zone matching, ordered results and 200 `data`/`meta` JSON.

The previous `(string) $request->query(...)` converted untrusted array filters to PHP `Array` warnings before any validation, and implicit `integer()` parsing accepted malformed pagination. Validate the query bag before SQL and return field-specific 422 errors with stable AR/EN messages for invalid explicit parameters. Empty optional location filters remain ignored; explicitly empty `page`/`per_page` are invalid instead of silently falling back to defaults. `page` is validated before Laravel pagination resolves it. No SQL, schema or write semantics changed.

## Scope / risk and acceptance
- `REVIEW_RISK: HIGH` due to public request-parsing/data exposure surface, despite read-only change.
- Positive real Laravel HTTP tests: default 20, valid scalar location filters, page/per_page, no-filter 200, active Retail only; inactive Retail and Wholesale excluded.
- Negative AR/EN tests: nested/array country/city/area, overlong text, malformed/oversized/array page and per_page -> 422 `errors.<field>` with no store data or internal PHP warnings. Store, zone and order rows unchanged.
- OpenAPI `GET /stores` explicitly documents optional scalar `country_code` (max 16), `city` and `area` (max 150 each), existing `Page` / `PerPage` validation (1–100 per page), and HTTP 422 `ValidationError`. The update is confined to the `/stores` operation: the merged #3908 notification and #3920 Retail catalog OpenAPI operations remain unchanged.
- Customer: `apps/customer_app/lib/core/api/b2c_catalog_api.dart` implements `HttpB2cCatalogApi.stores()` through existing `GET /api/v1/stores` and the same `B2cStore` JSON decoder. Valid guest discovery and 200 `data`/`meta` are unchanged; optional scalar filters are supported by the server, invalid crafted parameters now yield 422. No Customer Flutter source/DTO change.
- Driver: not affected; no driver entry or consumption of this public guest Retail stores route; no Driver API/Flutter changes.
- Van: not affected; no Van entry or consumption of this public guest Retail stores route; B2B VAN ONLY and B2C DRIVER ONLY invariant untouched.
- AR/EN field errors are localized through paired `backend/lang/{ar,en}/guest_store_query.php` keys.
- No setup/update ZIP, release, production, `main`, branch protection, financial/tenant mutation.
- Run PHP syntax, Pint, PHPStan, Laravel focused/full and GitHub exact-head Required CI including policy/V2 isolation/AR-EN/OpenAPI/MySQL, and file-by-file COMMENTED engineering review prior to merge.

## Evidence status
The pre-documentation code checkpoint `9b7304b941a4d44a171fc39a6dfaca3ae89e0832` passed Repository Policy, FOODEX V2 Isolation and Required CI (#43729158113, #43729158082, #43729158188), including Laravel and MySQL/Redis acceptance, but is **superseded** by the OpenAPI + documentation integration commit. A final exact-head CI, current-file HIGH technical review, merge and integration verification are required before closing this child. Do not claim Flutter test execution where no Flutter paths changed. Broader Customer/device/staging UAT remains in #2733/#2503.
