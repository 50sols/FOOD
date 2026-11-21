# V2 #3795 — Dashboard manual order unconfigured payment method AR/EN

Only integrate into isolated `Foodex_V2`; parent #2503 remains open for final staging/human UAT. No `main`, production, tags/releases, ZIP/APK publication or deployment.

## Existing authorized placement / visual and navigation contract

**Domain:** Retail and Wholesale Operations / Orders. **Entity:** manually created order and server-side quote. **Actor:** existing scoped Store Admin or authorized Operations user. **Goal:** identify unsupported configured payment method before quoting, confirming or persisting an order. **Workflow:** existing Orders management form/quote. **Placement:** **REUSE** existing `/admin/b2c/orders` and `/admin/b2c/orders/quote` request/form error surfaces (same B2B equivalent), no new route, Sidebar, Admin Hub destination, control, dropdown, form, page or UI markup. **Navigation:** existing authorized Orders entry point only; no change to reachability or permissions. **Desktop/tablet/mobile/RTL/LTR:** unchanged responsive layout and existing validation error placement, with the message now correctly localized by the authenticated admin's saved Arabic/English preference. **Failure state:** same existing field-scoped Laravel 422 JSON `errors.payment_method.0` for JSON and redirect/session `payment_method` error for web forms; no access or data-scope change.

## Source and tests

`AdminOrderManagementService::paymentMethod` is the authoritative allowlist check shared by `quote()` and `create()`. It obtains the configured `checkout.payment_methods`, permits `account_credit` only when the existing B2B active account has a positive credit limit, and rejects a syntactically valid unconfigured method **before quote, order and financial/inventory/audit writes**. The code changes only the one hard-coded human English message to the paired `admin_order_errors.payment_method_not_configured` AR/EN key, preserving the original English text verbatim. Saved authenticated User.locale ar/en wins over contradictory `Accept-Language`, with safe negotiation fallback for unsupported actor locales. No configuration, allowlist, account-credit calculation, tenant, RBAC, quote, transaction, financial or dispatch change.

`DashboardOrderManagementTest::test_b2c_dashboard_quote_and_order_localize_unconfigured_payment_method_without_writes` uses `RefreshDatabase + CoreReferenceSeeder`, real B2C store, inventory, sellable product, Store Admin role and scoped B2C customer. It sends actual authenticated JSON requests through both existing quote and manual order creation endpoints under saved ar/en actor locale with opposite `Accept-Language`. Each request must return the exact localized HTTP422 `errors.payment_method.0`. Compare counts of orders, items, invoices, payments, audit logs and stock movements plus reserved stock quantity after each failed request. Existing happy authorized B2C manual order create/update/cancel and cross-store denial tests remain unchanged; B2B account-credit allowlist and Van/Driver restrictions are unaffected.

## Cross-surface security and risk

- **Backend/Dashboard:** validation message only; original form/quote endpoints, role/store/tenant authorization and same business error remain
- **Customer:** no Customer Flutter/API contract or UI changes
- **Driver:** no Driver source/API/dispatch delta; B2C remains DRIVER ONLY
- **Van:** no Van source/API/dispatch delta; B2B remains VAN ONLY; existing B2B quote/manual payment authority remains
- **REVIEW_RISK:** HIGH because stock/finance/credit-adjacent; same-account file-by-file COMMENTED engineering audit is not independent GitHub APPROVED

Merge original issue PR only after all exact-final-head Repository Policy, V2 Isolation, AR/EN, PHP/Pint/PHPStan/security, full Laravel seeded functional/regression tests, real MySQL/Redis setup/upgrade/recovery and terminal Required CI SUCCESS. Close the coding leaf as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED after verifying integration SHA; master #2503 stays open for comprehensive human/staging acceptance.
