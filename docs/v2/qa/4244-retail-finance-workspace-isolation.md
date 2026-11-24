# #4244 — Retail Finance workspace cross-store / channel HTTP isolation

## Canonical source / task boundary

Parent #2782 (P031) and master #2503, isolated integration target `Foodex_V2` only. The canonical Retail Finance page uses `GET /admin/b2c/finance` via `admin.b2c.module` (module `finance`) and `B2cWorkspaceController::show`, not the Wholesale Finance export route or the canonical Retail/Wholesale Orders controllers. This tests existing server enforcement, not Sidebar visibility. There is no new privilege, endpoint, migration, UI or production behavior.

## Actual Laravel seeded HTTP acceptance

New `backend/tests/Feature/V2RetailFinanceWorkspaceIsolationTest.php` seeds canonical roles with synthetic active Store A (Retail), Store B (Retail), Store W (Wholesale), a Store A Retail Store Admin, a Wholesale-only B2B Admin and a roleless principal. For both Arabic (RTL) and English (LTR), it asserts:
- Store A Retail Finance GET returns 200 and the requested layout direction.
- A forged `channel=b2b` query cannot switch the Retail Finance GET to a Wholesale route.
- Explicit Store B or Wholesale Store W `store_id` returns 404 to the Store A actor.
- Conflicting `store` and `store_id` aliases, plus nonpositive, malformed and noncanonical numeric store selectors, fail closed at 404.
- A Wholesale-only admin and roleless actor get HTTP 403 even when passing a valid Retail store ID.
- The GET requests do not change counts in orders, payments, invoices or driver assignments.

Scope is the canonical existing Admin HTML GET. It does not claim all Finance CRUD, exports, approvals, account-role production audit, or complete P031 route matrix are proven. This test intentionally does not mutate finance records and does not introduce fixture access to real production accounts.

## Ownership and security review

**REVIEW_RISK: HIGH**, because of tenant/channel authorization assertions. Exactly two exclusive new files are owned by #4244. Do not modify B2cWorkspaceController, existing P031 tests or peer-owned #4240/#4242 sources unless a verified real source defect requires a fresh ownership preflight. Denied requests must remain denied; do not weaken assertions to turn CI green. Functional acceptance needs actual Laravel/MySQL tests and file-by-file review.

**MOBILE IMPACT:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No Flutter, mobile API, dispatch, shipment, pricing, order or notification source is touched; B2B VAN ONLY and B2C DRIVER ONLY remain unchanged.

## Merge / closure restrictions

Original Issue #4244; one issue-scoped branch `test/4244-v2-retail-finance-workspace-isolation` and one PR targeting `Foodex_V2` only. Exact-final-head Repository Policy, FOODEX V2 Isolation and FOODEX Required CI including Laravel/MySQL and terminal `required-ci-gate` must all succeed, followed by security review and guarded merge. Only then close #4244 as an atomic test leaf. Parent #2782 / master #2503 remain open; staging/manual final system acceptance is not implied by CI. No main, release, deployment, tag, production, signed ZIP or APK changes.
