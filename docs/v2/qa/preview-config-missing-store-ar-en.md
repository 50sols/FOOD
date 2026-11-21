# V2 #3738 — Retail Guest Preview Configuration missing store (AR/EN)

Parent #2503. Related completed #3721/PR #3724 localized a **different** route (event stream /admin/app-preview/events), not this read-only guest configuration route. Contract: docs/quality/LOCALIZATION_CONTRACT.md. Integration: **Foodex_V2 only**. No main, production, tags, releases, APKs or cPanel.

## Reproduction and source correction

GET /admin/app-preview/storefront-configuration?channel=b2c&mode=draft is a canonical authenticated Dashboard App Preview Center read-only Guest configuration endpoint. When an otherwise authorized Retail actor omits store_id, the original controller fails closed with 422 before TenantContextResolver::retail or revision fetching, but exposed the English-only literal "Retail Guest preview configuration requires a store." under Arabic Dashboard locale.

This patch replaces exactly that HTTP 422 human message argument with paired server translation preview_configuration.retail_store_required in backend/lang/ar and backend/lang/en. Original Laravel middleware actor locale, auth, validated channel/mode, principal B2B branch, Retail tenant resolver and support_access, app_preview.view permission and guest revision DTO and 422 JSON shape remain unchanged. No new UI/route, no permission bypass, no revision writes, no event/SSE engine change.

## Automated acceptance, impact and review

New authentic RefreshDatabase/CoreReferenceSeeder test: authorized B2C store admin GET JSON with valid channel/mode but omitted store_id in both locales yields exact localized HTTP 422 message, without app_preview_sessions, invalidation_events or audit writes. A foreign-store request is forbidden 403; a roleless actor is forbidden 403; the valid Retail read-only Guest configuration path still returns 200. Existing #3721 SSE test and AppPreviewDashboardInvalidationTest positive B2B/Last-Event-ID and scope tests are untouched.

- Dashboard: error-copy only on existing Preview Center configuration request, no new layout/nav; AR/EN and RTL/LTR retained.
- Customer: NOT AFFECTED — no public mobile API, Flutter, shared preview revision DTO or Guest persona behavior change.
- Driver: NOT AFFECTED — no Driver API/Flutter or push behavior change.
- Van: NOT AFFECTED — no Van API/Flutter or B2B dispatch change.
- No schema, queue, storage, finance, business ownership or notifications modified.

REVIEW_RISK HIGH (authenticated tenant-scope preview). Same-account COMMENTED file-by-file technical audit must be recorded as NOT independent GitHub APPROVED. Final exact-head Repository Policy, V2 Isolation, Localization, PHP/Pint/PHPStan/security, Laravel full suite, real MySQL/Redis and terminal Required CI all must SUCCESS before SHA-guarded Foodex_V2-only merge. Code-complete leaf closure must cite SHA + CI; final manual staging/business UAT deferred to open master #2503.
