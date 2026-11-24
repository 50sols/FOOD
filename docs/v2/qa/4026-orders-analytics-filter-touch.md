# FOODEX V2 G009 — #4026 Orders Analytics filter touch targets

## Existing screens and placement decision
- Parent #2750; master #2503. Integration `Foodex_V2` only. Source baseline `1eacd4a925802e22abd36a67d4d8712b60496449`.
- Domain **Orders & Fulfillment** → authorized `GET /admin/operations/orders`; Orders and Analytics are already two independent PAGE_TAB views (original #3972/PR #3973 MERGED), and Analytics already includes four selectable read-only subviews. **KEEP_EXISTING_TABS**, no additional workspace or Sidebar node.
- **Orders grid/search/status filters:** already fixed in original #3996. Preserve its `_order-operations-order-filters.blade.php` untouched, one-row searchable `GET` with independent pagination and `#order-tab-orders`.
- **This leaf's actual UI defect:** the separately rendered Analytics filter row in `_order-operations-header-filters.blade.php` uses 34px desktop selects/date fields/apply/reset and 36×38px mobile disclosure. On a 320–430px mobile browser its popover inputs were not explicitly 44px/16px. This violates Dashboard Mobile Web contract. Current row/popover already has the right GET form and `#order-tab-analytics`; **do not create or rearrange tabs/forms**.
- Actor, role, store/channel authorization: `OrderOperationsController::index` and `OperationalTenantScope` remain unmodified. No source permission/readiness controls altered.

## Source changes and limits
| File | Allowed delta |
| --- | --- |
| `backend/resources/views/admin/_order-operations-header-filters.blade.php` | CSS only: desktop GET filter controls/actions min 44px, nowrap x-scroll when necessary; mobile existing summary 44×44px, popover inputs/selects >=44px and 16px; Apply/Reset 44px; visible keyboard focus. Do not modify form attributes, query fields, route, anchor, validation or buttons |
| `backend/tests/Feature/V2OrdersAnalyticsFilterTouchTargetsTest.php` | Isolated AR/EN RTL/LTR authenticated positive B2B/B2C GET, exact two alternative forms and query store/channel/date + `#order-tab-analytics`, structural CSS and 44px/mobile focus, wrong channel 403, foreign store 404, roleless 403, no orders/dispatch/driver/van/audit writes |
| `docs/v2/qa/4026-orders-analytics-filter-touch.md` | This acceptance/owner evidence; no new navigation |

## Other screens reviewed, intentionally unchanged
| Surface | Decision / protected workflow |
| --- | --- |
| Single Order details, invoice, receipt, delivery proof and print popup | **NO_TABS_SINGLE_FLOW**; one record, linked financial and fulfillment state. Do not split operation, approval or payment into tabs |
| New Order wizard | **SKIP_DEPENDENT_WIZARD**; retain step order, validated target and server authority |
| Field Operations / Logistics | **KEEP_EXISTING_ROUTE_SECTION**; controller-selected `fieldOperationsSection`, Finance partial already owns native scoped tabs, and Van-only work is OWNER_DEFERRED. No mutation |
| Driver Live Tracking | **NO_TABS_SINGLE_FLOW**; authorized single live map, polling, driver markers and store scoping untouched |
| Status handling, warehouse/dispatch, routes, assignments | **NO CHANGE**; immutable decision **B2B→VAN ONLY**, **B2C→DRIVER ONLY** (no fallback) |

## Acceptance / security / reviewer gate
- **Navigation:** same existing authorized Orders route, selected channel and Store, `from/to`, `action_required=0`, `#order-tab-analytics` on both GET forms. Native Reset returns existing query context. No new POST, endpoint, tracker, batch, pagination or feature.
- **Responsive:** desktop >=1180px stays one row (contained horizontal scroll if text grows); at <=1180px original desktop form is hidden **only while** its existing mobile disclosure exposes equivalent GET controls; 44px targets and 16px inputs on phone. AR/RTL + EN/LTR and keyboard focus visually guided.
- **Security:** no JS mutates order state on switching; GET reads never create/update `orders`, `order_dispatch_states`, `driver_assignments`, `order_van_assignments` or audit. B2B/B2C HTTP negatives and inaccessible foreign Store must remain denied before rendering private data. No permission, branch/Store visibility, route, fulfillment actor policy or entitlements changed.
- **Scope:** Laravel ERP/web Dashboard ONLY. Customer/Driver/Van Flutter/APK: NO_DIRECT_SOURCE_IMPACT (all current V2 mobile work OWNER_DEFERRED). Public app APIs/DTOs unchanged; existing B2B VAN ONLY / B2C DRIVER ONLY backend regression remains required.
- **REVIEW_RISK: HIGH** due to order/dispatch adjacency, even though implementation is limited to read-only CSS. **TECHNICAL_REVIEW: PENDING exact-final-head success** until GitHub Required CI, Policy, Isolation, backend Laravel positive/negative tests, AR/EN, static analysis, security/MySQL acceptance all terminal SUCCESS. Do not claim manual viewport, keyboard or browser UAT unless actually executed. Final manual G009 acceptance #2750 and master #2503 remain OPEN. No main, tags, release, APK or production.
