# P031 — Wholesale Inventory HTTP isolation (#4258)

- **Ownership:** #4258, parent #2782, master #2503. Dedicated additive Laravel feature test and this QA file; distinct from Dashboard #4250, Clients #4252, Products #4254, Reports/Orders/Finance leaves.
- **Canonical route:** `GET /admin/b2b/inventory` via `admin.b2b.module` with `module=inventory`. Existing module permission is `inventory.view`; store scope comes from `WholesalePrincipal::storeId()` through server-side `wholesaleStoreIds`.
- **Positive:** seeded B2B_ADMIN has HTTP 200 in Arabic RTL and English LTR, with view `storeIds` equal to the canonical Wholesale store.
- **Forgery:** a real Retail store ID in `channel=b2c&store_id=...` cannot change the Wholesale principal.
- **Negative:** real Retail-scoped B2C_STORE_ADMIN and authenticated roleless user get HTTP 403; Retail actor cannot upgrade itself by passing `channel=b2b`.
- **GET side effects:** counts of orders, payments, invoices and driver_assignments stay unchanged across the matrix.
- **Mobile impact:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT; no Flutter/API/fulfillment changes. Preserve B2B VAN ONLY / B2C DRIVER ONLY.
- **Risk:** HIGH because tenant authorization. Technical review must examine both new files, actual HTTP behavior, negative cases and full exact-head CI; same-account COMMENT is not an independent APPROVED review.
- **Integration:** original PR to `Foodex_V2` only, Repo Policy/V2 Isolation/Required CI (full Laravel and MySQL/Redis install/upgrade/recovery) SUCCESS, normal protection + SHA-guarded merge; verify both files on integrated branch, close only #4258. Parents #2782/#2503 remain open. No `main`, release, production, APK.
