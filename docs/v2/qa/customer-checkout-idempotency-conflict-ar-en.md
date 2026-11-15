# V2 #3779 — Customer checkout conflicting Idempotency-Key 409 AR/EN

**Integration target:** isolated `Foodex_V2` only. Parent #2733 and V2 master #2503 remain open until final human/staging acceptance. No `main`, deployment, tags, releases, production, ZIP or APK promotion.

## Canonical contract: preserve business and security

An authenticated B2B or B2C Customer submits `POST /api/v1/checkout` with a supported `Idempotency-Key` and valid checkout request. Laravel resolves the user/store/channel and allowed payment methods before the database transaction. The canonical `CheckoutController` computes `checkout_request_hash` SHA-256 over store/address/payment/scheduled-delivery/note/coupon inputs. Inside the transaction, an existing order with the same customer/channel + `checkout_idempotency_key` returns HTTP200 and the same order on exactly equivalent payload. For changed payload, the existing constant-time `hash_equals` guard still aborts **HTTP409** and the same JSON `message` field, without processing any order/payment/stock/notification. This source change only localizes the 409 human sentence through new `checkout_errors.idempotency_reuse_conflict` AR/EN keys. The English message is original verbatim; saved User.locale ar/en controls the language and supported Accept-Language is fallback. Previously merged checkout locale keys (header invalid, missing quote cart, unsupported payment method) are untouched.

## Seeded production-graph HTTP evidence

The existing `CheckoutDomainTest::test_checkout_idempotency_replays_same_order_and_rejects_changed_payload` uses CoreReferenceSeeder, Retail B2C store/customer/cart/stock/address, Sanctum authenticated Customer and a scoped Dashboard Store Admin for order-created notification. First actual POST creates the order (HTTP201) with confirmed stock and payment/invoice/notification. Second with identical key and payload returns same order (HTTP200) with no duplicate notification. Then for persisted Customer actor locales ar/en and intentionally opposite `Accept-Language`, send same Idempotency-Key with changed order note; exact HTTP409 localized message, and no new orders/order items/invoices/payments/notifications/audit/cart items/stock movements/reserved stock; original order checkout_request_hash stays immutable. Existing B2B account credit, pricing, store authority, wrong-address and inventory race tests remain unchanged.

## Security + mobile/API impact

- **Backend/API:** fail-closed replay 409 human text only; HTTP status, JSON machine contract, request hash, tenant/store/role, address/payment/credit and transaction behavior unchanged
- **Customer:** existing Customer B2B/B2C checkout receives correct AR/EN error text; no Flutter/client DTO or request/response model code edit
- **Dashboard:** no UI/navigation/authorization/notification code change; original notification exactly once asserted
- **Driver:** no app/API/dispatch change; B2C remains DRIVER ONLY
- **Van:** no app/API/dispatch change; B2B remains VAN ONLY
- **Risk:** HIGH due confirmed order/stock, payment and idempotency; same-account COMMENTED engineering review is not independent GitHub APPROVED

**CI/release gate:** before merger, verify exact-final-head Repository Policy, V2 Isolation, Laravel full real seeded test, PHP lint/Pint/PHPStan/security, AR/EN, MySQL/Redis deployment install/upgrade/recovery and terminal Required CI SUCCESS. Merge original PR only to `Foodex_V2`, close coding leaf CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED, retain master #2503 for final UAT. Neither PR merge nor CI authorizes main or production.
