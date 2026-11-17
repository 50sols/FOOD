# V2 #3962 — disabled/archived role live preview parity (P006)

## Proven source issue

On \`Foodex_V2\`, \`AdminSidebarManager::roleCanUseItem\` already returns false for inactive or archived roles, and \`roleIdsForUser\` excludes their historical visibility preferences. \`SidebarManagementController::roleAccess\` selects a role and sends the canonical server \`roleMatrix\`. But the existing Blade JavaScript \`refresh()\` recomputes the what-if result from checkboxes/layout/scope/channel/module only and can label a historical role eligible. This is a misleading **role-only preview**, not evidence of a real HTTP bypass.

## Bounded remedy

- **Placement:** Reuse/Extend existing Administration → Page Tree & Permissions \`admin.sidebar-access.roles\`, existing resulting Sidebar preview. No new route, Sidebar, workbench, form, mutation or data store.
- **Role & workflow:** authorized roles.manage operator selecting active, inactive or archived role; preview may update only client-side text/ARIA status, never permissions or routes.
- **Rendering:** server emits explicit role lifecycle state per preview node and an accessible, localized visible status on inactive/archived selection. Existing client refresh treats an inactive/archived state as a blocking reason before considering historical checkboxes. AR RTL / EN LTR translation parity; same desktop/tablet/mobile responsive grid and shared header, no new visual primitive.
- **Source authority:** existing \`AdminSidebarManager::roleMatrix\` / \`roleCanUseItem\`, effective role+store/channel/user DENYs, module lifecycle/readiness, module entitlements, maker/checker and HTTP middleware remain untouched. Frontend preview deliberately cannot certify actual tenant/store/action authority; no result-tree direct links.
- **Saved preferences:** \`admin_sidebar_groups\`, \`admin_sidebar_items\`, \`admin_sidebar_role_visibility\` remain presentation only. Historical archived/inactive hide/show rows are preserved for audit and are not applied as effective-role hide. Current active-role hides suppress visible links without revoking authorized direct HTTP 200.
- **Scope/permissions:** role selection requires existing \`roles.manage\`; unauthorized HTTP remains 403. No entitlement/role grant or module certificate is created.

## Positive/negative Laravel acceptance

\`V2SidebarInactiveRoleLivePreviewTest\` seeds actual roles/users and tests role-picker AR/EN HTTP, active role eligible versus inactive/archived with preserved grants ineligible, server lifecycle markers and visible role status; read-only result tree cannot emit unverified HTTP links, and GET never changes permission_role or saved visibility. Combined active B2B + inactive/archived roles with historical hidden preferences retain the B2B Catalog nav and real Catalog HTTP 200, while current active role hide removes only the link; roleless Catalog HTTP 403. Existing #3916/#3138/#2774/#2792/#2786 tests remain mandatory. Fake module readiness not used.

## Impact / gates

- Backend: unchanged runtime authorizer, schema and HTTP write path; test coverage only.
- Dashboard: **affected** read-only role preview lifecycle truth; no layout framework or new menu.
- Customer: NO_IMPACT — no Customer API, Flutter, pricing or ordering change.
- Driver: NO_IMPACT — no Driver API, Flutter, B2C delivery or cash/assignment change.
- Van: NO_IMPACT — no Van API, Flutter, B2B allocation/custody/settlement change.
- B2B VAN ONLY / B2C DRIVER ONLY unchanged.

REVIEW_RISK: HIGH (security-sensitive preview, **no actual authority edit**). TECHNICAL_REVIEW: PENDING until exact changed-file/route/CI review. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL under #2503/#2750 only after source and CI pass. Merge strictly to Foodex_V2 after all exact-final-HEAD Repository Policy, V2 Isolation, Backend Laravel/Pint/PHPStan, AR/EN localization, UI tests and real MySQL/Redis install/upgrade/recovery Required CI are terminal SUCCESS. No main/tag/release/deploy.