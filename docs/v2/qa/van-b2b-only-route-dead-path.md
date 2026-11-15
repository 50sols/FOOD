# V2 #3692 — B2B-only Van order routes, no B2C fallback

**Integration:** Foodex_V2 only. Parent #2739 (Van app) and master #2503 remain open. No main merge, release, APK distribution or production operation.

## Why

The canonical `VanOrderController::assertCustomerScope` already aborts with HTTP 404 if the URL `type` is not `b2b`, before any catalog, options, quote or write processing. However the four routes still accepted `b2c` as a legal route token and the controller retained dead branches invoking B2C lookup and the generic order creation service. These were unreachable under the existing guard, **not a proven live bypass**. Removing them avoids a future accidental B2C→Van fallback and keeps B2B=VAN ONLY / B2C=DRIVER ONLY explicit at both routing and controller layers.

## Source and contract classification

- `backend/routes/api.php`: four named Van customer catalog/options/quote/orders endpoints now match `type=b2b` only; requests for B2C returned HTTP 404 before and continue to return 404.
- `VanOrderController.php`: eliminate dead B2C catalog, addresses, warehouse, payment, quote and order branches. The existing `assertCustomerScope`, assigned seller-store ID, live B2B Van visit check, commercial quote/create authority, idempotency key, paging, B2B price resolver and all JSON fields remain.
- `VanOrderControllerTest.php`: authenticated real Van actor tests four B2C requests are 404 and cannot create orders or audit records. Existing ten B2B authorized/denied/price/idempotency tests remain the positive/negative regression matrix.
- No migrations, tables, financial formula/SoD, user status, permissions, frontend strings or UI changes; no change to Arabic/English or RTL/LTR presentation.

## Affected applications

| Surface | Contract impact |
| --- | --- |
| Van | Van-facing API URL `type=b2c` remains 404, now rejected at route matching. Existing `#2739` mobile integration issue tracks this; Van Flutter exclusively uses B2B routes and needs no DTO/code change. |
| Customer | None; no Customer API/Flutter behavior changed. |
| Driver | None; no Driver API/Flutter or B2C fulfillment change. |
| Dashboard | None; no navigation or UI code changed. |

## Technical review and gates

REVIEW_RISK: HIGH — authorization and fulfillment guard. Review exact four route declarations, removed unreachable controller branches and HTTP test before merge. CI must verify Repository Policy, FOODEX V2 Isolation, full FOODEX Required CI Gate (Laravel, PHP syntax/Pint/PHPStan, localization, MySQL/Redis deployment acceptance and terminal gate). No success assumed until terminal exact-head evidence is recorded. Human/device/staging acceptance remains under #2503; this issue's bounded code acceptance cannot close the parent.
