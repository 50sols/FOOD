# #4242 — Wholesale finance XLSX export channel boundary (P031)

Parent #2782 / master #2503. Target **Foodex_V2** only. This is a bounded regression for the existing `GET /admin/b2b/finance?export=xlsx` mapped to `B2bWorkspaceController::show` and `financeExportResponse`, not an implementation of a new export.

## Authenticated acceptance

`backend/tests/Feature/V2WholesaleFinanceExportBoundaryTest.php` uses canonical CoreReferenceSeeder roles and synthetic Store A actors. It runs real Laravel HTTP requests in Arabic and English, verifies Wholesale B2B_ADMIN can download an attachment with no-store cache directives and that each successful export creates the existing audit event, while Retail Store Admin and roleless principal get HTTP 403 without an export audit. It preserves order/payment/invoice table counts; no production financial data is used. The route's own export serializer and SQL tenant filters remain authoritative.

## Security and ownership

Risk: HIGH (financial export and channel boundaries). Source is additive test + QA only; no live controller, routes, permission maps, migrations, service, UI, API or Flutter changes. The current active #4240 owns canonical Wholesale **Orders** GET tests and separate files, not this export. If exact-head CI reveals a genuine source defect, verify original ownership before changing runtime; never weaken deny expectations or assume a green unit test is full parent P031 proof.

Customer: NO_DIRECT_IMPACT. Driver: NO_DIRECT_IMPACT. Van: NO_DIRECT_IMPACT. This is an authenticated Admin HTML export regression only; no mobile source, mobile API, dispatch or routing edits. B2B VAN ONLY and B2C DRIVER ONLY unchanged.

## Gate

One PR into Foodex_V2 only, exact-head Repository Policy, V2 Isolation, Required CI including full Laravel/MySQL and terminal required-ci-gate SUCCESS; technical review and SHA-guarded merge before leaf closure. Parent #2782 and master #2503 remain open. No main, releases, tags, production or deployments.
