# V2 #3923 — Public Platform Wholesale Storefront Query Boundary

Parent #2733 (Customer storefront parity); master #2503; source branch `fix/3923-v2-platform-storefront-filter-validation`, integration `Foodex_V2` **only**.

## Actual route, user and trust boundary
- Public unauthenticated `GET /api/v1/platform/storefront`; canonical `PlatformMarketplaceController::home`. No new route, mode, privilege, module or navigation entry.
- Principal is the configured **active B2B** Wholesale store; never change to the first Retail store or expose private account prices. Retail banners/placements are only **active B2C** stores and existing service-zone decisions remain unchanged.
- Existing `q`, `category_id`, `country_code`, `city`, `area` optional filters previously cast direct untrusted query values, permitting nested/array coercion with PHP warnings or silent invalid category filtering.
- One boundary `Request::validate` now rejects array/nested and oversized scalar filters as JSON 422 with field-scoped `errors.<field>` (query clients already accept 200/validation failure). `category_id` only accepts positive integer syntax when supplied. Missing/empty optional filters retain previous default behavior. Search %/_ literal escaping and SQL bindings remain unchanged.

## Automated acceptance and negative matrix
`backend/tests/Feature/V2PlatformStorefrontMalformedQueryTest.php` uses `CoreReferenceSeeder` and an explicitly configured active B2B Wholesale principal, active B2C store and inactive B2C store.
- Positive AR/EN requests preserve 200, principal B2B identity, unchanged response keys and legitimate search/category/location filters; inactive and Wholesale stores never appear in Retail placements.
- Negative AR/EN nested/array `q`, `country_code`, `city`, `area`, `category_id`, invalid/zero/negative category and each overlength text field yield 422 and field-specific errors.
- No store, order or audit-log counts change on read-only request paths. Tests assert the three fixture store activation states are preserved.
- Existing role, Storefront, Catalog, SQL-like escaping, and full Laravel suites remain required regression gates; no independent local/CI pass may be inferred until actually run.

## Customer / Driver / Van impact
| Surface | Change | Contract and verification |
| --- | --- | --- |
| Backend/API | **Affected** | Public Platform Storefront GET input shape; malformed inputs now 422; valid requests maintain same 200 schema and SQL scope |
| Dashboard | **Not affected** | No admin Sidebar, route, RBAC, UI, financial or entitlement change |
| Customer | **Affected only for malformed public queries** | Existing Platform Wholesale home can continue to send valid optional scalar query parameters. No response shape or legitimate shopper journey change; direct HTTP AR/EN tests cover guest path. No Flutter source change required for valid traffic |
| Driver | **Not affected** | Driver app is B2C delivery-only; public Platform Wholesale homepage query contract is not a Driver API |
| Van | **Not affected** | Van app is B2B route/visit/fulfillment; no Van endpoint or dispatch/stock contract touched |

No migration, credential, installation, upgrade, checkout, write transaction, or physical mobile build change. Responsive/RTL/LTR UI is unchanged because only a server-side request-validation boundary changed.

## Threats, rollback and review
- Abuse: nested query values must not raise PHP `Array to string conversion` warnings or bypass location filtering; unbounded strings must not create expensive wildcard queries.
- Scope: validation cannot authorize Retail or foreign Wholesale products; service-zone `whereExists` still uses SQL parameters.
- Failure: invalid inputs return standard Laravel JSON 422 without touching storage. Error keys are stable in Arabic and English; existing valid API consumers remain compatible.
- Rollback: revert the single controller validation commit while preserving test/evidence and API documentation; no schema/data rollback required.
- **REVIEW_RISK: HIGH** due externally exposed input contract. Requires separate final exact-head file-by-file engineering review and successful Repository Policy, V2 Isolation, Required CI including Laravel, security, localization, MySQL/Redis acceptance before merge. Same-account source review is not independent approval.
- **FINAL_ACCEPTANCE: DEFERRED_TO_V2** applies only to real-device/staging checks in #2733/#2503; missing code, tests, contracts or red CI are not deferred.
- No merge into `main`, release, tag, APK signing, cPanel or production operation is authorized.
