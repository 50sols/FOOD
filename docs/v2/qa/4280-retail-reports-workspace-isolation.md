# P031 — Retail Reports workspace HTTP two-store isolation (#4280)

- **Ownership:** leaf #4280 under #2782 and master #2503; target `Foodex_V2` only. Original branch `test/4280-v2-retail-reports-workspace-http-isolation`.
- **Canonical endpoint:** `GET /admin/b2c/reports` via `admin.b2c.module` module `reports`, permission `reports.view` and existing `B2cWorkspaceController` trusted Retail store selection.
- **Allowed:** seeded `B2C_STORE_ADMIN` assigned Store A sees HTTP 200 in Arabic RTL and English LTR. Forged `channel=b2b` cannot change trusted scope.
- **Denied:** Retail Store B and Wholesale Store selected IDs give HTTP 404; contradictory `store_id/store` aliases and invalid IDs reject with 404; Wholesale-only `B2B_ADMIN` and authenticated roleless user get HTTP 403.
- **Read-only GET:** all orders, payments, invoices and driver_assignments row counts remain unchanged by tested HTTP requests.
- **Distinct:** focused Retail Reports admin route, not supervisory reporting #2534, Reports sidebar #2634, Wholesale Reports #4248 or Retail Products #4276. Two additive test/QA files only; no runtime API or controller changes.
- **Mobile assessment:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT; B2B VAN ONLY and B2C DRIVER ONLY unchanged.
- **HIGH RBAC/tenant risk:** exact-final-head Repo Policy, V2 Isolation and full Required CI four Laravel shards and MySQL/Redis installation/upgrade/recovery must pass; source review recorded as same-account COMMENT (not independent APPROVED). Respect review protection, SHA-guarded squash merge into Foodex_V2, verify integrated files and only close #4280. Parents #2782 and #2503 remain OPEN, no main, tag, release, APK or production.
