# V2 #3743 — Checkout invalid Idempotency-Key 422 AR/EN

**Parents:** #2733 Customer checkout and #2503 overall V2 completion. **Integration:** Foodex_V2 only, no main/production/deployment.

## Source and contract

The existing authenticated Customer `POST /api/v1/checkout` requires `Idempotency-Key` with length 16–100 after validating `store_id` and `address_id`, before the Customer store/identity resolver, idempotency hash, B2B account, cart locks and DB transaction. Existing 422 validation envelope is `errors.Idempotency-Key.0`; English copy was fixed regardless of authenticated Customer locale.

Use paired server-owned `checkout_errors.idempotency_header_invalid` AR/EN messages. Resolve the *authenticated customer* locale if ar/en; otherwise follow the standard request `Accept-Language` convention. No route, request field, header length, status, error-key, payment/credit, inventory, stock, order/invoice or audit changes. Preserve the original English copy exactly.

## Regression evidence

New `V2CheckoutIdempotencyHeaderLocaleTest` uses real Sanctum-backed HTTP POST for both Arabic and English Customer actors; each tests absent, too-short and too-long headers and exact localized `errors.Idempotency-Key.0`. The test asserts no orders, carts, payments, audit or notifications created. A Customer with unsupported saved locale follows Arabic `Accept-Language`; unauthenticated request with a syntactically valid header remains denied HTTP 401. Existing full-suite positive checkout and retry regressions must remain green. No fake client-driven amount, store or idempotency override is introduced.

## UI and cross-app impact

- Customer: human-readable checkout error changes, still HTTP 422 and exact error JSON key; no Flutter/DTO source change, AR/EN and layout parity unchanged
- Dashboard: no admin page or permission change
- Driver: no API/Flutter impact; B2C DRIVER ONLY is preserved
- Van: no API/Flutter impact; B2B VAN ONLY is preserved

## Security review and acceptance

REVIEW_RISK: HIGH, protected idempotent checkout and finance/tenant boundary. Same-account COMMENTED file-by-file technical audit is not independent APPROVED. Required exact-final-head Repository Policy, V2 Isolation, AR/EN, PHP/Pint/PHPStan/security, full Laravel/MySQL/Redis and terminal Required CI all SUCCESS before `Foodex_V2` merge. Parent #2503 retains full staging/device/final V2 acceptance; no production release claim.
