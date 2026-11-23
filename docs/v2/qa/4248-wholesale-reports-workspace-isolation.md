# V2 P031 — #4248 Wholesale Reports HTTP channel and actor isolation

**Parent:** #2782, master #2503. **Integration:** `Foodex_V2` only.

## Source boundary

The canonical Admin `GET /admin/b2b/reports` route resolves to `admin.b2b.module`, `B2bWorkspaceController::show('reports')`. Existing `MODULE_PERMISSIONS['reports']='reports.view'`, `authorizeModule`, `WholesalePrincipal`, and `wholesaleStoreIds` remain authoritative. A `channel` or `store_id` query argument is not permission to switch to a Retail principal.

The new `backend/tests/Feature/V2WholesaleReportsWorkspaceIsolationTest.php` is an authenticated seeded **real HTTP** Laravel regression, with two user locales and an isolated synthetic Retail store:

| Request | Expected |
| --- | --- |
| B2B_ADMIN, plain canonical Reports route, AR/EN | 200, RTL/LTR respectively |
| B2B_ADMIN, forged `channel=b2c&store_id=<Retail store>` | Still authorized Wholesale Reports 200, RTL/LTR respectively |
| B2C_STORE_ADMIN with assigned Retail store, forged B2B URL/channel | 403 |
| Active roleless principal | 403 |
| All GET requests | No changes to orders, payments, invoices or driver assignments |

The test does not claim a full column-by-column Reports data export certification, nor replace the historical Reports Center contract. It specifically pins the existing canonical Wholesale workspace GET route and its principal/role boundary. There are no existing controller, route, migration, UI, API, RBAC or shared-test edits.

## Security and release gates

**REVIEW_RISK: HIGH** for cross-channel/tenant assertions. Same-account author comment does not substitute independent GitHub approval where required. Review both changed files and run exact-head Repository Policy, FOODEX V2 Isolation and Required CI with Laravel Backend tests, MySQL/Redis acceptance and terminal `required-ci-gate`. Do not weaken negative assertions. Original Issue #4248 and one original PR; guarded merge to `Foodex_V2` only after all gates, then verify and close leaf. Keep parent #2782 and master #2503 OPEN. No `main`, deploy, release, tag, APK, or production account actions.

**Mobile impact:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Only a backend Admin HTML GET regression test and QA documentation are added, preserving the B2B VAN ONLY / B2C DRIVER ONLY dispatch rules.
