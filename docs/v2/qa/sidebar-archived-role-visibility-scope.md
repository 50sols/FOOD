# V2 Sidebar P006 — archived historical role visibility

- Atomic issue: [#3916](https://github.com/walidatiyaai2025-gif/FOOD/issues/3916)
- Parents: #2751 (Sidebar as effective authorization projection), #2503 (V2 master)
- Target: `Foodex_V2` only; no `main` or production operation
- Risk: **HIGH** because the Sidebar must not invent or suppress canonical authorized routes using ineligible historical roles

## Confirmed source and threat

`AdminSidebarManager::roleCanUseItem` already treats archived roles as ineligible. `roleIdsForUser` previously selected global and store role IDs using only `roles.is_active=true`. Inconsistent historical data with `archived_at IS NOT NULL` and `is_active=true` could therefore still apply an archived role's saved `admin_sidebar_role_visibility.is_visible=false` row to a user whose separately valid current role grants the page. The resulting menu suppression is not evidence of a lost server permission, but creates a broken discoverability path. The inverse risk (historical `visible=true` granting a route) is already blocked by effective `AdminNavigation` authorization and remains blocked.

## Minimal scope / regression

- Add `roles.archived_at IS NULL` to both the global and Retail/store role-ID queries for **presentation visibility preference selection only**, retaining the existing `roles.is_active` condition.
- Preserve historical `role_user`, `user_store_roles`, roles, grants and Sidebar preference rows. Do not rewrite or delete archived data.
- Simulate a recorded historical archived global role with an inconsistent active flag and a saved hidden catalog preference. A separately authorized B2B Admin's existing catalog link must remain discoverable in both AR/RTL and EN/LTR.
- Explicit active-role hide preference still hides the same link. A no-role actor's direct Catalog HTTP request remains forbidden.
- No new route, navigation destination, data contract, module enablement, permission grant, service mutation, DB migration, or customer-specific behavior.
- Current protected/permission-allowed runtime projection is reused; saved visibility remains a **hide-only preference**, never an authorization grant.

## Placement / UI and app impact

- Domain: Administration → Sidebar Role Access, canonical `AdminSidebarManager::apply`. User goal: show the authorized business destination without stale historical-role display suppression.
- Placement: **Reuse**, existing Sidebar and role visibility editor. Navigation type: existing SIDEBAR. Desktop/tablet/mobile: no layout or interaction markup changed; the same shared responsive/off-canvas renderer and 44px controls remain authoritative.
- Arabic/English, RTL/LTR: no new strings; regression exercises both locales and existing translations.
- Backend: only read-side Sidebar role selection; direct server authorization unchanged.
- Dashboard: projected visibility for authorized users can be corrected; no custom UI component or new menu entry.
- Customer: **not affected**, no Flutter/DTO/auth/API or storefront change.
- Driver: **not affected**, no Driver assignment/dispatch or mobile/API change.
- Van: **not affected**, no Van stock/visits/order API or mobile change. B2B VAN ONLY / B2C DRIVER ONLY invariants are unchanged.

## Security / failure / rollback

- Error paths: inactive roles remain excluded; legacy archived roles with stale active flags become ineligible for saved visibility effects; empty authorized projection remains empty.
- No schema/data changes and no reversible migration required. Rollback is restoration of the two query filters if the regression reveals a contract violation.
- No source files for login, finance, collections, tenancy, or releases are modified.
- Required: exact-head Repository Policy, V2 Isolation, backend PHPUnit, Pint/PHPStan/security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery and terminal Required CI. High-risk separate file-by-file engineering audit must be recorded before merge. Same-account audit is **not** independent GitHub approval.
- Parent #2751 and master #2503 remain open for broader Sidebar and staging/manual UAT. Closing #3916 certifies only this precise regression after verified green merge, not 177-node acceptance.
