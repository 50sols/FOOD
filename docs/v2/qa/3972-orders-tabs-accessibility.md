# FOODEX V2 / G009 #3972 — Orders existing-tab accessibility and fulfillment-scope audit

## Canonical placement and feature freeze
- Domain: Operations → Orders, existing `/admin/operations/orders` and `/admin/b2b/orders`. Parent #2750, master #2503.
- Entity: Order. User goal: navigate Orders grid/status lists versus independent read-only analytics, with nested Performance/Operations/Channels & Payment/Customers & Products analytics. Frequency: daily operational monitoring. Data density: paginated order grid plus bounded charts/KPIs.
- Permission: existing `orders.view` with authorized channel/store, independent order manage/dispatch/finance grants. Source authority: `OrderOperationsController`, `OperationalTenantScope`, `AdminNavigation`. No grant or bypass added.
- Placement: **KEEP_EXISTING_TABS**, Reuse + Extend the existing `order-workspace-tabs` and `order-analytics-tabs` in the canonical Blade, not a second route/Sidebar framework. Existing real filters, order detail popup and created Order wizard retained.
- Navigation: existing Sidebar orders domain and in-page PAGE_TAB; no direct-URL-only capability or new Sidebar. Existing `#order-tab-orders` deep links from status filters now point to the actual Orders tab ID; active tab hashes, GET filter URL and server pagination remain unchanged.
- UI: AR/EN labels unchanged, document `dir` continues to direct RTL/LTR, native button focus/roving tabIndex, Arrow/Home/End, focus visible, min 44px tab & status filter touch target across desktop/mobile, scrollable contained tab bars; order grid responsive design unchanged. Browser/device checks at 320/360/390/412/430/768/1024/1280 remain final UAT, not asserted as executed.

## Route/screen inventory (screen-by-screen)
| Surface | Decision | Existing authority / acceptance |
| --- | --- | --- |
| `admin/order-operations.blade.php` Orders list and filters | **KEEP_EXISTING_TABS**, scoped fix | `/admin/operations/orders`, original Orders/Analytics and analytics subgroups, `OrderOperationsController::index`; existing status filter links, order search/filter row, pagination and channel/store untouched |
| Order detail/edit popup, payment/dispatch, proof actions, New Order | **NO_TABS_SINGLE_FLOW / SKIP_WIZARD** | Same order record, sequential authenticated validation and status transition; do not split or hide mutation controls behind cosmetic tabs |
| Invoice/receipt / print/delivery proof | **NO_TABS_SINGLE_FLOW** | Existing `/admin/invoices/{invoice}`, order popup print and evidence links; immutable server authority, no tab actions |
| Logistics/Field Operations `field-operations.blade.php` | **KEEP_EXISTING_ROUTE_SECTION, NO NEW TABS** | `$fieldOperationsSection` renders one route-authorized section at a time. #2606/PR #3311 actively owns file; not edited |
| Driver live tracking `driver-live-tracking.blade.php` | **NO_TABS_SINGLE_FLOW** | Single authorized interactive map, not separable long independent sections; existing map + JS owner unchanged |
| `_order-new-wizard.blade.php`, `_order-operation-popup.blade.php` | **SKIP_WIZARD / NO_TABS_SINGLE_FLOW** | Dependency-ordered creation and record actions. No auth/routing refactor |
| `_order-operations-order-filters`, `_order-operations-header-filters` | **NO_TABS_SINGLE_FLOW**, unchanged | Original GET submit/search/filter panels follow active main tab. Existing `store_id/channel` and `#order-tab-orders` status links preserved |
| Field Van/Driver assignment, order status and fulfillment | **NOT UI TABS; NO CHANGE** | B2B VAN ONLY, B2C DRIVER ONLY. This leaf does not touch `OrderManualDispatchService`, `OrderOperationsController`, assigns, models, route files, mobile apps or APIs |

## Code fence and threat checks
Only `backend/resources/views/admin/order-operations.blade.php`, new `backend/tests/Feature/V2OrdersWorkspaceTabsAccessibilityTest.php` and new this QA file. The 21 open V2 PRs had no overlap with Orders Blade; #2606/PR #3311 owns field operations view. No shared CSS, route/controllers, data write, price, ledger, status, Store/Legal Entity RBAC or mobile files altered.

- Existing ARIA top tabs previously lacked ArrowLeft/Right/Home/End roving-focus behavior; their touch heights were 42px on desktop and **36px on mobile**. Nested analytics tab heights were **34px** and **32px** respectively; `role=tab` was pointer-only. Fixed by 44px targets, focus-visible and keydown handlers on existing two tablist elements, with direction derived from document `dir`. Home/End select first/last.
- Added stable `id`/ `aria-controls`/ `aria-labelledby` for existing Orders/Analytics outer tabpanel relationships. Default non-selected tabs are `tabindex=-1`, only selected tab is roving tab stop. Existing nav not rendered for Analytics when dashboard data is unauthorized; no forced Analytics request.
- `tab` interaction is client-side display only: panel `.hidden` and corresponding filter modes. Does not `fetch` financial work or issue `POST`, `PATCH`, `PUT`, update assignments, enable disallowed routes or launch a hidden submit. Existing async popup fetch remains separately user-triggered.
- HTTP regression covers B2B authorized read, RTL/LTR localization, panel semantics, forbidden B2B→B2C or B2C→B2B route, B2C→Van-origin denial, foreign store/roleless/inactive actor deny, and no DB changes to orders/dispatch/driver/van/audit when navigating.
- No Customer/Driver/Van files/API changed: **NO_DIRECT_IMPACT** to all three, without claiming device/widget tests passed. Invariant B2B VAN ONLY/B2C DRIVER ONLY unchanged.

## Acceptance register
- Code structural and authenticated Laravel regression: **PENDING exact-head CI**.
- Repository Policy, V2 Isolation, Required CI, AR/EN and backend/MySQL acceptance: **PENDING exact-head workflow results**.
- `REVIEW_RISK: HIGH` because operational orders/fulfillment-adjacent even though new behavior is UI-only.
- `TECHNICAL_REVIEW: PENDING` exact-head tests and second source audit; no claimed approval or merge.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` via #2750/#2503; do not close parent gate or publish/install/release anything.
