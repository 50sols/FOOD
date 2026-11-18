# FOODEX V2 G009 — Procurement Workbench tab and pagination defect (#3964)

## Source, scope and route lock
- Program: #2503, UX gate #2750, Procurement contract P238–P245 and existing RFQ/3-way match/returns workbench.
- GitHub preflight: `Foodex_V2@3729aca2a07d400c9c17b08db9f4b5cd9ba6664d`, original Issue #3964 and one branch `fix/3964-v2-procurement-workbench-tab-layout`. Reviewed active PRs, #2858 / PR #3065 owns `ProcurementCompletionManager`, existing Procurement Workbench/Completion tests and enterprise_procurement translations; no file overlap.
- Existing canonical route: `admin.erp.procurement.index`. Existing controller `EnterpriseProcurementController::index` determines current store via `context()` and `authorizeTab()`. This is **NOT** a request to change authorization or create new UI. Domain: procurement, role: entitled and scoped procurement reader or approver, entity: supplier/order/receipt/invoice and operational record, frequency: recurring, density: tabbed data grid/action workbench.
- Placement: **Reuse** `PAGE_TAB` for existing twelve sibling contexts; **NO NEW SCREEN**, Sidebar, route, query model, modal, shared CSS, JS, API, backend permission, store lookup or migration. Workflows and record detail navigation remain in their current tabs.

## Verified current layout issues
1. `enterprise-procurement.blade.php` contains twelve existing horizontal menu links `$tabs` (only server-authorized links), using shared `.foodex-tabs` which can wrap on wide viewports. Added a **page-local** `.erp-procurement-tabs` scrolling nowrap rail (consistent 44px hit targets, focus outline, AR/EN and native RTL/LTR) without a second navigation strip.
2. The embedded `admin._enterprise-procurement-completion` already emits its own numbered paginator in `rfqs`, `contracts`, and `matches` after its list. The parent template emitted a second `$rows->links()` after everything, which is redundant and can force long scrolling past the selected detail. Parent footer now only renders for `suppliers, requests, orders, receipts, invoices, returns`. Embedded RFQ/contract/match links remain `withQueryString()`; footer links explicitly preserve query by `withQueryString()`. Statement paging remains on its dedicated statement. Overview/Reports do not render a generic row paginator.

## Canonical tab / authorization matrix
| tab= | Permission / control (existing only) | Pagination |
|---|---|---|
| overview, suppliers | procurement.suppliers.view | overview none; suppliers footer |
| requests, rfqs | procurement.purchase_requests.view | requests footer, rfqs embedded |
| orders, contracts | procurement.purchase_orders.view | orders footer, contracts embedded |
| receipts | procurement.goods_receipts.view | footer |
| invoices, matches | procurement.purchase_invoices.view | invoices footer, matches embedded |
| returns | procurement.purchase_returns.view | footer |
| statements, reports | procurement.supplier_statements.view (and existing report operations gate) | statement-specific, report preview existing |

Controller remains source of truth for store_id and tab permission; `$tabs` is a server-authorized array. Each tab URL only carries `tab` + `store_id` intentionally: `q/status` from one business domain must not leak to a different domain. Search/filter GET forms carry selected `tab, store_id, q, status`; pagination on the active tab retains query context. Record deep links (supplier/request/rfq/order/contract/receipt/invoice/return), existing POST action forms, server-side SoD, maker-checker and CSRF unchanged. Invalid/foreign store and unauthorized role must still receive 403; module readiness remains authoritative.

## UI and mobile / app impact
- AR and EN: existing localized `enterprise_procurement.tabs.*` labels and status forms; `lang/dir` already localized. Keyboard focus visible; no wrapping; 320/360/390/412/430 mobile web widths scroll **inside tab rail** not the page. Tablet/desktop use same rail. No new master data/user inputs or raw technical IDs.
- **Customer: NO_IMPACT** — no Customer Flutter or public B2B pricing/API code altered.
- **Driver: NO_IMPACT** — no B2C dispatch/collection API or Flutter altered.
- **Van: NO_IMPACT** — no B2B custody/route API or Flutter altered. Existing B2B VAN ONLY / B2C DRIVER ONLY invariant preserved.

## Test and technical-review gates
`V2ProcurementWorkbenchTabLayoutTest` (new, disjoint from #3065) exercises authenticated real GET rendering of all twelve `tab=` cases and selected `store_id`, AR/EN and LTR/RTL, exactly one existing 12-item tab rail and single active nav item, filter `q/status/store_id/tab` readback, RFQ/contracts/matches one inline paginator with **no duplicate footer**, other list tabs one footer, Overview/Statements/Reports no generic footer, cross-store denial on all tabs and unprivileged denial. Existing Procurement workbench tests establish maker/checker/POST and page contracts (not touched).

**REVIEW_RISK: HIGH**: procurement/finance-sensitive surface, even though only read-only layout changed. **TECHNICAL_REVIEW: PENDING** until file-by-file audit and real latest SHA CI evidence. Merge only to `Foodex_V2` after Repository Policy, V2 Isolation, backend tests (incl real MySQL/Redis), localization, security/permission and terminal Required CI success; full UX/UAT #2750 and program #2503 remain OPEN. No main/tag/release/production claims.
