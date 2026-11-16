# V2 #3874 — Customer checkout missing / empty cart HTTP409 AR/EN

## Reuse / authority and route
- Parent #2503. Existing authenticated `POST /api/v1/checkout` route / `CheckoutController::__invoke`, no route, schema, permission, role, payment, quote, idempotency or response-key change.
- Both guards are **after** validated request, Idempotency-Key range, authenticated User, authoritative CustomerDomainResolver store/channel scope and configured payment checks. Within existing checkout DB transaction, the first abort is the **missing scoped Cart**, the second is the **existing scoped Cart with no CartItems**. Existing status HTTP409 and response `message` remain stable.
- The equivalent read-only QuoteController errors were already bilingual via #3765 / #3801; this issue fixes the independently remaining **checkout** copies. New `checkout_errors.checkout_cart_missing` / `checkout_errors.checkout_cart_empty` keyed paired AR and EN. Exactly original English error bodies retained.
- User's supported persisted `ar/en` takes precedence over contrary Accept-Language; unsupported persisted locale negotiates supported regional `ar-KW` / `en-US` with safe Arabic fallback.

## Regression and risk
- Existing real seeded `CheckoutDomainTest` has a B2C store, inventory, catalog, customer domain, valid address and authenticated Sanctum user. No new fixture or hard-coded fake responses.
- For missing owned cart and existing cart with its items removed, send four locale combinations (8 total HTTP POSTs) with valid Idempotency-Key and original store, address and payment data. Assert exact localized HTTP409 `message` (same status/body shape), cart is not created or changed, cart items stay unchanged, orders/invoices/payments/audits/notifications/stock movements counts unchanged, inventory quantity and reservation unchanged after each rejection.
- Original successful real checkout tests, customer invoice and payment creation, stock rechecks and idempotency replay remain unchanged and run in full Laravel CI. Invalid/foreign store and role/ownership checks stay untouched and are covered by existing suite.
- The checkout path is in transaction; both existing aborts are exception-based and roll back state. The error string replacement adds no write operation and does not bypass authoritative scope.
- **REVIEW_RISK HIGH**: authenticated commerce, money, stock and idempotency adjacency; negative tests are mandatory before declaring success.

## Mobile and navigation impacts
| Surface | Effect | Justification |
| --- | --- | --- |
| Backend / API | Existing human HTTP409 message localized | Exact route, status, response key, authorization, DTO and transaction unchanged |
| Dashboard | Not affected | No admin controllers, views, permission, navigation or API contract changed |
| Customer | Text only | Existing B2B/B2C checkout can surface AR/EN correct human message; no Flutter/DTO/route change |
| Driver | Not affected | B2C DRIVER ONLY dispatch, Driver API and code unchanged |
| Van | Not affected | B2B VAN ONLY dispatch, Van API and code unchanged |

## Five-file review/rollback and acceptance
1. `backend/app/Http/Controllers/Api/V1/CheckoutController.php` — replace two original HTTP409 messages + add private actor-locale helper; no business/mutation/scope edits.
2. `backend/lang/ar/checkout_errors.php` — two new Arabic keys.
3. `backend/lang/en/checkout_errors.php` — two matching keys with original English.
4. `backend/tests/Feature/CheckoutDomainTest.php` — real seeded AR/EN missing/empty negative HTTP test with unchanged checkout read/write state.
5. This QA doc — trace, threat, impact, failure/rollback, human acceptance.

Rollback these text/catalog/test/documentation changes only; no schema, migration or production data repair necessary. Same-account COMMENTED file-by-file technical audit is **not** independent GitHub APPROVED. Before merging require exact-final-head Repository Policy, V2 Isolation, full Laravel tests, PHP/Pint/PHPStan/security/OpenAPI, AR/EN parity, real MySQL/Redis install/upgrade/recovery and terminal FOODEX Required CI all SUCCESS. SHA-guard merge ONLY into `Foodex_V2`. Close implementation leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only after verified merge and evidence; parent #2503 must remain OPEN for consolidated staging/device/manual UAT. NO main, tag, release, APK/ZIP promotion, cPanel or production.
