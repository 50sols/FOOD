# V2 #3697 — B2C Driver reminder 409 Arabic/English errors

Integration **Foodex_V2 ONLY**; parents #2735 (B2C Driver execution/notifications), #2818 (P082 authorized Dashboard), #2503 (master). No main, release, tag, APK, production or cPanel.

## Source and scope

Existing `OrderOperationsController::remindDriver`, canonical `POST /admin/operations/orders/{order}/remind-driver`, requires a signed-in operator and `managedOrder(..., 'orders.manage')` with store/channel scoping before any reminder operation. Two hard-coded English HTTP 409 messages covered (a) no active driver assignment and (b) assigned profile missing a linked app user. The first is reachable on valid B2C unassigned/cancelled/delivered orders. **Schema caveat:** `drivers.user_id` is declared NOT NULL and constrained by the canonical delivery-platform migration. Therefore, the second is a defensive guard against inconsistent legacy data, not a state that a real `RefreshDatabase` test can construct legally. Do not disable FK/NOT NULL to fake its HTTP path. Both strings are now localized with existing `msg(ar,en)`; actual HTTP regression targets the first valid failure path and tests protected cross-store denial.

The action now sets the authenticated actor's explicitly supported `ar` or `en` locale, with the standard Arabic fallback, before protected `managedOrder` and 409 decision. No error status, redirect, permission, store/channel selection, assignment state, idempotency, notification, audited success event or push queue payload changes.

## Tests and observed contracts

Existing `OrderOperationsIsolationTest::test_driver_reminder_is_actionable_persisted_and_queued` verifies the genuine positive B2C assigned-driver dispatch. Added `test_driver_reminder_409_is_localized_and_never_queues_for_missing_assignment`: genuine `RefreshDatabase` + `CoreReferenceSeeder`, store-scoped B2C operator, real Retail customers/orders in two stores, two operator locales, HTTP 409 JSON with exact translated message, HTTP 404 foreign-store denial, no driver assignment, unchanged notification count and reminder-audit-event count, no `DispatchPushNotification` queued. It uses **only valid persisted production-like rows**.

Focused command: `cd backend && php artisan test --filter=test_driver_reminder_409_is_localized_and_never_queues_for_missing_assignment`.
Whole-file command: `cd backend && php artisan test --filter=OrderOperationsIsolationTest`.
Required exact-head Policy, V2 Isolation and Required CI: PHP syntax, Pint, PHPStan, Composer security, OpenAPI, AR/EN, full Laravel tests, MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate` must all succeed. Existing positive test protects the notification contract.

## Cross-app and UI effect

| Surface | Analysis |
| --- | --- |
| Dashboard Operations | Two AR/EN error strings under existing authorized action; no screen/route/component changes |
| Public API | No public API, OpenAPI or mobile DTO contract changes |
| Customer Flutter | Not affected, no customer identity/order/status notification changes |
| Driver Flutter | Not affected, preexisting bilingual push/success payload unchanged; Dashboard-only error text |
| Van Flutter | Not affected, Van B2B-only routing/visits/custody unchanged |

RTL/LTR, responsive/a11y and all existing localized UI unchanged. **B2C DRIVER ONLY / B2B VAN ONLY** unchanged. This is MEDIUM-risk (authorized operation rejection, no financial or tenant write); engineering `COMMENTED` review is not an independent GitHub `APPROVED` review.

Three unique paths: `OrderOperationsController.php::remindDriver` only; one new `OrderOperationsIsolationTest` method; this QA file. No overlapping active V2 worker PR file fences at preflight.

Close the atomic implementation as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED only after exact-last-HEAD required checks pass, SHA-guard squash into Foodex_V2 and integration verification. Parent #2735/#2818/#2503 remains OPEN for broader operational/UAT acceptance. No main or production promotion.
