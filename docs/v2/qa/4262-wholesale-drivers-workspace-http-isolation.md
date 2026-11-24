# P031 — Wholesale Drivers workspace HTTP isolation (#4262)

- **Ownership:** #4262 under #2782/#2503. Only two additive paths. This is the server-rendered Wholesale Admin drivers workspace, NOT Driver Flutter app behavior.
- **Canonical route/authority:** authenticated `GET /admin/b2b/drivers` through `admin.b2b.module` with `module=drivers`. Existing module permission `drivers.b2b.view`, `WholesalePrincipal::storeId()` and trusted `wholesaleStoreIds()` remain unchanged.
- **Allow:** global seeded B2B_ADMIN returns HTTP 200 in Arabic RTL and English LTR with authoritative Wholesale `storeIds`.
- **Forged Retail selectors:** passing real Retail `store_id` and `channel=b2c` cannot change view `storeIds` or underlying canonical scope.
- **Deny:** Retail-scoped B2C_STORE_ADMIN and authenticated roleless actor receive HTTP 403 even when claiming `channel=b2b`.
- **GET nonmutation:** order, payment, invoice and driver-assignment counts unchanged.
- **Risk classification:** HIGH (authorization/tenant security), two-file technical review + exact-head Repository Policy, V2 Isolation and Required CI with complete Laravel and MySQL/Redis acceptance mandatory. Same-account COMMENT review is not independent GitHub APPROVED.
- **App impact:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No Flutter, mobile API, dispatch, assignment mutation, notification, route/controller or runtime code changes. B2B VAN ONLY / B2C DRIVER ONLY invariant preserved.
- **Completion:** merge only reviewed issue-scoped PR into `Foodex_V2` with exact-head green and SHA guard, verify both paths on integration branch, then close only #4262. #2782 and #2503 remain OPEN. No main, tags, releases or production.
