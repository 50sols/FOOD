# P031 — Retail Content Admin HTTP cross-store authorization (#4289)

- **Owner:** bounded security leaf #4289 under P031 #2782 and master #2503, targeting `Foodex_V2` ONLY.
- **Canonical route:** GET `/admin/b2c/content` via `admin.b2c.module` module `content` with `promotions.view`, using existing `B2cWorkspaceController` server-authoritative B2C selected-store context.
- **Positive AR/EN:** seeded B2C_STORE_ADMIN assigned Retail Store A receives HTTP 200 with Arabic RTL and English LTR; forged `channel=b2b` with authorized Retail Store A does not replace trusted channel/store authority.
- **Negative selectors:** foreign Retail Store B or Wholesale B2B store IDs return 404, contradictory `store_id/store` aliases return 404 and malformed or noncanonical `store_id` values `0`, `-1`, `01`, `not-an-id` return 404; Wholesale-only B2B_ADMIN or authenticated roleless gets 403.
- **Nonmutation:** read-only GET matrix leaves orders, payments, invoices and driver_assignments row counts unchanged.
- **No duplicate:** #2566 / PR #3094 previously proved Retail Content Sidebar HTTP navigation; it did not cover this P031 contradictory/malformed two-store selector matrix. Wholesale and other Retail modules have independent regressions. Exactly two new test/QA files, no controller, navigation, runtime or API modifications.
- **Mobile impact:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT; no Flutter, dispatch or fulfillment changes. B2B=VAN ONLY, B2C=DRIVER ONLY unchanged.
- **Review and acceptance:** HIGH RBAC/tenant risk; file-level technical COMMENT does not mean independent GitHub APPROVED. Require final exact-head Repository Policy, V2 Isolation, full Required CI with four Laravel shards, MySQL/Redis real install/upgrade/recovery and performance, protected SHA-guarded merge of original PR to Foodex_V2, verification of both merged file blobs then close only leaf #4289. Parents #2782/#2503 remain OPEN, no main, production, release or APK.
