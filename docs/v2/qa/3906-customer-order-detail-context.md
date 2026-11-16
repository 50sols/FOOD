# V2 #3906 — explicit Customer order detail context fail-closed

Issue #3906; parent Customer order parity #2733; Customer identity #2731; master #2503. **Target Foodex_V2 only**, never main/production/release.

## Source and independently bounded defect

The existing authenticated `OrderController::show` checks order ownership first and then `assertRequestedOrderContext`. The latter ignored an explicitly supplied malformed `store_id` (because it tested `is_numeric` only for positive values) and treated invalid empty input as absent so that a caller's valid `X-FOODEX-Store-ID` header could mask a malformed query. Array-shaped `channel` would undergo PHP string conversion rather than fail cleanly. Thus an otherwise authorized order detail could return 200 in a *requested context that did not actually match*, unlike the already-strict Customer invoice `show/download` context checks (#3504).

This is request-context integrity and defensive anti-confusion, **not a cross-tenant ownership bypass**. The original platform/split customer resolver, order ownership query, user permissions, B2B/B2C separation and successful response JSON remain authoritative. `OrderController::index`'s recently merged #3902 channel guard stays untouched.

## Implementation

Reuse existing `OrderController::show` and replace only its private helper `assertRequestedOrderContext`:

- Track explicit `store_id` or `channel` presence so empty query values cannot fall back to a valid-looking header. Explicit query has precedence; absent query may use the relevant header.
- Strict scalar store ID: string/int only; trimmed nonempty unsigned decimal digits; `DisplayNumber::referenceId` to handle huge values without overflow or coercion. A supplied invalid, zero, negative, array, overlong or wrong-store value returns 404.
- Strict scalar channel string only; trimmed lowercase exactly B2B/B2C; empty, array, unknown or wrong channel returns 404. Avoid array-to-string PHP warnings and text/error disclosures.
- Valid matching query and matching headers or no context maintain 200; no new route, exception payload, entitlement, state change, migration, payment, notification or user-facing text.

## Authenticated negative HTTP regression

Extended the original `PlatformCustomerUnifiedOrdersTest::test_platform_customer_order_detail_rejects_foreign_customer_and_store_channel_tampering`, using actual platform/retail fixture and owned order:

- Same-store B2C `store_id/channel` → 200 with stable stored store_id/channel; wrong Retail store and wrong channel → 404; unrelated customer ID remains 404.
- Explicit `store_id=invalid|0|-1|oversized-decimal|empty` → 404 even though the actor owns the order.
- Array `store_id[0]`, array `channel[0]`, empty and invalid `channel` → 404 (no 500/warning).
- Matching `X-FOODEX-Store-ID` and `X-FOODEX-Customer-Domain` without explicit query → 200; explicit empty or invalid query takes precedence and returns 404.
- Verify unchanged number of order records; do not mutate user data. No real production credentials or database.

## App and release impact

| Surface | Assessment |
|---|---|
| Backend/API | Existing authenticated Customer `GET /api/v1/orders/{id}` negative query/header behavior only; owner checks and authorized positive response unchanged |
| Customer | Existing `HttpCustomerOrdersApi.order()` sends valid positive `store_id` and normalized B2B/B2C `channel` with matching header; no Flutter app/UI/API response schema change, valid paths compatible |
| Driver | No Driver endpoint, status, assignment, notification, Flutter or permission change; B2C DRIVER ONLY preserved |
| Van | No Van endpoint, orders/visits/stock/route Flutter change; B2B VAN ONLY preserved |
| Dashboard/ERP/Finance | No Dashboard route, finance write, data migration, inventory or settlement change |

**REVIEW_RISK: HIGH** because the Customer API uses scoped identity/data. Require file-by-file review of OrderController, test fixture and this QA evidence. Required exact-final-head CI: Repository Policy, FOODEX V2 Isolation, PHP Syntax/Pint/PHPStan/security/OpenAPI, Laravel functional suite, AR/EN localization, MySQL/Redis fresh install/upgrade/recovery, terminal required-ci-gate. Flutter jobs may skip only when change detection proves no Flutter source delta; no claim of Flutter test execution without evidence. No merge until all applicable checks succeed on last HEAD.

The broader source requirements in #2733, #2731 and #2503 (full Customer journeys, invoices/notifications, Android signed staging, live/manual UAT) remain OPEN; this leaf completion cannot claim them. No main merge, tag, release, production cPanel change or signed Setup/Update ZIP release.
