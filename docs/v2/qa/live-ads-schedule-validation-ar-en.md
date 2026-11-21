# V2 #3729 — Live Ads scheduling validation AR/EN

- Program parent: #2503; implementation target `Foodex_V2` ONLY.
- Existing source: `LiveAdController::validated` on the authorized Advertising → Live Ads create/update routes.
- Related already merged work: P235 Live Ads Sidebar #2565 and scoped literal search #3584. Neither of their tests/files is modified.
- REVIEW_RISK: MEDIUM (admin content create/update). Same-account COMMENTED technical audit does not constitute independent APPROVED reviewer.

## Specific unchanged machine contract

The existing date-window validation rejects an `ends_at` value earlier than or equal to `starts_at`, comparing `CarbonImmutable` in `Asia/Kuwait`. Only the human-facing `ValidationException::withMessages` text changes to `__('live_ads.ends_after_start')` backed by matching AR/EN keys:

| Actor locale | HTTP | Error key | Expected human message |
| --- | --- | --- | --- |
| Arabic | 422 | `errors.ends_at.0` | يجب أن يكون وقت الانتهاء بعد وقت البدء. |
| English | 422 | `errors.ends_at.0` | End time must be after start time. |

Date rules, route/action permissions, Retail store entitlement, B2B/B2C channel scope, audit and Live Ad creation/update semantics are unchanged. Validation precedes image persistence, ad write, and audit logging.

## Automated evidence contract

New `backend/tests/Feature/V2LiveAdScheduleValidationLocaleTest.php` uses `RefreshDatabase` and `CoreReferenceSeeder`, true Laravel authenticated POST/PATCH JSON to the existing named routes, B2C store-scoped manager, AR/EN actor locale:
- Invalid date ordering on create returns 422 with exact locale-specific `ends_at` text; ads and audit row counts unchanged.
- Valid schedule through the same real POST redirects normally and persists the owned B2C ad.
- Invalid date ordering on update also returns exact 422; no change to existing ad's title/end date, ad count or audit count.
- Roleless actor is denied 403; a Retail manager cannot create for another Retail store (404); neither denial creates ads or audits.
- Existing Live Ads Sidebar, read scope, Customer localized feed, and literal search tests remain untouched.

**Do not claim PASS until actual exact final HEAD GitHub CI reports success.**

## Impact matrix

- Backend: localized admin validation `ends_at` message only; HTTP contract, tenant store, time normalization, persistence unchanged.
- Dashboard: existing Advertising → Live Ads editing form; standard validation display, existing AR/EN and RTL/LTR without layout additions.
- Customer: no Customer API/live-ad feed payload, Flutter, published ad or notification contract change. Existing API query and app rendering stay untouched.
- Driver: no Driver API/Flutter change; B2C DRIVER ONLY.
- Van: no Van API/Flutter change; B2B VAN ONLY.
- No new routes, entitlements, schema, migration, Sidebar or release artifacts.

## Required gates

One branch, one issue-scoped PR; five owned files only. File-by-file source audit, appropriate Laravel/authorization tests, Pint/PHPStan/security, localization quality, Repository Policy, V2 Isolation and terminal Required CI/MySQL/Redis all **SUCCESS on final exact source SHA**. Recheck peer file fences and current isolated base, guarded merge exclusively to `Foodex_V2`, record merge SHA and tests, then close #3729 only `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` under parent #2503. No `main`, tags, release, APK, production or cPanel action.
