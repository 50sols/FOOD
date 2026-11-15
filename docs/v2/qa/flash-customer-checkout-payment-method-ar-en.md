# V2 #3768 — Customer Flash checkout unconfigured payment method AR/EN

**Source integration:** `Foodex_V2` only, parent #2733 and master #2503 remain open until their own full acceptance. No main, deployment, release, tag, ZIP/APK publication or production.

## Canonical source and contract

Authenticated `POST /api/v1/flash-reservations/{reservation}/confirm` dispatches a Customer channel confirmation through `FlashOfferController::confirm` to `FlashCheckoutService::confirmCustomer`. When `payment_method` is syntactically valid but **not in the configured `checkout.payment_methods` allowlist**, it still fails with Laravel HTTP 422, same `errors.payment_method.0` field and existing request schema, before customer/store resolution, reservation lock, order, invoice, stock movement, payment, audit or side effects.

This bounded change replaces only the human-readable English error with paired `flash_checkout.payment_method_not_configured` values in the existing AR/EN catalog; English content remains verbatim. An authenticated user's saved `ar` or `en` preference takes precedence over contradictory `Accept-Language`; supported negotiated fallback is used for other locales. The previously shipped `idempotency_header_invalid` key from #3761 is preserved unchanged. Do not substitute a different payment method silently or enable unsupported methods.

## Seeded HTTP verification

`FlashOfferBackendTest::test_customer_flash_checkout_unconfigured_payment_method_is_bilingual_and_write_free` uses the existing real RefreshDatabase/CoreReferenceSeeder B2C store and active offer, then creates an actual Flash reservation through `FlashOfferService`, authenticates via Sanctum, posts confirm with a valid Idempotency-Key but unsupported payment method under both persisted actor locales ar/en and contradictory Accept-Language headers. Exact localized HTTP422 validation errors must match. After each failed request, order/order-item/invoice/payment/audit/reservation/event counts, existing reservation status and inventory reserved quantity remain identical. Existing `test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation` covers valid configured method, 201 order/invoice/payment/inventory, and 200 idempotent replay.

## Impact, threat and failure classifications

- **Backend/API:** human-readable payment-method validation only; no HTTP status/field, configuration allowlist, identity, store, tenant, transaction, idempotency, inventory or finance behavior change
- **Customer:** existing retail Flash checkout may show the localized server error; no Customer Flutter/route/DTO/payload code change
- **Dashboard:** no authorization, form, navigation, API or rendered layout change
- **Driver:** no API/Flutter change; B2C remains DRIVER ONLY
- **Van:** no API/Flutter change; B2B remains VAN ONLY and Customer Flash remains Retail-only
- **Security:** REVIEW_RISK HIGH due payment, inventory and reservation adjacency; fail-closed allowlist preserved. Same-account file-by-file COMMENTED audit is an engineering review, NOT independent GitHub APPROVED

**Release block:** Before merging verify exact-final-head Repository Policy, V2 Isolation, AR/EN, PHP lint/Pint/PHPStan/security, full Laravel seeded HTTP, real MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate` SUCCESS. The atomic leaf's code completion is not final staging/mobile acceptance; retain #2503 as the final master gate.
