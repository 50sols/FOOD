# V2 #3777 — confirmed Flash reservation checkout replay mismatch AR/EN

This issue is scoped to the isolated `Foodex_V2` branch only. Master #2503 and Customer parent #2733 retain final staging/human UAT; no `main`, production, tags/releases, APK or Setup ZIP publication.

## Existing contract preserved

`POST /api/v1/flash-reservations/{reservation}/confirm` authenticates the requesting B2C Customer, resolves the Retail store and the user-owned delivery address, validates configured payment method and idempotency key, and locks the Flash reservation transactionally. Once a reservation is `confirmed` and references an order, exact replays with the same canonical `store_id`, `address_id` and `payment_method` return the **same order** HTTP200 without a new mutation. A replay with another user-owned delivery address, store, or payment method remains **HTTP409** with the same `message` JSON field, still fail-closed, without changing the previously confirmed order.

The only source change is translating the **human** 409 mismatch message by existing saved `User.locale` ar/en preference, or supported Accept-Language negotiation when user locale unsupported, using a new paired `flash_checkout.confirmed_request_mismatch` catalog key. The English value is unchanged verbatim. Other Flash checkout errors and existing `idempotency_header_invalid` and `payment_method_not_configured` keys remain unchanged. No locks, idempotency keys, request hashing, token guards, reservation status transitions, financial writes, stock reservation, delivery dispatch, API DTO or transport changes.

## Seeded actual HTTP proof

The existing `FlashOfferBackendTest::test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation` provisions a real Retail store, offer, user, linked Retail customer, address, reserved Flash stock, and actual authenticated Sanctum HTTP reservation + confirmation. It first verifies expected order/invoice/payment/quota/stock creation HTTP201 and exact-request replay HTTP200 with the same order ID. Then it creates **another authorized customer-owned address** and attempts a changed-address confirmation with the same valid Idempotency-Key and already confirmed reservation under both persisted Arabic and English actor locales with intentionally opposite Accept-Language values. Each must return HTTP409 and the exact translated `message`, without a new order/item/invoice/payment/audit/flash event/reservation/quota/stock movement or extra reserved inventory. Confirmed reservation and original order address must remain unchanged. Existing successful HTTP and no-write negative regressions remain.

## App and security impact

- **Backend/API:** user-facing replay conflict text only; HTTP409 `message` envelope and retail authorization/store/credit/stock/payment invariants unchanged
- **Customer:** existing Retail Flash checkout client can display localized server error, no Customer Flutter or DTO change
- **Dashboard:** no UI/navigation/role/permission, contract or financial edit
- **Driver:** unchanged; B2C fulfillment remains DRIVER ONLY
- **Van:** unchanged; B2B fulfillment remains VAN ONLY; Customer Flash booking never routed to Van
- **Security:** REVIEW_RISK HIGH as confirmed order/payment/stock and retry boundary. Same GitHub account may record detailed COMMENTED file-by-file engineering audit but must never claim an independent APPROVED review

**Required before integration:** exact-final-head Repository Policy, V2 Isolation, AR/EN, PHP lint/Pint/PHPStan/security, full Laravel real seeded HTTP tests, MySQL/Redis install/upgrade/recovery acceptance and terminal `required-ci-gate` SUCCESS, plus PR changed-file lease/concurrency and base recheck. Merge only `Foodex_V2`, close leaf as CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED, parent #2503 remains open.
