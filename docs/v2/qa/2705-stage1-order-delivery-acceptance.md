# FOODEX V2 #2705 — ERP Stage 1 wholesale order and delivery acceptance

**Owner source:** T115R1 / master #2503. **Target:** `Foodex_V2` only. **Issue:** #2705. **Risk:** HIGH (order creation, tenant isolation and fulfillment actor). This is an executable integration increment; do not equate a new test with an accepted green run.

## Existing canonical behavior, no alternative authority

- Wholesale buyer and catalog/price tier/warehouse belong to the canonical B2B principal. The existing authenticated `POST /admin/b2b/orders` creates an actual order, reserves from the selected B2B warehouse and persists its B2B channel; the test deliberately does not create the source order directly.
- Existing authorized Dashboard B2B Order workspace remains its navigation owner. Existing `OrderManualDispatchService::assignVan`, `VanRegistryService` and authenticated `/api/v1/van/orders/{id}/execution*` control fulfillment. B2B is **VAN ONLY**; B2C is **DRIVER ONLY**. No alternate manual B2B-Driver path is introduced.
- The new Stage 1 test uses a seeded product, price tier, customer/account and warehouse; AR/EN Dashboard rendering; real Van representative assignment; REST status transitions `assigned → accepted → picked_up → out_for_delivery → delivered`; staged proof upload; and an explicitly synthetic paid COD fixture. Test payment fixture is **not** Treasury/AR/GL source certification.
- Negative test: B2B_ADMIN cannot use a Retail warehouse and a Retail-only actor cannot create a Wholesale order; neither denied request creates an order, reservation or fulfillment assignment.

## Executable checks

`backend/tests/Feature/V2Stage1WholesaleOrderDeliveryJourneyTest.php`

1. `test_wholesale_dashboard_order_is_delivered_by_its_assigned_van_without_driver_fallback`: authenticated B2B creation, authoritative principal warehouse, zero legacy Driver assignment, RTL/LTR B2B page, real Van execution HTTP through delivery proof and terminal state.
2. `test_retail_actor_and_foreign_store_warehouse_cannot_create_wholesale_orders`: Retail store/provenance and role 403/422, no orders, no reservation side effects, no assigned Van/Driver.

**Additional existing independent regressions**: `B2bAdminWorkspaceTest`, `OrderOperationsIsolationTest`, `OrderTerritoryRoutingServiceTest`, `OrderManualDispatchServiceTest`, `VanDeliveryExecutionTest`, `V2ErpWholesaleRetailScopeIsolationTest`.

## Permissions and impact

- **Wholesale Dashboard:** `B2B_ADMIN` role + canonical B2B admin routes and server warehouse validation. No Super Admin blanket permission or ERP entitlement bypass.
- **Van:** existing protected `app:van` API and active Van representative only. Backend test exercises the existing API, not a Flutter change.
- **Customer:** B2B order creation via existing Dashboard; no mobile API contract or Flutter source change.
- **Driver:** no Driver assignment or runtime change; B2C remains the only Driver delivery channel.
- **Van app:** no Flutter change; server execution contract must remain backward-compatible.
- **Financial implications:** COD fixture is illustrative payment precondition for delivery; no Finance posting, source remittance or ledger certification is claimed. Stage 5 accounting #2709 and source-ledger acceptance remain independent.
- **Localization/UI:** existing B2B Dashboard in both Arabic RTL and English LTR; no new screen/sidebar, translations or design elements.

## Closure requirements

Exact new-head Required CI (Laravel full suite, MySQL/Redis where applicable), Repository Policy, V2 Isolation, security/tenant coverage, file-level HIGH-risk review, and source-versus-T115R1 acceptance must all finish successfully. Manual/browser/staging evidence or other necessary end-to-end gaps must remain explicitly pending, not marked PASS. Do not merge a draft or close #2705 while any required item fails or is unknown. Never target `main`, production, tags, APKs or release promotion.
