# V2 #3910 — Public Retail store discovery query validation

Parent: #2733; master: #2503; target base: `Foodex_V2` ONLY.

## Source / placement
The existing public `GET /api/v1/stores` route in `backend/routes/api.php` serves guest Retail/B2C discovery. Reuse the exact route and `GuestStoreController::index`; no Dashboard/Sidebar/new screen or application navigation change. Preserve store-type join, active-store constraint, effective active service-zone matching, ordered results and 200 `data`/`meta` JSON.

The previous `(string) $request->query(...)` converted untrusted array filters to PHP `Array` warnings before any validation, and implicit `integer()` parsing accepted malformed pagination. Validate the query bag before SQL and return field-specific 422 errors with stable AR/EN messages for invalid explicit parameters. Empty optional location filters remain ignored; explicitly empty `page`/`per_page` are invalid instead of silently falling back to defaults. `page` is validated before Laravel pagination resolves it. No SQL, schema or write semantics changed.

## Scope / risk and acceptance
- `REVIEW_RISK: HIGH` due to public request-parsing/data exposure surface, despite read-only change.
- Positive real Laravel HTTP tests: default 20, valid scalar location filters, page/per_page, no-filter 200, active Retail only; inactive Retail and Wholesale excluded.
- Negative AR/EN tests: nested/array country/city/area, overlong text, malformed/oversized/array page and per_page -> 422 `errors.<field>` with no store data or internal PHP warnings. Store, zone and order rows unchanged.
- OpenAPI `/stores` parameters + 422 validation response must be synchronized before merge (coordinate with #3908, which owns a **different operation** in the same shared OpenAPI file).
- Customer: existing guest discovery scalar queries/200 JSON unchanged; malformed query now explicit 422. No Customer Flutter source/DTO changes and no new mobile task for unchanged valid interface.
- Driver: not affected; no driver entry or consumption of this public guest Retail stores route; no Driver API/Flutter changes.
- Van: not affected; no Van entry or consumption of this public guest Retail stores route; B2B VAN ONLY and B2C DRIVER ONLY invariant untouched.
- AR/EN field errors are localized through paired `backend/lang/{ar,en}/guest_store_query.php` keys.
- No setup/update ZIP, release, production, `main`, branch protection, financial/tenant mutation.
- Run PHP syntax, Pint, PHPStan, Laravel focused/full and GitHub exact-head Required CI including policy/V2 isolation/AR-EN/OpenAPI/MySQL, and file-by-file COMMENTED engineering review prior to merge.

## Evidence status
Pending exact final head and terminal CI. Do not claim code-complete or merge until full acceptance.
