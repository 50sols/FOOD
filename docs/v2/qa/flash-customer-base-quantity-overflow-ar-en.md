# V2 #3884 — Customer Flash base-quantity overflow HTTP422 AR/EN

Parent #2503. This localization-only follow-up **reuses the authoritative arithmetic safety fix in closed #3809 / merged #3810**. Do not change `FlashOfferService::reserve` safety/stock logic.

## Source and request contract

- Existing authenticated Customer route `POST /api/v1/flash-offers/products/{offerProduct}/reserve`; `FlashOfferController::reserve` calls `CustomerDomainResolver::forStore` before checking `round(quantity * offerProduct.conversion_factor, 3)`.
- Finite `PHP_FLOAT_MAX` quantity multiplied by the real seeded 10× conversion overflows to INF. The existing `! is_finite($baseQuantity) || $baseQuantity <= 0` guard must reject before commercial policy, reservation and inventory writes.
- Only the guard's original fixed English **human** HTTP422 `message` changed to `flash_checkout.invalid_base_quantity`. The original English is verbatim. Persisted actor locale `ar` or `en` takes precedence over contrary `Accept-Language`; otherwise use supported `ar-KW`/`en-US` regional header with safe Arabic default.
- HTTP status 422, `message` response key, route, payload, store/channel/identity enforcement, pricing, stock, service error and idempotency contract are unchanged. No new payment, address, user or SKU permissions.

## Seeded real-HTTP acceptance

- Extend existing `FlashOfferBackendTest::test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation`. Real Sanctum-authenticated Retail B2C Customer, catalog product, active Flash offer with 10× conversion, real inventory and Customer domain store scope.
- Four rejected `POST` requests, each with a distinct idempotency key, `quantity=PHP_FLOAT_MAX`, contradictory saved `ar/en` vs regional header and unsupported saved `fr` with regional `ar-KW/en-US`. All must return HTTP422 and exact localized `message`; no exception stack, internal customer details or non-finite price data is returned.
- After **each** rejection, assert no changes to reservation count, Flash events, stock movements, orders, payments, invoices, audit logs, notifications or reserved inventory. Actor original persisted non-null locale is restored.
- Existing positive HTTP201 finite reservation, active reservation lookup, checkout confirmation/replay and invoice/stock assertions remain part of the same real seeded test; separate #3809 direct service overflow/positive tests remain intact.
- Full Laravel suite, PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN pair parity and MySQL/Redis fresh-install, upgrade and recovery must pass on the exact final PR HEAD, alongside Repository Policy, V2 Isolation and terminal FOODEX Required CI.

## Surface impact / security

| Surface | Impact |
| --- | --- |
| Backend / API | Existing authenticated Customer overflow HTTP422 **message text only**. No route, permission, status/DTO, business math or state changes |
| Customer app | Existing Flash buy-now validation can display the correct Arabic or English error. No Flutter source, navigation or API model changes |
| Dashboard | Not affected — no admin routing or views touched |
| Driver app | Not affected — B2C DRIVER ONLY routing and contracts unchanged |
| Van app | Not affected — B2B VAN ONLY fulfillment / service-level error and contracts unchanged |

**REVIEW_RISK HIGH** because Customer account/store scope, commercial rules, finances and reserved inventory are adjacent. Authorization and tenant isolation remain before the error branch; no alternate consumer may use the error string to bypass the arithmetic guard.

## Five-file audit / rollback

1. `backend/app/Http/Controllers/Api/V1/FlashOfferController.php` — only human 422 error translation in existing overflow guard, no numerical or mutation changes.
2. `backend/lang/ar/flash_checkout.php` — one Arabic key.
3. `backend/lang/en/flash_checkout.php` — matching key, original English verbatim.
4. `backend/tests/Feature/FlashOfferBackendTest.php` — four locale negative HTTP cases with no-write snapshots and existing positive flow.
5. This QA source/negative/security/impact/rollback document.

Rollback only localized text, paired keys, tests and QA; no migrations/DB state changes. Same-account GitHub `COMMENTED` HIGH source audit is an engineering review, **not independent APPROVED**. SHA-guarded squash merge only to `Foodex_V2` after every applicable exact-final-HEAD CI success, then close leaf `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with PR, tested and merge SHAs and CI links. Parent #2503 stays OPEN for final staging/device/manual UAT. NO main/release/tag/APK/ZIP promotion/cPanel/production.
