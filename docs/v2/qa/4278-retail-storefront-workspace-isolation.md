# P031 — Retail Storefront Admin HTTP tenant isolation (#4278)

- **Owner**: #4278; parent P031 #2782; V2 master #2503. Original target `Foodex_V2` only.
- **Canonical route**: GET `/admin/b2c/storefront` via `admin.b2c.module` (`module=storefront`) governed by `stores.view` and B2cWorkspaceController server-side store authority.
- **Positive**: Store A-scoped `B2C_STORE_ADMIN` receives HTTP 200 in AR/RTL and EN/LTR; forged `channel=b2b` cannot override current selected Retail Store A.
- **Negative**: attempts to select Retail Store B or a Wholesale B2B store ID return 404. Contradictory `store_id` and `store` aliases or malformed store selector IDs return 404; Wholesale-only `B2B_ADMIN` and roleless authenticated actors return 403.
- **No mutation**: read-only GET leaves orders/payments/invoices/driver_assignments row counts unchanged.
- **Distinct source**: complements general Retail Store Admin persona #2716; differs from Wholesale Storefront #4264 and Retail Inventory/Products/Customers. No existing controller, routes, permissions or runtime code changed.
- **Mobile impact**: Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT; no Flutter, API or fulfillment changes, B2B Van-only/B2C Driver-only rule unchanged.
- **Security review**: HIGH tenant/RBAC risk; same-account GitHub COMMENT is not an independent APPROVED review.
- **Acceptance**: exact-final-head Policy, V2 Isolation, full Required CI including all Laravel shards and MySQL/Redis install/upgrade/recovery terminal SUCCESS. Review, protection, SHA-guarded merge into `Foodex_V2`, verify both new file blobs on merge commit, close only #4278. Keep #2782/#2503 OPEN; no main, release, prod or APK.
