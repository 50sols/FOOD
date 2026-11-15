# V2 #3835 — Stable Notification API Pagination (AR/EN)

**V2 parent:** #2503. **Integration base:** `Foodex_V2` only. No production, release/tag, APK or `main`.

## Source and implementation

- Canonical endpoint: authenticated `GET /api/v1/notifications` served by `NotificationController::index` and the existing `NotificationAudience::apply` authorization predicate.
- Defect: `latest('published_at')` orders only by a non-unique timestamp. A set of simultaneously published rows has no specified relative order, which leaves subsequent offset pages free to contain repeated or omitted notification IDs across requests.
- Fix: retain the exact audience and publication filters, `per_page` bounds, paginator and response DTO, but order by `published_at DESC, id DESC`. The unique primary-key secondary sort deterministically orders equal timestamps. It does not make offset pagination a snapshot: concurrent inserts/deletes can still shift pages. Cursor pagination would require a separate, versioned API design and consumer review.
- No schema/migrations, mutating endpoints, notification publishing, read-receipts, locale policy or authorization logic altered. No new navigation, UI route or duplicated notification surface.

## Regression and security

The dedicated `V2NotificationStablePaginationTest` publishes five B2C notifications with the same past timestamp; it fetches pages 1–3 in Arabic and English, verifies descending unique IDs, exact per-page count and metadata, localized title and no read-receipt side effects. A notification targeted at another user must not appear in the returned pages. Existing `NotificationAdministrationTest` and `NotificationAudienceIsolationTest` remain required regression tests. No notification payload or private data may be printed to logs.

**Threat/failure review:** Tie ordering is presentation/query behavior, not a new authorization primitive. The audience query still runs before ordering/pagination. The negative foreign-user fixture verifies the sort does not change scoping. Mixed B2B/Retail users and driver/van recipients continue to be resolved by existing server-side audience filters. Rollback is a one-line query sort restoration, with no DB/side-effect rollback.

## Backend / Dashboard / Customer / Driver / Van impact

| Surface | Impact | Parity decision |
| --- | --- | --- |
| Backend API | Read list has a canonical secondary sort; response fields, auth and HTTP status unchanged | **Affected**; dedicated HTTP regression |
| Dashboard | Existing notification reader may see a more stable order for tied timestamps; no Dashboard UI or route changes | No new surface; existing administration suite |
| Customer | Existing `/notifications` consumer receives same DTO and locale labels; equal-timestamp ordering is now deterministic | No Flutter code/route/protocol change, no independent mobile feature |
| Driver | Same shared read endpoint, unchanged store/channel authorization and DTO | No Flutter code or action contract delta |
| Van | Same shared read endpoint, unchanged Van scope and DTO | No Flutter code or action contract delta |

**Risk classification:** `REVIEW_RISK: HIGH` pending exact-head technical review because a shared endpoint is read by multiple apps. File-by-file audit must cover the controller, test, and this note, and must verify that the audience boundary is unchanged. Independent GitHub peer approval is not claimed.

## Required acceptance

Fresh Issue claim + issue-scoped branch + one PR, base **Foodex_V2**. PHP syntax, Pint, static analysis, security, full Laravel, AR/EN localization and exact-head Repository Policy, FOODEX V2 Isolation, MySQL/Redis install/upgrade/recovery and terminal Required CI Gate must be **SUCCESS**. If any check is missing/red, leave PR and Issue open. Merge only into `Foodex_V2` with documented reviewed HEAD, CI runs and integration SHA. Final human/device/staging acceptance stays with #2503; do not describe it as production validation.
