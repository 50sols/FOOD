# P031 — Wholesale Products canonical HTTP isolation (#4254)

- **Ownership:** leaf #4254; parent security scope #2782; V2 master #2503. Dedicated test and QA paths, disjoint from #4250 Dashboard, #4252 Clients and previously covered Reports/Orders/Finance.
- **Route:** authenticated `GET /admin/b2b/products`, route `admin.b2b.module` with `module=products`; canonical permission `catalog.view`. Existing `B2bWorkspaceController::show` obtains store scope from server-side `WholesalePrincipal` and `wholesaleStoreIds`.
- **Allow:** seeded global B2B_ADMIN receives HTTP 200 and the canonical Wholesale `storeIds` in Arabic RTL and English LTR.
- **Forged selectors:** `channel=b2c&store_id=<existing Retail store>` cannot replace or enlarge authorized Wholesale `storeIds`.
- **Deny:** B2C_STORE_ADMIN with real scoped Retail `user_store_roles` assignment and authenticated roleless user receive HTTP 403, even with forged `channel=b2b`.
- **Read-only:** assert orders, payments, invoices and driver_assignments counts unchanged across the request matrix.
- **MOBILE IMPACT:** Customer — NO DIRECT IMPACT; Driver — NO DIRECT IMPACT; Van — NO DIRECT IMPACT. Only a Laravel Admin HTTP regression test; no mobile API, dispatch, offline, auth or notification changes. B2B VAN ONLY/B2C DRIVER ONLY preserved.
- **No runtime changes:** controllers, routes, role mappings, migrations, existing frontend, Flutter sources, release, tag, main and production are unchanged.
- **Review risk:** HIGH (tenant/authorization regression), even though test-only. A same-account review records engineering analysis, not independent GitHub APPROVED.
- **Acceptance:** source review of test and QA document, exact-final-head Repo Policy + V2 Isolation + Required CI (full Laravel and MySQL/Redis acceptance) SUCCESS, normal GitHub protection and SHA-guarded merge to `Foodex_V2` only, verify both files at integration SHA, then close only #4254. Parents #2782/#2503 remain OPEN.
