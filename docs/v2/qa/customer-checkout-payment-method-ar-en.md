# V2 #3771 — Customer checkout unsupported payment-method validation AR/EN

**Source integration:** `Foodex_V2` only; parents #2733 and master #2503 stay OPEN until their own final acceptance. No `main`, production, deployment, tag, release, ZIP/APK publication.

## Canonical source and contract

The authenticated `POST /api/v1/checkout` route invokes `CheckoutController::__invoke` and validates the requested `store_id`, `address_id`, and optional `payment_method`, resolves the signed-in customer and authoritative B2B/B2C store/channel, then constructs the payment-method allowlist from `checkout.payment_methods`. The existing B2B account-credit eligibility exception still computes purchasing power from the account ledger. If the submitted method is not allowed, Laravel returns **HTTP 422** with the same `errors.payment_method.0` validation field, before address mutation, quote, order, inventory, payment, invoice, audit and cart operations.

The source changes exactly the fixed English error string to the paired `checkout_errors.payment_method_not_configured` locale key in existing AR and EN catalogs. English copy is verbatim; saved actor locale ar/en takes precedence, with Accept-Language fallback for unsupported actor locales, mirroring the adjacent existing CheckoutController idempotency-header locale resolution. Previous `checkout_errors.idempotency_header_invalid` and `checkout_errors.quote_cart_missing` keys from other merged issues are retained.

## Reproducible real HTTP test

`CheckoutDomainTest::test_unconfigured_checkout_payment_method_is_localized_and_does_not_mutate_checkout` uses the existing `RefreshDatabase + CoreReferenceSeeder` retail fixture with a real store, catalog, stock, B2C platform/domain customer, authorized saved address, Sanctum actor and real one-item authenticated cart. It posts valid-length `Idempotency-Key`, unconfigured `payment_method`, both persisted actor locales `ar` and `en`, and deliberately contradictory Accept-Language. Assert exact localized HTTP422 `errors.payment_method.0` and unchanged orders, order items, invoices, payments, audit logs, notifications, carts, cart items and inventory reserved stock after each failed attempt. Existing `CheckoutDomainTest` remains authoritative for happy checkout 201, duplicate identical replay 200, credit/stock and wrong-address scope regressions.

## Security/role/client impact

- **Backend/API:** message-only localization at a fail-closed configured payment allowlist; no authentication, store/channel, tenant, credit, credit eligibility, finance, stock, idempotency, retry, lock, database, route, DTO or response schema change.
- **Customer:** existing Customer API checkout receives translated validation text in the standard `errors.payment_method.0`; no Customer Flutter code or request/response schema update.
- **Dashboard:** no UI/navigation/admin permission or data changes.
- **Driver:** unaffected, no Driver API/Flutter change; B2C remains DRIVER ONLY.
- **Van:** unaffected, no Van API/Flutter change; B2B remains VAN ONLY.
- **Review:** HIGH because checkout/payment/credit boundaries. Same-account file-by-file COMMENTED audit is an engineering record, not an independent GitHub `APPROVED`.

**Merge gate:** the issue-scoped original PR must have all exact-final-HEAD Repository Policy, V2 Isolation, PHP/Pint/PHPStan/security, AR/EN, seeded Laravel full tests, real MySQL/Redis deployment/upgrade/recovery and terminal Required CI SUCCESS before SHA-guarded squash merge **only** to `Foodex_V2`. Manual/staging/mobile comprehensive acceptance remains parent #2503.
