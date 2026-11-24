# P031 — Wholesale Settings workspace HTTP role/channel isolation (#4260)

**Owned leaf:** #4260; parent #2782, master #2503. New, uniquely scoped test and QA file only. Existing `B2bWorkspaceController::show` + `admin.b2b.module` route remain authoritative.

- **Canonical route:** authenticated `GET /admin/b2b/settings` with module `settings`, requiring `settings.view`. The canonical Wholesale `storeIds` is derived from server-side `WholesalePrincipal::storeId()`.
- **Allow:** seeded global B2B_ADMIN sees the Settings page (HTTP 200), Arabic RTL and English LTR, with exactly one authoritative Wholesale store ID.
- **Forged selectors:** passing a real Retail `store_id` and `channel=b2c` does not replace the canonical Wholesale scope.
- **Deny:** Retail-only B2C_STORE_ADMIN with real `user_store_roles` Retail store assignment and roleless authenticated user get 403; claiming `channel=b2b` cannot elevate Retail.
- **Read-only:** page GET in each locale does not change orders, payments, invoices or driver_assignments.
- **High security risk:** review of both test/docs paths and complete exact-head CI (Repository Policy, V2 Isolation, Required CI, Laravel suite, MySQL/Redis deployment) before merge. Same-account technical COMMENT is not independent APPROVED.
- **Mobile contract:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT. No backend runtime, API, schema, Flutter, dispatch or notification changes; B2B VAN ONLY/B2C DRIVER ONLY preserved.
- **Integration:** original issue-scoped branch and PR to `Foodex_V2` only, protected SHA-guarded merge after required checks and source review, verify both integrated files then close only #4260. #2782/#2503 remain OPEN. No main, deployment, production, tags or release.
