# V2 #3761 — Customer Flash checkout invalid Idempotency-Key AR/EN

**Base:** `Foodex_V2` only. Master #2503 remains open; no main, production, releases, tags or APKs.

## Canonical scope

Authenticated `POST /api/v1/flash-reservations/{reservation}/confirm`, `FlashOfferController::confirm` → `FlashCheckoutService::confirmCustomer`. A missing, under-16 or over-100-character `Idempotency-Key` is rejected **before** customer store resolution, reservation lock, inventory, payment, invoice, audit and order creation. The existing ValidationException HTTP422 `errors.Idempotency-Key.0` envelope and exact length bounds remain; only the human-facing message resolves the paired `flash_checkout.idempotency_header_invalid` locale key. Persisted actor `ar/en` locale takes precedence, with existing Accept-Language AR/EN fallback for unsupported locales. Original English text retained.

## Reproducible seeded HTTP negative evidence

`FlashOfferBackendTest::test_customer_flash_checkout_rejects_invalid_idempotency_header_in_both_locales_without_writes` creates a real B2C Flash offer and active reserved stock using the canonical `FlashOfferService`, authenticates the actor with Sanctum, then submits six real API requests (empty/short/101-character key for ar and en). Every response must be 422 with exact localized validation error. Captures and rechecks row counts (orders/order_items/invoices/payments/audit/flash reservations/events), inventory reserved quantity, and reservation status, before and after every invalid attempt. Existing `test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation` still verifies positive 201 checkout and successful 200 idempotent replay.

## Surface and invariant impact

- Customer: existing Customer Flash reservation-confirm error is localized to chosen account language, without DTO, request fields, status, Flutter, routing or payment behavior changes.
- Dashboard: no route/navigation/settings/layout or privilege change.
- Driver: no app/API change; B2C remains DRIVER ONLY.
- Van: no app/API change; B2B remains VAN ONLY; Customer Flash checkout remains Retail-only.
- Security: **REVIEW_RISK HIGH**. No guard, entitlement, store, reservation locking, transaction, credit, inventory, finance, retry or event mutation changes.
- Source review must be same-account COMMENTED engineering audit, not independent GitHub APPROVED. Release needs exact-final-head Repository Policy, V2 Isolation, AR/EN, PHP/Pint/static/security/OpenAPI, real seeded Laravel HTTP and MySQL/Redis install/upgrade/recovery with terminal Required CI **SUCCESS**. Final V2 manual/staging UAT remains master #2503.
