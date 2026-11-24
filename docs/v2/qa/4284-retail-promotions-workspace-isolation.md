# P031 — Retail Promotions workspace HTTP tenant isolation (#4284)

- **Ownership:** bounded security leaf #4284, parent #2782, master #2503; original integration Foodex_V2 only.
- **Canonical GET:** `/admin/b2c/promotions` via `admin.b2c.module` module=promotions and `promotions.view`; selected store is server-authorized by B2cWorkspaceController.
- **Allow:** Retail B2C_STORE_ADMIN assigned Store A gets HTTP 200 AR/RTL and EN/LTR; forged `channel=b2b` with valid Store A does not change Retail authorization.
- **Deny:** another Retail Store B or Wholesale store selector fails HTTP 404; `store_id`/`store` contradictory aliases and malformed IDs fail HTTP 404; B2B_ADMIN-only and roleless authenticated actors fail HTTP 403.
- **No-write:** GET leaves orders, payments, invoices and driver_assignments counts unchanged.
- **Distinct source:** extends P031 negative-selector HTTP evidence without duplicating #2562/#3093 Sidebar/Flash Offers channel discovery; existing Retail Promotions controller/route/permission are unchanged.
- **Mobile:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No Flutter/API/dispatch changes, B2B Van-only/B2C Driver-only preserved.
- **Risk:** HIGH tenant/RBAC; same-account technical COMMENT is not independent GitHub APPROVED.
- **Acceptance:** exact final HEAD Policy, V2 Isolation, Required CI including Laravel 4 shards and MySQL Redis install/upgrade/recovery + required-ci-gate terminal SUCCESS; technical review and required protections; SHA-guarded merge into Foodex_V2; verify both new file blobs at merge SHA, close only #4284. Parents #2782/#2503 remain OPEN. No main, production, releases.
