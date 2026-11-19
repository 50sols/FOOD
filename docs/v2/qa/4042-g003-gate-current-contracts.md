# V2 #4042 — G003 current canonical C13 / #734 integration evidence

**Original Issue:** #4042, parent gate #2744, parent master #2503.
**Integration:** `Foodex_V2` ONLY. **REVIEW_RISK: HIGH** (CI acceptance changes).
**Purpose:** align two *existing* executable acceptance inventories to real tests after the unified Customer entry and Wholesale navigation evolution, while preserving stronger Retail lifecycle/ownership checks. This does not rewrite, add, or run any Flutter app source tests on production; no API, routes, migrations, ledger, dispatch or runtime behavior changes.

## Source-traced replacements and behavioral equivalence

| Shell | Old stale reference | Current authoritative source evidence |
| --- | --- | --- |
| C13 | Business-only entry login | `customer_navigation_test.dart`: `entry route renders unified login without Marketplace bypass`. Asserts `unified-customer-auth-screen`, `c13-business-login-title`, safe guest-action absence, AR/EN toggle and no bypass. |
| C13 | Login to obsolete Screen 2 dashboard | `successful non-merchant login lands on canonical Wholesale Home`: authentic login through `FoodexCustomerApp`, resolves `WholesaleHomeDesignScreen`, rejects `PlatformMarketplaceScreen` and legacy login title. |
| C13 | Dashboard Store icon | `b2b_journey_test.dart`: `Wholesale shopping resolves principal store on first open without store id`, asserts principal store `70`, scoped product API path and correct rendering. The old dashboard shopping widget is intentionally absent. |
| C13 | Four-footer buttons, Shopping=>Wholesale Home | `customer_b2b_footer_test.dart`: `Wholesale footer keeps Home visible and Shopping opens Wholesale products`, checks actual six destinations, B2B store/query channel, protected product/offers/profile navigation. |
| C13 | B2B manual order old tier/MOQ title | `DashboardOrderManagementTest.php`: `test_b2b_dashboard_order_uses_approved_customer_tier_price_and_finalizes_on_assignment` tests current authorized tier+fulfillment; existing separate pricing/credit assertions remain required by inventory. |
| C13 | Historical `Wholesale / Retail` literal | `business_account_profile.dart` now labels `Wholesale B2B` and `Retail B2C`; both required separately. |
| Operational | Obsolete compact More-only hub test | `b2b_journey_test.dart` now asserts `B2B profile is the complete visible account and finance hub` and checks the actual scoped account summary, statement entry and financial totals; retired compact-only marker is not a valid current requirement. Initial [Required #44627500035](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44627500035) failed both C13 and Operational on exactly this next stale marker (other child outcomes still pending then). Fixed on original branch without altering app tests. |
| Operational | Broad old `transition_own_order` name | `OrderOperationsIsolationTest.php`: `test_retail_admin_cannot_operate_foreign_order_but_can_cancel_own_new_order` tests foreign store 404/order unchanged; owner pending→cancelled with canonical `order_status_history`. Gate additionally requires `from_status=pending`, `to_status=cancelled` and `test_dashboard_rejects_inactive_lookup_status_and_illegal_lifecycle_jump`. |

No shell requirement was removed; legacy markers were replaced with verified current markers, and additional account-label and status-history checks were added. Both C13/Operational jobs still **execute** their full inventories. Missing file/text causes a nonzero exit. No `|| true`, skip flags, exclusions, workflow deactivation or external owner contract waivers.

## Durable regression and threat review

- `scripts/tests/test_c13_operational_gate_contract.py` scans C13 dynamic/static evidence references and all Operational static evidence paths; missing source or stale marker fails. Validates no bypass of Customer sign-in, actual Wholesale Home and real B2B route authority, and specifically scopes the Retail negative + positive cancellation assertions to the true PHP test method.
- The operational script invokes the Python test every time, including from nested C13. The test and both original gate scripts run as required GitHub jobs on the exact final PR HEAD; a successful earlier merge-commit from another PR is *not* accepted as evidence here.
- **Risk classification:** HIGH, since CI scripts could suppress important acceptance. File-by-file audit must show each changed marker maps to a real source assertion, and no missing checks/gate suppression. This is a source-authoritative test-alignment fix, not permission to rewrite behavior, downgrade older acceptance without parity, or close sibling ERP/Finance leaves.
- **Rollback:** revert only this issue-scoped PR if any canonical accepted feature changed unexpectedly; no DB or persistent business data to restore. Never modify production. Any failure should remain visible as red Required CI.

## Cross-app/API impact

Customer: *no app/API/source changes*; static C13 shell reads previously merged Customer widget tests and AR/EN code without modifying them. Driver: no app/API/dispatch change. Van: no app/API/dispatch change. B2B=VAN ONLY and B2C=DRIVER ONLY remain explicitly protected by existing immutable regression scripts, including every unchanged acceptance reference. Backend: no operational source/test change; CI-only static reference alignment against current Backend assertions.

## Acceptance / release restrictions

- Required final-head CI, Policy and V2 Isolation must all be terminal SUCCESS. In-scope C13 and Operational gate jobs must actually execute SUCCESS, not SKIP. Capture original branch HEAD, PR merge SHA and post-merge target evidence.
- Parent gate #2744 and master #2503 remain OPEN until all remaining V2 backend ERP/P112 coding and final owner acceptance. #4042 can close as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only if this atomic test-alignment PR is actually merged to `Foodex_V2` with all exact-head required checks green and technical review PASS.
- No main/tags/releases/production/cPanel/Flutter development.
