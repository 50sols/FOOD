# P031 — Retail Settings Admin HTTP two-store authorization (#4282)

- **Ownership:** leaf #4282, security P031 parent #2782, V2 master #2503; target Foodex_V2 ONLY.
- **Route:** GET `/admin/b2c/settings` via `admin.b2c.module` module=settings, `settings.view`, B2cWorkspaceController canonical Retail selected store authority.
- **Positive:** B2C_STORE_ADMIN assigned Retail Store A receives HTTP 200 AR/RTL and EN/LTR; forged channel=b2b cannot elevate its store scope.
- **Negative:** foreign Retail Store B and Wholesale B2B store IDs return HTTP 404; conflicting `store_id`/`store` aliases or malformed selectors return 404; B2B-only and roleless authenticated actors return 403.
- **GET nonmutation:** orders, payments, invoices, driver_assignments table counts are equal before and after all requests.
- **Separation:** Retail Settings module is distinct from Wholesale Settings #4260, Retail Reports #4280, Retail Customers #4270 and Retail Storefront #4278; no runtime/controller, schema, permissions or API changes.
- **Mobile impact:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT. No Flutter or app-specific workflows; preserve B2B VAN ONLY and B2C DRIVER ONLY.
- **Review risk:** HIGH tenant RBAC security; same-account technical COMMENT cannot substitute for independent APPROVED when GitHub requires one.
- **Acceptance:** final exact PR HEAD Repository Policy, V2 Isolation, full Required CI Laravel test shards and MySQL/Redis install/upgrade/recovery plus terminal required-ci-gate SUCCESS; source review and normal protections, protected SHA-guarded merge to Foodex_V2 and verify both integrated blobs, close only #4282. Keep #2782/#2503 OPEN. No main/production/release/APK.
