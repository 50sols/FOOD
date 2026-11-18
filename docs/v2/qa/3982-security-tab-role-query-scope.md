# V2 #3982 — Security Center existing tabs and Role Matrix selector fail-closed

Parent authority: #2750 (G009), #2751 (P006), #2818 (P082), master #2503. Target **Foodex_V2 only**. FEATURE FREEZE. Bounded source defect: presentation input shapes **must not** alter role grants, HTTP authorization, auditing, or user store scope.

## Source-to-layout decisions

| Existing area / current source | Placement decision / why |
| --- | --- |
| User management, create/edit/archive and store role assignment, `admin/security.blade.php` / `SecurityController` | **KEEP_EXISTING_TABS**: Users, Roles, Governance, Sessions and Audit are already canonical server-selected sibling tabs, not steps to reimplement. Existing role-assignment modal and create form stay in the Users tab, their POST permissions unchanged. Existing one-line tab strip now scrolls horizontally on mobile, min 44px, focus-visible; no new tabs / sidebar. |
| Permissions matrix and role tree, `admin/sidebar-role-access.blade.php` / `SidebarManagementController::roleAccess` | **NO_NEW_TABS** for interdependent role selection + one matrix editor; the explicit `role_id` must point to the exact role, never silently fall back to another role. Existing `Gate::authorize('roles.manage')`, server-derived `AdminSidebarManager::roleMatrix`, readiness projection and authorization remain as-is; `canEditRolePermissions` now additionally requires `is_active` to keep inactive custom roles read-only. |
| Sidebar layout, `admin/sidebar-layout.blade.php` | **KEEP_EXISTING_ROUTE**. Display/visibility is a user preference, not a permission grant; no new Sidebar, no mutation of `admin_sidebar_role_visibility` or layout. |
| Enterprise Security scopes, financial approval limits, delegations, Maker/Checker change queue, `admin/security-enterprise.blade.php` | **KEEP_EXISTING_TABS / EXISTING_CENTER**, as the existing G009 classification specifies, with no arbitrary hiding of approval queue or rearrangement of maker/checker workflow; no new tab or edit to `EnterpriseSecurityController`. |
| Organization/company/legal entity/business unit, `admin/enterprise-organization.blade.php` | **NO_TABS_SINGLE_FLOW**; hierarchical record drilldown is dependent, existing routes and business ownership unchanged. |
| Audit/security logs, security Governance and Audit `section` | **EXISTING_NATIVE_PAGE_TAB**. Direct HTTP still requires `security.view`; GET must not write audit records. |
| Multi-role matrix, Store/global role permissions, UserRole override | **SOURCE-AUTHORITATIVE RBAC**, not a UI-access calculation. Tenant/role/HTTP/explicit DENY remain in existing services and Middleware. |

## Source defect and fix boundary

- `SecurityController::index` previously cast untrusted `section`, `q`, `status`, `assignment` to `string` before shape validation. Malformed array GETs could raise a PHP warning/HTTP 500. The `deletion_preview` field was also used as an int if present; arrays may result in an unintended lookup. Now **after** the existing `Gate::authorize('security.view')`, each explicitly provided parameter must be a string, otherwise HTTP **404**. Known sections are still allowlisted `users/roles/governance/sessions/audit`; existing unknown **scalar** section still falls back to Users. Existing search literal escaping, account scope, status and pagination remain unchanged. No bypass or data write.
- `SidebarManagementController::roleAccess` previously used `(int) $request->query('role_id', first)` then `?? first` even for explicit unknown or forged IDs. That silently displayed another role's permission matrix and could confuse manual role review. Now **after** the original `Gate::authorize('roles.manage')`: absence selects existing first role, explicit selectors require a positive decimal string and a real matching role. Malformed array, zero, negative, nonnumber or unknown ID returns **404**. Archived and inactive roles are not deleted or converted to new grants; the existing permission editor is additionally hidden for `is_active=false` roles while the original backend `RbacManager` remains the mutation authority.
- No user-visible database ID, raw JSON, new permissions or fabricated values have been introduced. Existing option hidden internal values are not surfaced as text by this change. No Sidebar/Menu/route/migration/API changes.
- Existing Security tabs are native links, not synthetic `role=tab` controls that would need additional keyboard arrow semantics; desktop/tablet and 320/360/390/412/430px mobile use horizontal scrolling rather than wrapping. AR/EN values come from existing `admin.security.tabs.*` keys.

## Real HTTP/test obligations

New `V2SecurityCenterQueryTabAuthorityTest` exercises actual seeded Laravel HTTP: AR/EN RTL/LTR all five valid `section` links and exact Sidebar selected role, malformed array GET `section/q/status/assignment/deletion_preview` 404, explicit array/invalid/unknown role selector 404, unknown scalar section fallback; archived/inactive roles as historical but not editor authority, actor with only archived/inactive roles cannot enter either page; mixed-role ALLOW plus **per-user GLOBAL DENY** on security.view or roles.manage forces 403 on each corresponding direct HTTP route; roleless and B2C store-only security role cannot access platform Security/Sidebar editor. Each GET verifies `audit_logs`, `role_user`, `user_store_roles`, `permission_role`, `user_permission_overrides` and saved Sidebar visibility counts unchanged. Check all actual tests on exact commit in CI; this document is not a test pass.

Existing contracts to retain/regress:
- `V2SecurityAdministratorRoleMutationAuthorityTest` explicit mutation DENY with audited reason.
- `V2RoleSidebarDenialLivePreviewTest` source-permission vs unsaved preview and readiness.
- `V2SidebarArchivedRoleVisibilityTest`, `V2InactiveArchivedSidebarRoleMatrixTest` role historical/audit behavior.
- `V2SidebarLayoutAuthorizationSeparationTest`, `V2SidebarRoleVisibilityHttpBoundaryTest` preferences vs real HTTP.
- `EnterpriseSecurityAdministrationTest`, `V2SecurityGovernanceHubAuthorityTest` Maker/Checker and authorized localized tabs.

## Threats, deferred sources, impact

**REVIEW_RISK: HIGH** (RBAC/role selector). `TECHNICAL_REVIEW: PENDING_EXACT_HEAD_CI` until all required checks and file-by-file audit. The model-level contradictory **`roles.archived_at != NULL && is_active = true`** effective global/store permission question remains separately owned by P006/P013 (#2751/#2758) and is NOT silently fixed in this UI request: altering `User.php` role decision globally would affect API/Flutter and require a separate tested source authorization lane. This change tests normally inactive archived roles and preserves historical access without granting them new permissions.

Customer: **NO_DIRECT_IMPACT**; Driver: **NO_DIRECT_IMPACT**; Van: **NO_DIRECT_IMPACT**. Four owned changes are Dashboard Security GET presentation, query shape guards, regression test and this document. No API, Flutter, mobile session, prices, dispatch or ledger source modified. B2B VAN ONLY / B2C DRIVER ONLY untouched.

For final integration: verify exact final SHA, Repository Policy, V2 Isolation, terminal Required CI, full Laravel/security and AR/EN, no colliding worker file owners; record HIGH file review and merge only original PR to `Foodex_V2`. User-facing browser viewport/UAT and full RBAC/ERP final acceptance stay #2750/#2748/#2503; do not close the master gates or claim live access testing. No main, production, tag, release or deployment.
