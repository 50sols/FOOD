# P031 — Wholesale Clients canonical HTTP isolation (#4252)

- **Ownership:** issue #4252; parent #2782; master #2503. New dedicated source and QA paths, no overlap with active #4250 dashboard branch or previously completed Reports, Orders, Finance HTTP leaves.
- **Contract:** `GET /admin/b2b/clients`, route `admin.b2b.module` with `module=clients`, must authorize `b2b.accounts.view` and derive its store scope exclusively from `WholesalePrincipal`, not caller-provided query data.
- **Positive:** seeded B2B_ADMIN receives HTTP 200 in Arabic RTL and English LTR; view `storeIds` exactly contains the canonical Wholesale principal store.
- **Spoofed context:** B2B actor requests `channel=b2c&store_id=<actual Retail ID>`; view scope remains Wholesale, excludes Retail.
- **Negative:** B2C_STORE_ADMIN assigned through `user_store_roles` to the Retail store and roleless authenticated user both receive 403.
- **No writes:** count orders, payments, invoices and driver_assignments before and after the entire HTTP matrix.
- **MOBILE IMPACT — Customer / Driver / Van:** no direct impact on any of the three apps, their APIs, notifications, auth, routing or offline behavior; Laravel Admin Clients GET only. Keep B2B VAN ONLY and B2C DRIVER ONLY; no mobile issue needed for this test-only leaf.
- **No changes:** migrations, production routes/controllers, role configuration, services, UI, frontend, apps, main, releases or production.
- **Acceptance:** exact-head Repository Policy, V2 Isolation, Required CI (full Laravel and MySQL/Redis installation, upgrade and recovery) success; security review and SHA-guarded merge into `Foodex_V2` then verify the two files before closing #4252. Parents #2782 and #2503 remain open.
