# #2697 AP Officer — bounded least-privilege role regression

Target: `Foodex_V2` only. This is a narrow source-invariant increment, **not acceptance of the whole #2697 ERP role workflow**.

## Source / behavior

The canonical `backend/config/job_roles.php` defines `AP_OFFICER` as a tenant-scoped finance operator requiring the finance module, with `finance.view`, `finance.manage` and `reports.view`. It does **not** explicitly grant `finance.post`, `finance.approve`, procurement posting, security administration or dispatch rights.

`backend/tests/Feature/V2AccountsPayableOfficerPermissionTest.php` pins both the catalog configuration and the effective permissions of a real seeded role attached to a synthetic active user. This prevents accidental direct privilege expansion without changing any existing runtime grant. The role's broad `finance.manage` permission is intentionally **not** narrowed here: that requires an action-level AP/AR HTTP and finance-service SoD assessment to prevent breaking existing operations.

## Outstanding full acceptance (KEEP ISSUE OPEN)

- Authenticated AP request / invoice / allocation HTTP allow-and-deny workflows for distinct legal entities, Wholesale and Retail stores; action-level enforcement beyond coarse `finance.manage`.
- Module entitlement/readiness, maker/checker, delegation limits, negative audit and rollback verification.
- UI/sidebar AR/EN, RTL/LTR, accessibility and real business processing.
- Exact-head backend CI (Laravel, security, localization, MySQL/Redis fresh-install/upgrade/recovery), file review and integration SHA; merge only if accepted.
- Customer/Driver/Van: **NO DIRECT IMPACT** from this test-only change. No Flutter/API/dispatch, customer sessions or production changes; B2B=VAN ONLY, B2C=DRIVER ONLY remain intact.

No actual-account, production, release or deploy evidence is asserted.
