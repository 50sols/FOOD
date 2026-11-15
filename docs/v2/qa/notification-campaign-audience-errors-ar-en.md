# V2 #3698 — Localized notification campaign audience/application rejection

**Integration:** Foodex_V2 only. Parent V2 master #2503 remains OPEN. No release/tag/main/production changes.

## Source / acceptance

The canonical Dashboard `POST /admin/notification-campaigns` and `PATCH /admin/notification-campaigns/{campaign}` already authorize `notifications.manage` and the effective B2B/B2C store/channel and role scope, before calling `assertAudienceAppCompatibility()`. Four user-visible validation messages were hard-coded English in both AR and EN Dashboard modes. Mutations did not explicitly set actor locale: only `index()` did. No unauthorized dispatch or cross-tenant vulnerability was proven.

- `PromotionalNotificationCampaignController.php`: set the trusted actor locale **after** `authorizeAccess` authentication and permission decision; the index/read, store, update, state and send-now paths now consistently use it. Replace only four incompatibility messages with `notifications.*` localized keys; continue rejecting Customer→operations apps, Driver→Customer/Van, Van→Customer/Driver, and Van/B2C combinations. Preserve `app`/`target_channel` validation keys, HTTP 422, all input validation, scope, and audit.
- `backend/lang/ar/notifications.php`, `backend/lang/en/notifications.php`: one-to-one AR and EN business error phrases.
- `PromotionalNotificationCampaignTest.php`: authenticated management HTTP regression for both actor locales across all 4 invalid combinations (JSON 422 and exact field/message); denied user 403; no campaign/audit write. Update path 422 keeps original draft and audit intact. Pre-existing positive, per-store, B2B/B2C, review-token, scheduler and notification tests remain.
- There are no changes to views, Sidebar, client DTOs, notifications, queue/scheduler, stock, dispatch, payments, migrations or schema. UI/RTL/LTR layout stays unchanged.

## Three app impact classifications

| App | Impact |
|---|---|
| Customer | **Not affected.** No Customer API/Flutter/recipient targeting or customer notification payload changes; incompatible campaigns are already rejected before notification creation. |
| Driver | **Not affected.** No Driver API/Flutter or dispatch/assignment change; Driver app audience compatibility unchanged. |
| Van | **Not affected.** Van B2B-only notification eligibility and existing Van app payload remain unchanged. Van/B2C rejection remains in place with translated Dashboard error text only. |

## High-risk technical review and exact-head gates

REVIEW_RISK: HIGH because user-defined audiences and application segmentation are security-relevant. Must inspect the five issue-owned files, authentication/permission ordering, B2B/Retail channel and storefront boundaries, error text and no-write negative tests. Document same-account technical review as such, **not** independent Github `APPROVED` status. Exact-head Repository Policy, V2 Isolation, full Required CI PHP/Pint/PHPStan, Laravel, AR/EN, MySQL/Redis, terminal gate must each be SUCCESS. No merge, close or final-release claim before that evidence. Parent #2503 retains final staged/manual/device acceptance.
