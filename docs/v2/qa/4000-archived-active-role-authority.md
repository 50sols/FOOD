# V2 #4000 — archived-but-active role effective authorization guard

**Origin:** P013 #2758 (original PR #3203 closed/unmerged, not reproduced), P006 #2751, P082 #2818, G007 #2748, master #2503. **Base:** `Foodex_V2` exclusively. **FEATURE FREEZE:** correction of existing canonical authorization, no role/route/Sidebar/module or new tab.

## Source / cause and boundary

The live `User` model historically relied on `roles.is_active=true` in several effective-role and permission queries. The `roles.archived_at` marker is an independent, terminal historical lifecycle state. Normally archive operations turn off both fields, but imported or legacy rows may be `archived_at != NULL && is_active=true` (fixture intentionally simulates this, without claiming any production occurrence). Current Sidebar layout and role preview correctly reject archived role visibility; that cosmetic decision alone is not HTTP security.

The scoped fix excludes **archived global and store roles** from:
- `User::hasRole` including SUPER_ADMIN, B2B global-channel and B2C store-role lookup;
- `User::isPlatformOwner` role portion, without modifying the independently protected owner registry;
- `User::hasInheritedPermission`, `inheritedPermissionCodes`, `effectivePermissionCodes` projection through inherited role history;
- `User::permissionFitsActiveGlobalRoleDomain`, `hasPermissionScopeContext`, `hasGlobalRoleContextForChannel` so a legacy per-user ALLOW cannot borrow an archived role as its active domain/scope context.

Stored `role_user`, `user_store_roles`, `permission_role` and `user_permission_overrides` histories remain untouched. Explicit GLOBAL and STORE DENY retain precedence, sensitive approvals still require role inheritance, active separate role remains effective. No permission/code/tenant/SoD grant. No additional ID/JSON text is shown to a business user.

## Exact file fence and dependency

1. `backend/app/Models/User.php` — nine archived-null role query guards, three archived-null store role query guards, one loaded-role projection guard.
2. `backend/tests/Feature/V2ArchivedActiveRoleEffectivePermissionTest.php` — seeded global Super Admin/direct Security + Sidebar role matrix GET deny, B2C Store A/Store B permission isolation and archived explicit ALLOW rejection, mixed active/archived role + GLOBAL DENY direct catalog and audit/security tests, inactive actor and AR/EN.
3. This QA evidence file.

**Owned externally / unchanged:** `backend/app/Support/AdminNavigation.php` remains modified by **P135 #2858 / PR #3065**, so the additional `AdminNavigation::canAccessPlatformErp()`, `canAccessMyWork()`, `usesCustomerServiceDashboardProfile()` queries that check `is_active` without `archived_at` are **not repaired here**. P135's independent direct ERP Procurement 403 regression has recorded a real 200 failure for contradictory archived+active global role on its own stale/unfinished PR HEAD. It MUST be repaired/coordinated by the original owner, with a combined integration CI verification before marking P013/P006/G007 closed. This scoped fix cannot certify those higher-level route gates or an ERP module.

## Acceptance / impact classification

- Scoped direct HTTP: security.view and roles.manage existing Security Center/Siderbar role matrix GET 403 once all effective granting roles are archived; no tab `section` value bypasses RBAC. Existing five tabs remain, with authorized user-facing labels and no new route/Sidebar. Catalog direct 403 on explicit DENY, read-only audit/assignment counts preserved.
- Negative tests also assert Store A/B channel permission boundaries, active unrelated role remains valid, inactive identity zero permissions, global ALLOW cannot revive role-only security and historical role/overrides remain persisted.
- **Customer:** shared User auth model is backend-used and may affect revoked/archived roles, not Flutter UI/API schema. Existing #2730 tracks account disable/archive mobile session behavior; verify API authorization parity on combined source, no NO_IMPACT claim.
- **Driver:** same shared User role API potential security tightening; route B2C Driver restrictions and token abilities require the existing #2735/#2740 parity gates. No Flutter files edited.
- **Van:** same shared User role API potential security tightening; B2B Van-only route/role checks require #2738/#2740 parity. No Flutter files edited. **B2B VAN ONLY / B2C DRIVER ONLY unchanged.**

## Review and CI obligations

**REVIEW_RISK: HIGH** — global identity and permission authority. **TECHNICAL_REVIEW: PENDING** until exact final SHA, isolated authenticated Laravel suite, real mysql/redis install-upgrade-recovery, PHP syntax/Pint/static-analysis, AR/EN, security and all Repository Policy + V2 Isolation + Required CI gates report SUCCESS. Need separate file-by-file threat review: role history, stale-session, cached owner memo, cross-store override, Super Admin control plane, dual role and direct route. Role memo within the same PHP request and external P135 navigation query remain independent integration review subjects, not falsely certified here.

**SOURCE_COMPLETE #4000** must be limited to the three issue-owned paths and applicable automated evidence; parent #2758/#2751/#2748/#2503 remain OPEN. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` only after verified source/CI. No main, tag, release, production or deployment.
