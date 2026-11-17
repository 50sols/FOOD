# V2 #3996 — Orders one-row filter reachability and semantic Apply

## Source and integration
- Parent: G009 #2750; master #2503; superseded Orders tab accessibility already completed by #3972 / PR #3973. This issue is a **narrow existing-UI defect** under FEATURE FREEZE.
- Canonical page: Operations → Orders, GET /admin/operations/orders, existing OrderOperationsController@index and server-authoritative tenant/channel/store scoping. No route/Sidebar/controller/API, dispatch or finance changes.
- Original UI source: backend/resources/views/admin/_order-operations-order-filters.blade.php, included within the existing Orders workspace. Only this partial plus one independent Laravel test and this QA document changed.

## Before / after
| Concern | Source defect on Foodex_V2 | Remediation |
| --- | --- | --- |
| Apply | Invalid '<label><span class="order-gr="submit">' HTML, no functional Apply button | Restore a single native type=submit button in the existing GET form |
| Primary order/status/driver filters | CSS hides controls below 1100/1350px without an alternative form input | Keep exactly one of each control in a contained horizontally scrollable nowrap primary rail at all widths; never duplicate successful input names |
| Touch and zoom | 34px control/action/More disclosure and .66rem filter text | >=44px touch targets; phone >=16px text input/select |
| Extra filters | Existing details popover stays independent and uses the same GET | Preserve its Store, origin (B2B only), period, dispatch status and exception fields; no nested form |
| Search / scope / reset / pagination | Existing GET form uses channel, authorized store, action_required and #order-tab-orders | Preserve unchanged, do not introduce a new tab, query API or pagination path |

Placement: REUSE existing PAGE_TAB workbench and single-row filter; not a new tab inside order details. The main Orders/Analytics tabs and nested analytics panels remain from #3972; this leaf changes no tab state logic.

## Security & business acceptance
- Existing OrderOperationsController, OperationalTenantScope and FulfillmentActorPolicy continue to authorize all reads and state transitions. Switching a tab or applying GET filters never writes to orders, audit, driver, van or dispatch.
- B2B → VAN ONLY; B2C → DRIVER ONLY. No Worker on this issue may change assignment rules or permit disallowed fallback. A historical B2B Driver search/readback is not a dispatch grant.
- Existing Orders status links, independent pagination, detail record popup, invoices/receipts, print, payment, New Order wizard, logistics and live Driver map **UNCHANGED**.
- AR/EN and RTL/LTR preserve native labels/selects, keyboard access, scrollable one-row filter on mobile 320/360/390/412/430, tablet 768/1024 and desktop 1280+. Browser/device viewport behavior remains **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL**, not claimed tested.
- Authenticated Laravel regression exercises authorized B2B and B2C GET in both languages, rendered semantic controls, single submitted input per field, retained scope + Reset deep link, explicit wrong-channel and foreign-store DENY, inactive actor DENY, and zero mutation of orders/dispatch/driver/van/audit. Structural assertions guard breakpoints and minimum touch sizing.
- No new HTTP endpoints/hidden panels/permission gates, no price/credit/financial authority, no CSRF/write path changes. Existing server GET validation and role restrictions stay authoritative.

## Independent surface classification
| Surface | Decision | Code impact |
| --- | --- | --- |
| Orders list, grid, filters | KEEP_EXISTING_TABS, repair existing filter row | Partial only |
| Order detail / status mutation / New Order | NO_TABS_SINGLE_FLOW / SKIP_WIZARD | None |
| Invoice, receipt, print, payment evidence | NO_TABS_SINGLE_FLOW | None |
| Logistics / Field Operations Finance | KEEP_EXISTING_ROUTE_SECTION / KEEP_EXISTING_TABS | None; #2606 field operations branch must not be touched |
| Driver live tracking / maps | NO_TABS_SINGLE_FLOW | None |
| Customer app | NO_DIRECT_SOURCE_IMPACT | No Flutter/API changes |
| Driver app | NO_DIRECT_SOURCE_IMPACT | No Flutter/API changes |
| Van app | NO_DIRECT_SOURCE_IMPACT | No Flutter/API changes |

## CI and integration checklist
- REVIEW_RISK: HIGH (orders/fulfillment-adjacent). TECHNICAL_REVIEW: PENDING exact-final-head tests and an explicit path-by-path audit.
- Required: Laravel HTTP + static filter contract, Repository Policy, V2 Isolation, AR/EN, backend tests and MySQL/Redis where CI applicable, terminal required-ci-gate SUCCESS **on final PR HEAD**; skipped != PASS.
- One original PR base=Foodex_V2 only. No main, production, release, tags or deployment. Close this leaf only after real merge SHA and CI evidence; #2750/#2503 remain OPEN until final platform UAT.
