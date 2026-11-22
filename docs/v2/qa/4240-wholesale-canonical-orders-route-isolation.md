# #4240 — Canonical Wholesale Orders HTTP isolation (P031 leaf)

## Contract

Issue #4240 is an independent, bounded Laravel HTTP acceptance regression under parent #2782 and V2 master #2503. The canonical `/admin/b2b/orders` route is handled by `OrderOperationsController::b2bIndex`; it is not the same entry point as the generic `/admin/operations/orders` URL. `b2bIndex` unconditionally merges `channel=b2b` before the shared `index` enforces operational channel/store `orders.view` scopes.

## Reproducible matrix

`backend/tests/Feature/V2WholesaleCanonicalOrdersRouteIsolationTest.php` uses seeded, synthetic actors and real Laravel HTTP GET:

| Case | Expected |
| --- | --- |
| Active B2B_ADMIN, canonical Wholesale store, AR and EN | 200, canonical `admin.order-operations`, selected b2b channel, correct RTL/LTR |
| B2B_ADMIN, forged channel=b2c or all with authorized Wholesale store | 200 but selected channel remains b2b |
| B2B_ADMIN, forged Retail store_id | 404 |
| Active Store A B2C_STORE_ADMIN, Wholesale URL with Wholesale store_id | 403 |
| Active roleless actor, Wholesale URL | 403 |
| All cases | No additional orders, payments, invoices or driver assignments |

No production routes/controller/service, permissions, user accounts, API, schema or Flutter applications are modified. No read-only success is represented as real customer-account or financial approval acceptance.

## Risk and mobile impact

REVIEW_RISK: HIGH due to cross-channel and cross-store security assertions. Negative cases target the actual middleware/controller entry and cannot be inferred from Sidebar visibility. In a failed CI run, fix a legitimate source defect only after resolving active file ownership; never weaken denial assertions just to green the gate. Same-account review is an engineering audit, not independent APPROVED review.

**MOBILE IMPACT — Customer / Driver / Van:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Admin HTML GET test-only change; no app/API/dispatch source changes. Existing **B2B VAN ONLY** and **B2C DRIVER ONLY** remain unchanged.

## Integration acceptance

Original scoped branch `test/4240-v2-b2b-orders-route-isolation`, one PR targeting `Foodex_V2` only. Check Repository Policy, FOODEX V2 Isolation, exact-final-head Required CI including full Laravel tests, real MySQL/Redis deployment acceptance and terminal `required-ci-gate`. Record actual statuses and SHA-guarded merge only after success. Close atomic #4240 only when code and tests are fully verified and merged. Keep parent #2782 and #2503 open for broader exhaustive route and final owner acceptance. No `main`, tag, release, deploy, production, mobile build or APK changes.
