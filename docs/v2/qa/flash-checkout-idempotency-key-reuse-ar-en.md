# V2 #3793 — Flash checkout reused Idempotency-Key for a different reservation AR/EN

**Isolated integration:** `Foodex_V2` only. Parents #2733/#2503 retain final staging/human acceptance. Never merge to `main`, tag, release, deploy, publish APK/ZIP or touch production.

## Existing verified contract and bounded change

`POST /api/v1/flash-reservations/{reservation}/confirm` resolves the authenticated Retail Customer and store, validates the configured payment method and user-owned delivery address, and computes a SHA-256 canonical request hash containing `reservation_id`, store, address and method. It transactionally locks the Flash reservation. An already-confirmed **same** reservation returns its original order HTTP200 for matching store/address/payment, or existing HTTP409 confirmed-request-mismatch (localized separately by #3777). An **active second reservation** for that same B2C actor, confirmed with an `Idempotency-Key` already attached to a prior order, enters the separate `Order::query()->where('b2c_customer_id')->where('checkout_idempotency_key')->lockForUpdate()` path, verifies hash in constant time and fails HTTP409 when the new reservation ID differs. No second order or financial mutation is permitted.

This atomic patch **only** replaces the existing English-only human 409 message in that separate guard with `flash_checkout.idempotency_reuse_conflict` paired AR/EN catalogs. The original English sentence remains unchanged. Saved User.locale `ar/en` takes precedence over supported negotiated `Accept-Language`. The transport remains HTTP409 `message`. Previously merged Flash language keys are unchanged; no changes to allowlist, auth, tenant scope, locks, idempotency/replay decision, reservation status, quote, order/invoice/stock, financial ledger, notifications, routes, DTO, app code or schema.

## Reproducible seeded HTTP acceptance

`FlashOfferBackendTest::test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation` already provisions a genuine seeded Retail store/product/Flash offer, linked B2C customer and owned address, then exercises Sanctum HTTP reservation and confirmation HTTP201 (order+invoice+payment+quota+stock), exact retry HTTP200 and mismatched confirmed reservation address HTTP409 AR/EN with no writes. The fixture permits two 10-base-unit Flash reservations per user.

After that positive/negative sequence, create a **new active** reservation under a fresh reserve key but the same customer/offer (second 10-base-unit reservation within 20-base-unit per-customer quota). On that new reservation send POST confirmation with the **first order's already-consumed valid checkout Idempotency-Key**, same owned address and method; the new reservation ID changes the canonical request hash. Repeat under saved user `ar` and `en` with opposite Accept-Language. Assert exactly localized HTTP409 JSON `message`. After each refusal, compare orders, items, invoices, payments, audit, Flash reservations/events, stock movements, quota reservations and reserved inventory quantity to the baseline captured **after** creating that second reservation. The second reservation must stay active with no order; original reservation stays confirmed and prior order ID, address and request hash remain immutable. No request secrets or internal server paths appear.

## Customer / Dashboard / Driver / Van impact and risk

- **Customer:** existing B2C Flash checkout receives a translated 409 human message only, no Flutter/DTO/route/contract change
- **Dashboard:** no changes to order management, inventory, navigation, permissions or billing
- **Driver:** no app/API change; B2C order fulfillment remains DRIVER ONLY
- **Van:** no app/API change; B2B fulfillment remains VAN ONLY, Flash Customer B2C cannot route to Van
- **Security:** REVIEW_RISK HIGH, as payment/idempotency/stock adjacency. Same-account file-by-file COMMENTED engineering audit is not an independent GitHub APPROVED review.

**Gate:** complete applicable exact-final-HEAD Repository Policy, V2 Isolation, PHP syntax/Pint/PHPStan/security, localization AR/EN, full Laravel seeded HTTP regression, real MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate` SUCCESS before SHA-guarded squash merge into Foodex_V2. Close coding leaf as CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED only after verified integration. Master #2503 remains OPEN for consolidated human/staging/device acceptance.
