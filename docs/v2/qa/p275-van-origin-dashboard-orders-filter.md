# FOODEX V2 P275 — Van direct-sale origin filter in canonical Dashboard Orders

Atomic Issue: #3897 | Parent P275: #2605 | Van mobile: #2739 | Integration: `Foodex_V2` ONLY.

## Why this leaf exists

The original #2605 PR #3318 already merged its B2B Van store/customer authorization and Van App selection protections; #3515 similarly merged terminal-visit sale denial. This separate source gap concerns Dashboard **discoverability of Van-created direct-sale orders** without duplicating the open #2606 Field Operations layout/route implementation.

Reuse → Extend: `admin.operations.orders.index` + the existing Orders grid and popover filters, with a compact Van direct-sales tab when `channel=b2b`. No new route, dashboard or independent menu/RBAC policy.

## Source and authorization

- `AdminOrderManagementService` writes `pricing_snapshot.commercial_origin = van` in the same transaction when the canonical Van commercial bridge creates an authorized B2B sale. This is the source of origin; `order_van_assignments.source` or current Van dispatch assignment are **not** sale-origin evidence.
- Validated read-only `origin=van` is permitted only for `channel=b2b`. Unrecognized `origin` is rejected by Laravel validation. B2C, all-channel or Retail-only crafted requests receive HTTP 403, not an empty global fallback.
- The existing `OrderOperationsController` applies the actor's operational `orders.view` channel and exact store scopes before the provenance predicate; no new privilege or customer data is introduced.
- All existing order table rows, detail links, pagination/query-string retention, status filters, RTL/LTR responsive controls and AR/EN translations are reused.
- Channel switches remove stale Van-only `origin` so a valid B2C tab does not inherit an unauthorized B2B filter.
- Filter is SELECT-only; no order, stock, payment, dispatch, finance, inventory or customer mutation.

## Test matrix

`backend/tests/Feature/V2VanDirectSalesDashboardFilterTest.php` seeds:
- One authorized B2B origin=van order and a same-store origin=dashboard order; expected only the actual Van direct sale on `channel=b2b&origin=van`.
- A foreign B2B store Van-origin order; expected absent from an actor's authorized commercial scope.
- Retail store-only actor attempting B2C, explicit B2B and implicit channel requests with `origin=van`; all HTTP 403.
- A super-admin attempting `channel=all&origin=van`; HTTP 403.
- Unrecognized `origin` with JSON acceptance; HTTP 422.
- AR/EN tab/filter text, no write count change on GET.

This is HIGH risk because it reads commercial orders. Review file-by-file and require source scope/auth confirmation; Laravel full tests, MySQL/Redis deployment acceptance, AR/EN localization, Repository Policy, V2 Isolation and terminal Required CI must all be SUCCESS on the exact final PR head. **No successful test is claimed before CI evidence.**

## Cross-app effects

| Surface | Classification |
|---|---|
| Dashboard | Affected: existing B2B Orders filter/view; no new operational action or permission |
| Backend | Affected: same authenticated Dashboard GET only; no change to Van API or order creation |
| Van | No app/API delta in this leaf: reads existing immutable Van sale provenance, while #2739 remains the app/stock/settlement/offline acceptance owner |
| Customer | No customer API/Flutter change |
| Driver | No Driver API/Flutter change, B2C driver-only contract preserved |

## Not completed by this leaf

#2605 still needs full real Van direct-sale field runtime and business acceptance, especially Dashboard Sidebar/Field Operations discoverability (open #2606 PR #3311 owns shared files), consignment-stock/finance settlement integration, failure/recovery and signed mobile-runtime #2739 validation. This filter leaf does not claim P275 end-to-end completion or allow closing #2605/#2739/#2503.

No `main` merge, production deployment, tag, release, or automatic V2 promotion.
