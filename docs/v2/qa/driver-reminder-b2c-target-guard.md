# V2 #3706 — enforce Retail-only, same-store Driver reminder target

Parents: #2735 (B2C Driver), #2782 (tenant/channel isolation), #2503 (V2 master). **Sole PR target: Foodex_V2**. Absolutely no main, release/tag, APK, cPanel or production action.

## Confirmed existing defect

`OrderOperationsController::remindDriver` in the authorized Dashboard Operations Controller already enforces `orders.manage` store/channel scope for the acting operator. It historically selected active `driver_assignments` by `order_id` and then loaded `drivers` by assignment driver ID, without validating that **the order itself is B2C**, assignment `store_id` / `assignment_type` match the order, or the actual driver profile `store_id` / `driver_type` / `is_active` matches. Independently valid FKs do not imply cross-table store/channel consistency. Stale/imported/manually-corrupted historical assignments could therefore target a different store/channel or route a B2B Driver reminder. This is a deterministic fail-closed boundary defect; there is no unsupported claim of exploitation or SQL injection.

The prior atomic #3697 / PR #3699 already merged AR/EN 409 and inactive linked user checks. This issue **does not duplicate them**.

## Scoped implementation

- Reuse the canonical `POST /admin/operations/orders/{order}/remind-driver` and existing `managedOrder(..., 'orders.manage')` authorization. After actor auth and scope, reject **any non-`b2c` order** with HTTP 404, before reading DriverAssignment, even if a historic B2B DriverAssignment exists. Retains strict B2B **VAN ONLY** / B2C **DRIVER ONLY** contract.
- Keep the existing active-assignment lookup/status filters and missing assignment 409. Require the returned active `DriverAssignment.assignment_type='b2c'` and `store_id===order.store_id`, else localized 409.
- Require the selected `drivers` profile to be `driver_type='b2c'`, `store_id===order.store_id`, `is_active=true`; join the existing linked `users` record and preserve the previously delivered `users.is_active` guard. Fail with localized AR/EN 409 before any notification, push, audit or state change.
- No new route, role, grant, migration, queue, financial settlement, UI component or public API contract. Successful valid B2C reminders retain the exact bilingual payload and push-after-commit behavior.

## Real test and allowed/denied matrix

`backend/tests/Feature/OrderOperationsIsolationTest.php::test_driver_reminder_never_notifies_wholesale_or_foreign_store_targets` uses a real `RefreshDatabase` and `CoreReferenceSeeder` with legally persisted FK-valid fixture rows:
- Authorized `SUPER_ADMIN` request to a real `b2b` Wholesale order with historical active B2B Driver assignment **404**; no Driver notification.
- Retail store-scoped operator uses two actual selected locales (Arabic and English); current B2C order has an assigned foreign-store Driver, a same-store B2B Driver, a deactivated B2C Driver, a wrong assignment channel, or wrong assignment store, each **409 with localized literal error**. Reversible fixture updates between checks reproduce cases without disabling database referential integrity.
- Verify two order channels/statuses preserved, notification count unchanged, reminder audit count unchanged, and zero `DispatchPushNotification` queued from any rejected request.
- Existing `test_driver_reminder_is_actionable_persisted_and_queued` stays unchanged and covers a real eligible B2C Driver positive path with persisted bilingual notification and queued job.
- Additional existing #3697 test covers no-assignment and linked `users.is_active=false` AR/EN 409, foreign-store direct order 404 and no side effects.

Focused: `cd backend && php artisan test --filter=test_driver_reminder_never_notifies_wholesale_or_foreign_store_targets`. Full file: `cd backend && php artisan test --filter=OrderOperationsIsolationTest`.

## Cross-app impact

| Area | Effect |
| --- | --- |
| Dashboard Operations | Existing authorized reminder action returns fail-closed 404/409 for invalid channel/store target, bilingual error. No new UI/route |
| Backend/management API | No public/mobile API/DTO or OpenAPI shape changes |
| Customer Flutter | No API, order status or notification change |
| Driver Flutter | Existing successful Retail Driver notification payload unchanged; invalid server-authorized notifications no longer emitted |
| Van Flutter | No Van contract or code change; B2B Van-only fulfillment stays authoritative |

AR/EN behavior is preserved from #3697. RTL/LTR/responsive/a11y components unchanged.

**REVIEW_RISK HIGH** — store/channel isolation and dispatch. Scope is exactly one Controller method, one new real Laravel regression method, and this QA doc. Review all three files, record a `COMMENTED` same-account technical audit as engineering evidence (not independent GitHub APPROVED). Require exact final HEAD Repository Policy, FOODEX V2 Isolation, full Required CI including PHP syntax, Pint, PHPStan, security/OpenAPI, AR/EN, full Laravel backend, real MySQL/Redis setup/upgrade/recovery, and terminal `required-ci-gate` SUCCESS. Merge with expected head SHA into **Foodex_V2** alone, verify integration and close this atomic source leaf CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED while source parents #2735/#2782/#2503 stay OPEN for wider UAT.
