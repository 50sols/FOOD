# V2 UX G009 — ERP Center horizontal workspaces (#3960)

**Source:** #2750 (P145/P369–373), #2503; `AGENTS.md`; UI placement, Dashboard UI/UX and Mobile Web contracts. FEATURE FREEZE: existing route/Blade only.

## Route and ownership classification
| Surface | Evidence and decision | Ownership |
|---|---|---|
| Overview Dashboard | Executive `_executive-overview` combines access scope, module links, urgent decisions and SaaS status; simultaneous visibility is the executive-dashboard pattern. **NO_TABS_SINGLE_FLOW**; no source change. | No edit |
| Action Center / My Work / approvals | `/admin/action-center` has six server-selected sections (`summary, filters, queue, analytics, activity, approvals`) from merged #3948. **KEEP_EXISTING_TABS**. Saved views, maker-checker and approval deep links unchanged. | #3947/#3948; no edit |
| ERP Center | `/admin/erp` has four independently revisitable sibling components (snapshot/KPI, workload and state analytics, personal follow-up/activity, module browsing). Original static classifier marked `NO_TABS_SINGLE_FLOW` based on lexical metrics; current Blade source inspection establishes independent sibling functions. **CONVERT_TO_HORIZONTAL_TABS** on this route only, without changing entry route, controller, data or module cards. | #3960 Blade only |
| ERP work queues / Procurement, WMS, Finance, Treasury, Customer Service | Existing domain workbenches/tab strips are governed by their own routes, permissions, tenant/entitlement, POST and saved views. **KEEP_EXISTING_TABS / no source modification**, no duplicated cross-domain page. | Other workers, unchanged |

## Placement record
- Domain: Enterprise ERP; entity: authorized ERP modules and user work items.
- Role: an existing platform ERP-authorized actor (NOT store-only B2C actor); user goal: move between independent ERP context panels.
- Workflow: read-only dashboard + module navigation; frequency: recurring; data density: KPI, charts, work queue and module grid.
- Existing parent/route: `admin.erp.index`, `ErpCenterController::__invoke`, `AdminNavigation::canAccessPlatformErp`.
- Pattern: executive command center composing independent page panels. Placement: **Compose** existing four sections as `PAGE_TAB` (Reuse → Compose); no new screen/route/Sidebar, global CSS, component or JS.
- Entry point: existing authorized ERP sidebar; fallback/back path: ERP Center and canonical module URLs. Module links remain conditional on controller authorization and readiness.
- Desktop/tablet/mobile: existing native `.foodex-tabs/.foodex-tab`, one horizontal scrollable single-line rail, with **only the selected authorized panel rendered by the server** below; 320/360/390/412/430/768/1024/1280 widths under shared Mobile Web CSS; no extra page-width overflow introduced by the rail.
- RTL/LTR: existing paired `admin.erp_center.*` translation keys, HTML `lang/dir`, native anchors with visible focus. Tab labels: Snapshot, Analytics, Follow-up, Modules; one active `aria-current="page"` and exactly one selected linked panel in HTML; three inactive panels are **absent**, not merely `hidden`.
- States: valid tab/snapshot default; array and unknown tab reject to snapshot unless authorized `status` filter is selected, in which case module grid is the safe fallback. Valid `status` and `module` retained when switching tabs; status filters always route to module tab. Historical module health KPI `status=degraded#erp-modules` and a validated authorized `module=procurement` deep link both select Modules by default, with zero controller edits; invalid module falls back to Snapshot. No write actions.

## Security and data contracts
1. `ErpCenterController` performs admission before rendering; this PR does not alter it or intersect with #2858 / PR #3065 ownership.
2. Tab query is server-rendered with strict `is_string + in_array(..., true)` allowlist; a query parameter never authorizes a module. `status` comes from controller validation; `module` is passed only if scalar and matches a controller-visible card. **Each full panel is guarded by `@if($activeErpTab === ...)` on the server; nonselected KPI, work-item, activity and module-card HTML is not transmitted. `hidden`/CSS is not treated as a data-access boundary.**
3. KPI destination URLs, my-work deep links, health status, entitlement/readiness, module URLs and cross-store control are unchanged.
4. Customer: **NO_IMPACT** — no Flutter code, API, auth scope, price/credit or route changes. Driver: **NO_IMPACT** — no dispatch/collection routes changed; B2C DRIVER ONLY unchanged. Van: **NO_IMPACT** — no Van API/custody changes; B2B VAN ONLY unchanged.
5. No new business configuration, status mutation, exported data, raw IDs, migrations, or external dependency.

## Tests and review gate
New `V2ErpCenterHorizontalTabsTest.php` performs authenticated per-tab HTTP checks, invalid and array input, status fallback, **exactly one server-rendered panel and negative absence of inactive panel markers and confidential follow-up/module/KPI regions**, AR/EN and RTL/LTR, status/module context, KPI/action-center links on their **selected** panels, no tab-triggered module/entitlement mutations, unauthenticated denial and B2C store-only forbidden access. Three existing #2858/#3065 ERP Center, denial-diagnostics and wholesale-navigation test files received a narrow test-only fixture correction: module-card assertions now GET `?tab=modules`, and the old all-panels-at-once expectation is split into authorized GETs for each selected panel. This temporary test-only ownership transfer and coordination are recorded in #3960/#2858 comments; no ERP/controller/authorization source changed. Preserve the broader P135 assertions and do not edit these shared test files further without renewed owner coordination.

## Original-PR review blocker and correction
- Same original PR #3961 had a later `TECHNICAL_REVIEW: FAIL` on HEAD `117713955a90098c5475e9ac008d2a1d12647470`: four sections were all shipped to browsers with the other three `hidden`, making inactive scoped data available in HTML.
- Corrected **on the original branch** (no replacement PR): use strict server-selected `@if` around each entire sibling section and remove dormant hidden-only CSS. Navigation remains four links with one `aria-current="page"`; only one full section exists per response. The original controller, module entitlement/readiness, role/store boundaries, status/module allowlists, KPI links, saved Action Center views and approval actions are untouched.
- New source test explicitly rejects the former behavior by requiring exactly one `data-erp-panel` in response HTML, no markers or payload from other panels, and verifying modules filters, snapshot KPIs and follow-up links only on selected corresponding GETs. It also checks authorized module deep links route to Modules, whereas unrecognized module keys safely remain at Snapshot. Final exact-head CI must be rerun because the prior SUCCESS was on the **rejected** review SHA.
- This is **source and automated HTTP evidence only**. Visual AR/EN RTL/LTR viewport, keyboard and screen-reader UAT remains at #2750; there is no assertion that local browser UAT occurred.

**REVIEW_RISK: HIGH** (ERP module navigation and readiness-sensitive presentation; source authority unchanged).
**TECHNICAL_REVIEW: PENDING** until exact-head checks, file-by-file audit and all regression findings resolve.

Premerge gates: Repository Policy, FOODEX V2 Isolation Gate, Required CI, affected Laravel, Pint, localization/structural UI and negative RBAC. Require all terminal SUCCESS on exact PR head; manual device/staging UX acceptance is tracked by #2750/#2503. No main/tag/release/production.
