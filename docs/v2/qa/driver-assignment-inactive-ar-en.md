# V2 #3825 — inactive Driver assignment denial HTTP409 localized AR/EN

**Integration:** Foodex_V2 only. Parent Driver journey #2735 and V2 master #2503 remain OPEN until their source and human/staging/device acceptance finishes. No main integration, tag, release, ZIP/APK publication or production deployment.

## Existing authorization, UI placement and fail-closed validation

The existing scoped Operations/Orders assignment action, `POST /api/v1/admin/deliveries/assign`, receives an authenticated B2C_STORE_ADMIN authorized for `orders.dispatch` in the **exact** Retail store of the order. `DriverAssignmentController::assign` validates driver and order IDs, resolves order channel and authoritative tenant/store, and explicitly forbids B2B Driver assignment with the already translated VAN ONLY error. It then refuses an inactive Driver with HTTP409. Only the human-facing English sentence `Driver must be active before assignment.` becomes a new paired `driver_assignment_errors.driver_inactive` AR/EN string using the controller's **existing** trusted authenticated admin locale. The existing 409 `message` field, driver active/available/role/channel/store checks, source authorization, reassignment locks, audit and notifications remain unchanged.

**UI placement:** REUSE existing authorized Operations → Orders → exact order → Assign Driver action and its current error surface. No new sidebar/nav, page, form, interaction, mobile-web/RTL/LTR markup, API route or DTO. Error text is now correct in Arabic or English; original English unchanged verbatim.

## Real seeded HTTP and data-preservation proof

`DriverAssignmentLifecycleTest::test_retail_admin_inactive_driver_assignment_rejection_is_bilingual_and_has_no_writes` uses real `RefreshDatabase/CoreReferenceSeeder`, scoped B2C order in `ready`, a B2C_STORE_ADMIN assigned to the store, and a B2C_DRIVER who belongs to the same store but has `is_active=false`. Under both stored admin `User.locale` AR and EN and contrary `Accept-Language`, actual Sanctum-authenticated `POST /api/v1/admin/deliveries/assign` must return HTTP409 and the exact localized `message`. Assert no added driver assignments, orders, status history, audit, notifications, payments or reserved stock; the original order remains `ready` and driver remains inactive. Existing valid Retail assignment/delivery, foreign-store and B2B VAN ONLY tests stay unchanged.

## Customer, Driver, Van and Dashboard impact

- **Dashboard:** existing authorized operation's human error text translated; no UI route, navigation, permission or responsive markup changes
- **Driver:** no Driver Flutter/app API schema or mutation change; inactive drivers remain ineligible, B2C DRIVER ONLY fulfillment remains
- **Van:** no Van app or API change; B2B remains VAN ONLY, protected by the earlier channel guard
- **Customer:** no Customer app/storefront/checkout changes
- **Security:** REVIEW_RISK HIGH (fulfillment/authorization and assignment), same-account COMMENTED technical file-by-file audit is not independent GitHub APPROVED; the rejected assignment never writes audit/notifications or assignment state

Before guarded merge, verify exact-final-head Repository Policy, V2 Isolation, PHP/Pint/PHPStan/security, AR/EN, full seeded Laravel tests, real MySQL/Redis install/upgrade/recovery and terminal Required CI SUCCESS, no competing file lease or base drift overlap. Merge only to Foodex_V2 and close atomic leaf CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED with tested and integration SHA; parents remain OPEN and no production authorization.
