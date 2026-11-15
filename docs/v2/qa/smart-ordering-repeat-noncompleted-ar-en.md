# V2 #3822 — Smart Ordering repeat: incomplete-order HTTP409 AR/EN

**Integration:** `Foodex_V2` ONLY; master #2503 remains OPEN. No main, production, tags, release or APK/cPanel deployment.

## Source-to-runtime trace and security review

| File | Bounded change / negative scope |
| --- | --- |
| `backend/app/Domain/SmartOrdering/RepeatOrderService.php` | Existing order-not-completed check still rejects with HTTP 409. Order ownership, B2C channel, store filtering and completed/delivered status are unchanged. Only existing English-only human error is localized. Authenticated actor saved `ar/en` has priority; unsupported locale falls back to supported `Accept-Language`. |
| `backend/lang/en/smart_ordering_repeat_errors.php` | Original English message preserved verbatim. |
| `backend/lang/ar/smart_ordering_repeat_errors.php` | Arabic translation of the identical rejection; no client authority or business-state changes. |
| `backend/tests/Feature/SmartOrderingCustomerGrowthTest.php` | Seeded Sanctum actor + B2C restaurant + published runtime/entitlements + historical pending order, **actual HTTP POST** under saved ar/en with contrary header and unknown saved locale with supported AR/EN header fallback. Exact 409 message, no drafts/orders/order-items/payment/audit/stock write, historical order remains PENDING. Existing positive completed/live-price and cross-tenant tests retained. |
| `docs/v2/qa/smart-ordering-repeat-noncompleted-ar-en.md` | This impact and threat matrix; no separate navigation/API/role changes. |

### Scope & surface impact

- **Backend API:** existing authenticated public restaurant repeat endpoint emits localized 409 text only; error shape/status, site entitlement, actor/store/customer/channel/status guards and quote/draft/analytics semantics unchanged.
- **Dashboard:** not affected; no route, UI, SideBar or permission change.
- **Customer Flutter:** customer-facing API error message changes to correct AR/EN, but no endpoint, HTTP status, response JSON schema, DTO, authentication or app source change. No mobile code needed; positive repeat response unchanged.
- **Driver Flutter:** not affected. B2C fulfillment remains DRIVER ONLY.
- **Van Flutter:** not affected. B2B fulfillment remains VAN ONLY.

### Risk / acceptance

**REVIEW_RISK: HIGH** (authenticated customer commerce and tenant boundary). Defect is message-only, but denial before draft/quote/stock/payment must remain authoritative. No request-controlled translation keys, tenant or channel bypass, SQL widening, unknown locale leak or idempotency changes. CI must show exact-final PR HEAD successful Repository Policy, V2 Isolation, AR/EN, Pint/PHPStan/security, full Laravel, MySQL/Redis fresh/upgrade/recovery and terminal Required CI. Same-account file-by-file technical audit is recorded separately and is **not** independent GitHub approval. Final staging/UAT belongs to #2503.
