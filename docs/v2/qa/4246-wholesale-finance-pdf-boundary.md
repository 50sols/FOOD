# #4246 — P031 Wholesale Finance PDF HTTP export boundary

## Authoritative path
The existing `/admin/b2b/finance?export=pdf` is routed through `B2bWorkspaceController::show` to `financeExportResponse`. The controller builds its store set from the trusted `WholesalePrincipal`, checks `finance.view` and the authenticated Wholesale tenant context before PDF generation, and records `b2b.finance.exported` only on success. Existing `B2bFinanceFilterExportTest` covers format and filters for an authorized principal; merged #4242 covers XLSX cross-role denial. This leaf tests PDF-specific download denial and audit semantics.

## Acceptance and threat model
The additive Laravel test `V2WholesaleFinancePdfExportBoundaryTest` uses reference-seeded synthetic users and a Retail store. In Arabic and English, it asserts B2B_ADMIN can get actual PDF bytes with an attachment and no-store caching, despite a forged Retail `store_id` and `channel=b2c` query parameter. The same URL is 403 for a Retail store admin and a roleless authenticated principal. Each authorized PDF increments the existing export audit exactly once; denied requests do not produce an export-success event. Orders, invoices, payments and driver-assignment counts stay unchanged.

**Security review risk: HIGH.** Only test/QA are changed; no renderer, controller, route, RBAC, schema, tenant/finance service, live account, public API or Flutter source. These assertions do **not** certify all P031 routes or all financial entitlements. Existing SoD, approvals, store isolation and PDF parser concerns remain governed by their original issues. The test deliberately does not infer the absence of every possible financial leak from PDF header presence alone.

## Impact and merge gate
Customer: NO_DIRECT_IMPACT. Driver: NO_DIRECT_IMPACT. Van: NO_DIRECT_IMPACT. This is a protected Admin GET export test only; B2B VAN ONLY / B2C DRIVER ONLY dispatch policy unchanged.

Issue #4246 is an atomic leaf under #2782 and #2503; one original PR to `Foodex_V2` only. Merge/closure require final-head Repository Policy, V2 Isolation, full Laravel/MySQL Required CI plus terminal `required-ci-gate`, security source review and verified squash merge. Parent issues remain open. No `main`, release, tag, APK or production deployment.
