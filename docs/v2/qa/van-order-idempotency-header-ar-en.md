# V2 #3811 — Van order invalid Idempotency-Key header AR/EN

**Integration boundary:** `Foodex_V2` only. Parents #2739 (Van complete journey) and #2503 (master) stay OPEN for remaining stock, settlement, offline and human/staging/device acceptance. No `main`, production, signed release, tags, ZIP, APK or cPanel deployment.

## Existing authorized endpoint and message-only fix

`POST /api/v1/van/customers/b2b/{customer}/orders` authenticates the Van agent via Sanctum `app:van`, requires an authorized active Van assignment and B2B-only customer, valid visit and store scope. Before any quote, inventory, order, payment or audit writes, `VanOrderController::store` trims the `Idempotency-Key` request header and rejects blank, shorter than 16 characters and longer than 100 characters with Laravel HTTP422 and the existing `errors.Idempotency-Key.0` field. Original `A valid Idempotency-Key header is required.` is English-only.

Only the existing human error is translated using paired `van_order_errors.idempotency_header_invalid` AR/EN keys. Saved authenticated actor User.locale ar/en takes precedence over supported Accept-Language fallback (no trust in client-provided identity/role). English text is verbatim. No changes to header/length checks, route, response field, HTTP422, Van actor/visit/tenant authority, idempotent order/credit/stock creation, finance maker-checker, or Van client request/DTO model.

## Seeded real HTTP acceptance

`VanOrderControllerTest::test_van_order_creation_requires_idempotency_key_before_checkout` uses `RefreshDatabase`, real `CoreReferenceSeeder`, assigned authenticated VAN_OPERATOR and B2B planned visit. For persisted ar/en Van actor locales under the opposite Accept-Language, send actual POST `/api/v1/van/customers/b2b/999/orders` with explicitly empty, short and 101-byte Idempotency-Key headers. All six requests must return HTTP422 and exact localized `errors.Idempotency-Key.0`. Orders, order items, invoices, payments, audit, stock movements and reserved inventory must remain unchanged after every rejection. Existing successful Van B2B quote/create/retry and explicit B2C/outside-visit 404 regressions stay unchanged.

## App impact and security

- **Van:** AFFECTED, server human message of existing form error translated AR/EN; existing Van app error rendering/HTTP422 key, client DTO, runtime and code unchanged. Remaining Van journey #2739 stays OPEN
- **Customer:** NOT AFFECTED, no Customer endpoints, storefront, contracts, payment or Flutter code changes
- **Driver:** NOT AFFECTED, no Driver API or Flutter code changes; B2C order routing remains DRIVER ONLY
- **Dashboard:** NOT AFFECTED, no route, sidebar, screen, permission or form/RTL/mobile web changes
- **Backend:** risk HIGH due Van order finance/stock and authorization adjacency. Same-account file-by-file COMMENTED audit is engineering evidence, not independent GitHub APPROVED peer review. B2B order fulfillment remains VAN ONLY

**Merge gates:** exact-final-head Repository Policy, FOODEX V2 Isolation, AR/EN, PHP/Pint/PHPStan/security, full Laravel seeded tests, real MySQL/Redis install/upgrade/recovery and terminal required-ci-gate all SUCCESS. Merge original one PR to Foodex_V2 with expected SHA and verify integration before closing this atomic coding leaf CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED; master #2503 and Van parent #2739 retain comprehensive source/human acceptance.
