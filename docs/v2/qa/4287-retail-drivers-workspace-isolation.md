# P031 — Retail Drivers **Admin** HTTP selector and tenant isolation (#4287)

- **Scope:** bounded Laravel Dashboard ERP Admin-only read route, parent #2782, master #2503, branch Foodex_V2 only.
- **Route:** GET `/admin/b2c/drivers` through `admin.b2c.module` with module=drivers, protected by drivers.b2c.view, B2cWorkspaceController store scoping.
- **Positive:** Store A assigned B2C_STORE_ADMIN receives 200 in Arabic RTL and English LTR; forged `channel=b2b` cannot change trusted Retail authority.
- **Negative:** foreign Retail Store B and B2B Wholesale Store selectors 404; malformed `store_id` and conflicting `store_id`/`store` alias requests 404; Wholesale-only B2B_ADMIN and roleless authenticated actor 403.
- **GET no writes:** orders, payments, invoices and driver_assignments table row counts remain unchanged.
- **Distinct from #2592:** earlier Drivers Sidebar HTTP authority verifies known foreign store denial and menu discoverability; this test specifically covers conflicting aliases and malformed selectors with a full AR/EN matrix. Distinct from Wholesale Drivers #4262.
- **Mobile:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT. No Driver Flutter, customer/van Flutter, API, dispatch, routing or assignment lifecycle changes; B2B VAN ONLY and B2C DRIVER ONLY preserved.
- **Security risk:** HIGH; same-account COMMENT does not constitute independent APPROVED.
- **Acceptance:** exact-final-head Repo Policy, V2 Isolation and full Required CI (Laravel four shards, MySQL Redis install/upgrade/recovery, required-ci-gate) terminal SUCCESS; review/branch protection; SHA-guarded squash merge Foodex_V2, verify both new files at merge SHA and close only #4287. Parent #2782 and master #2503 remain open. No main/releases/production.
