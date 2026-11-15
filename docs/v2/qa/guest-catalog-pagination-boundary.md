# V2 #3920 — Retail guest catalog pagination query boundary

- Source: V2 Customer storefront #2733; master #2503; GitHub issue #3920.
- Integration: `Foodex_V2` only; no main, tag, APK, release, production or deployment.
- Reuse: existing `GuestCatalogController`, existing four public B2C GET routes and canonical Laravel query validation. No Dashboard navigation or UI/DB migration.
- Root cause: `Request::integer('per_page')` coerced untrusted nested/array/invalid values; `page` was passed to the paginator without an explicit shape check.
- Fix: both supplied pagination fields must be positive integers; per-page upper bounds are 100 for products/categories/offers and 50 for banners; absent defaults remain 20/10. Each validation yields field-scoped HTTP 422; excluded B2B/inactive stores are checked first and still yield HTTP 404.
- Positive/negative evidence: `php artisan test --filter=V2GuestCatalogMalformedPaginationTest`, `php artisan test --filter=GuestBrowsingTest`, Pint, PHPStan, OpenAPI and full exact-head Actions checks. Verify results from linked PR rather than assuming tests are green.
- Security: read-only endpoints, no new permission or grant, no visitor-to-admin pathway, no mutation, no expanded store scope. B2C=DRIVER fulfillment unchanged and B2B=VAN fulfillment unchanged. Rollback by reverting the new validator/helper and OpenAPI operation changes.
- Customer: existing valid integer page/per_page callers remain compatible; malformed custom requests now receive HTTP 422. Driver: does not consume these public guest B2C catalog reads. Van: does not consume these public guest B2C catalog reads. No Flutter model or authenticated API change; broader Customer device UAT remains under #2733.
- AR/EN: validation uses canonical Laravel localized field errors, no new static UI text; both locales and nested arrays covered by seeded HTTP regression. RTL/LTR, desktop, tablet and mobile web response models unchanged. Manual browser/device accessibility is deferred to parent #2733/#2503.
- Risk: HIGH (public query input boundary, though no authorization mutation). Review each modified path and edge cases before merge.
