# P031 — Wholesale Pricing Admin HTTP scope and tier readiness (#4291)

- **Issue:** bounded #4291, parent security #2782, V2 master #2503. Target `Foodex_V2` only.
- **Canonical entry:** `GET /admin/b2b/pricing` via `admin.b2b.module` module `pricing`, `B2bWorkspaceController::show`, requiring global `b2b.pricing.view` plus authorized `WholesalePrincipal::storeId()`.
- **Feature flag safety:** when `b2b.pricing_tiers_enabled` is false on authoritative Wholesale store, return 404 even when it is true for a Retail store. When enabled on the Wholesale store, B2B_ADMIN may view the page.
- **Allow, AR/EN:** B2B_ADMIN sees 200 with `storeIds` equal solely to the Wholesale principal in Arabic RTL and English LTR; forged `channel=b2c&store_id=<retail>` cannot change server-selected Wholesale authority.
- **Deny:** Retail-only store-scoped B2C_STORE_ADMIN attempting to request B2B Pricing returns 403; authenticated roleless account returns 403.
- **Read-only:** requests cannot change `orders`, `payments`, `invoices`, or `driver_assignments` row counts.
- **Distinct:** supplements P082 #2818 API View vs Manage, established B2B pricing rules tests and #2706 pricing mode work; no pricing policy or runtime implementation changed.
- **Mobile impact:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT; no Flutter, dispatch, API or checkout changes. B2B VAN ONLY; B2C DRIVER ONLY.
- **Risk:** HIGH tenant/feature-gate. Same-account technical COMMENT is not independent APPROVED review. Preserve branch rules and human gates.
- **Acceptance:** terminal exact final HEAD Repository Policy, V2 Isolation and full Required CI including 4 Laravel shards, MySQL/Redis install/upgrade/recovery, required-ci-gate; source review and normal branch protections, SHA-guarded merge to `Foodex_V2`, verify both files on merge SHA then close only #4291; #2782/#2503 stay OPEN. No main/releases/prod.
