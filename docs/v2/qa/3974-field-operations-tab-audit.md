# FOODEX V2 / G009 #3974 — Field Operations route and finance-tab authority audit

**Baseline:** `Foodex_V2@557d928f534a7ffb3d5609a36f980a76a12c1be9` after successful merge of #3972; target `Foodex_V2` exclusively. **Feature freeze:** no Sidebar, new feature, module, route, entitlement, implementation of Van trips, or finance posting.

## Placement decision

- **Domain:** Fleet & Field Operations → finance operations; **entity:** authorized B2B store-scoped wallets/collections/remittances/reconciliation.
- **Roles/permissions:** server's existing `finance.view` (read) and separately `finance.manage` (review/post); no new grants. `FieldOperationsController::finance` validates `ops_tab` against `wallets,collections,remittances,reconciliation`. Existing `FieldOperationsFinanceService::viewModel` owns store scope, pagination, state, precision.
- **Goal:** switch high-frequency operational ledger-related read panels while retaining the current search/status/page-size and upstream B2B finance context; keep authenticated POST review separate.
- **Existing parent/routes:** canonical `/admin/field-operations/finance` and embedded `/admin/b2b/finance?finance_tab=operations`. Both reuse `_field-operations-finance.blade.php`. Navigation via existing Sidebar/Finance domain; `PAGE_TAB` for four existing sibling finance read workspaces; no direct-URL-only entry.
- **Placement:** **Reuse → Extend** existing `.foodex-tabs` link rail; not a different renderer, no JS tabs, not a wizard. Only CSS and accessible marker scoped to this view fragment.
- **Screen pattern:** Operations Data Explorer / Grid, one table per authorized server-rendered `ops_tab`; actions remain conditional and bound to original Remittance row. One-row horizontal scroll on desktop/tablet/mobile (320/360/390/412/430/768/1024/1280); 44px targets, `aria-current=page`, focus-visible; AR/RTL and EN/LTR use existing labels/document direction. Actual browser/device UAT deferred #2750.
- **States:** existing empty/status/approved/pending/posted/reconciled views and finance POST validation/redirect retained; no new client state/polling. No change to `ops_page` reset when tabs change, original `ops_q`, `ops_status`, `ops_per_page`, `finance_tab` preservation remains.

## Complete user-request screen classification and ownership fence

| Surface | Decision | Evidence / owner |
| --- | --- | --- |
| Field Operations Overview `/admin/field-operations` | **NO_TABS_SINGLE_DASHBOARD** for concurrent KPIs/exceptions; sibling route navigation should be a separate authorized one-line `PAGE_TAB` bar | `field-operations.blade.php`, `FieldOperationsController` belong to #2606/PR #3311, **HANDOFF** issued to original owner in #2606; this leaf does not edit |
| Fleet / Live Map | **NO_TABS_SINGLE_LIVE_FLOW** inside map; keep persistent map + online/stale/offline, filters, markers, silent polling and recenter | `_driver-live-map.blade.php`, `driver-live-map.js`; no changes |
| Vans list and Van detail/trip/session | **NO_TABS_SINGLE_RECORD / WORKFLOW**; route links to authorized sibling sections (not hidden concurrent sessions) | `field-operations.blade.php`, `VanTripSessionService.php` #2606/PR #3311; no changes |
| Assignments | **NO_TABS_SINGLE_FLOW**; owner/scoping and B2B-only lifecycle not split | Existing `field-operations` section and `_driver-assignment-management.blade.php` unchanged |
| Customers / Visits | **NO_TABS_SINGLE_FLOW** as operational queue/workflow; don't activate a visit on merely selecting a tab | `FieldOperationsController::visits`, original lifecycle and customer/store authority, no changes |
| Territories / Routing | **NO_TABS_SINGLE_MAP_OR_RULE_BUILDER**; polygon editor and route policy builder need synchronized state; no forced hidden panels | Existing `field-operations` route and map, no changes |
| Finance `/admin/field-operations/finance` | **KEEP_EXISTING_TABS → enforce one-row** | Four existing wallets/collections/remittances/reconciliation tabs, this leaf edits only shared read-only partial |
| B2B Finance embedded Operations | **KEEP_EXISTING_TABS** one row | Same partial reused, `finance_tab=operations` in query preserved |
| Van trip settlement / closeout | **SKIP_DEPENDENT_WORKFLOW** | #2602/PR #3332 and #2607/PR #3313 separately own custody settlement/closeout; untouched |
| Driver tracking | **NO_TABS_SINGLE_LIVE_FLOW** | `driver-live-tracking.blade.php`, map shared and GPS state; untouched |

**Route-level sibling navigation handoff:** the main Field Ops Blade is actively within original #2606 PR #3311 changed-file fence. User prohibited overlap; G009 asked original owner to use `AdminNavigation`'s permission/tenant-aware projection if adding main route-level page tabs, retaining existing `overview/fleet/vans/assignments/customers/visits/territories/routing/finance` route authority and Van-detail back context. This is NOT implemented in this leaf and is NOT represented as complete.

## Changed file fence

- Edited `backend/resources/views/admin/_field-operations-finance.blade.php`: scoped CSS `.fieldops-finance-tabs` enforces desktop/mobile nowrap, confined X scroll, 44px link, existing active `aria-current` and keyboard focus, no shared global CSS impact; added `data-field-finance-tabs` to existing nav. No change to tab link data, tables, form, POST, CSRF, money conversion, store scope, role gates, pagination or money precision.
- New `backend/tests/Feature/V2FieldOperationsFinanceTabsPresentationTest.php`: authenticated AR/EN RTL/LTR and both canonical embedding routes, four existing valid tabs with safe query/pagination context, inactive/roleless access denial, no mutations of `collection_accounts/collection_transactions/remittances/custody_ledger_entries` on GET, source layout/POST ownership assertions.
- This QA file only.

## Security / mobile and CI evidence register

- Existing `finance.view/finance.manage` gates unchanged; no client-side privilege, report, account or custody transition, no hidden tab executing `POST`. Same physical ledger/service, no duplicates. No source edits for Customer/Driver/Van or their APIs: each **NO_DIRECT_IMPACT** (no Flutter test-pass claim).
- B2B VAN ONLY and B2C DRIVER ONLY unaffected; no dispatch, loading/unloading, GPS, map, silent-refresh, Van trip sessions/returns/settlement changes.
- **REVIEW_RISK: HIGH** (finance-adjacent), **TECHNICAL_REVIEW: PENDING** actual exact-head Laravel+MySQL/Redis, policy, isolation and localization checks. No unverified PASS claims. Exact tests and CI run links will be added to original PR/Issue.
- **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under #2750/#2503. Keep #2602/#2606/#2607 and parent gates OPEN according to their own conditions; no production, main, tag, release or cPanel.
