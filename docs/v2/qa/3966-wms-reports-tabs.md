# V2 G009 / #3966 — WMS Report Panel Composition

## Source and placement

- **Parent:** #2750 UX gate, #2503 ERP/RBAC master. **Base:** Foodex_V2, initial observed SHA `3729aca2a07d400c9c17b08db9f4b5cd9ba6664d`. FEATURE FREEZE: no new feature, role, endpoint or Sidebar.
- **Domain:** Inventory/WMS. **Entity:** authorized physical stock summaries and WMS operational report read models. **Role:** existing inventory.view scoped actor. **User goal:** switch among independent low-stock, aging, count variance, transfer performance and demand-plan report tables without scrolling through all five. **Workflow:** read-only investigation and existing report-card drilldowns. **Frequency:** recurring. **Density:** five independent bounded data grids.
- **Canonical parent / route:** `admin.erp.wms.index?tab=reports`; `EnterpriseWmsController::index` performs `OperationalTenantScope`, `WarehouseScope`, module/entitlement/readiness decisions prior to including `admin._enterprise-wms-reports`.
- **Placement:** **Reuse → Compose** the already present `.wms-tabs.foodex-tabs` and `.wms-tab.foodex-tab` as an existing-route, in-page **PAGE_TAB** with `report_panel` query parameter. No new route, public endpoint, master data, design primitive, Sidebar, or data source. **Navigation owner:** WMS hub; **entry point:** authorized `reports` outer WMS tab; **back path:** existing WMS tabs + report cards; no direct-url-only new capability.
- **Responsive:** shared 44px native links, single horizontal scroll rail on 320–1280 px within WMS panel; AR/EN localized existing `enterprise_wms.reports.*` labels, RTL/LTR from outer HTML, keyboard visible focus via shared FOODEX tabs. No script or sticky state, normal browser back/forward and GET.
- **States:** five exact `report_panel` allowlist values `low_stock, stock_aging, count_variance, transfer_performance, demand_plan`; missing, array, object-like or unknown input chooses `low_stock`, and only that one table is rendered server-side. Other four datasets are **not emitted as hidden HTML**. Existing eight report navigation cards and shared export-queue notice stay outside the selected table.

## Screen-by-screen classification and ownership

| Existing WMS / Inventory surface | Decision | Source authority / reason |
|---|---|---|
| Inventory overview/dashboard | KEEP_EXISTING_TABS | `tab=overview`, linked KPIs and exact inventory 360; simultaneous dashboard indicators appropriate |
| Warehouses / Locations | KEEP_EXISTING_TABS | `tab=warehouses` / `locations`; page actions and contextual forms tied to scoped selected warehouse |
| Stock ledger/movements, balances | KEEP_EXISTING_TABS | `tab=movements` / `balances`; keyset stock cursor and record drilldown unchanged |
| Batches/expiry | KEEP_EXISTING_TABS | `tab=lots`; creation and lifecycle data are linked work, not unrelated reports |
| Adjustments, Transfers, Counts | KEEP_EXISTING_TABS | `tab=adjustments/transfers/counts`; POST maker-checker, request keys and state workflow unchanged |
| Reservations/allocations, reorder, valuation, aging | KEEP_EXISTING_TABS | `tab=reservations/reorder/valuation/aging`; authoritative business/money/stock reads and writes untouched |
| Demand planning | KEEP_EXISTING_TABS | `tab=planning`; existing approval/hand-off workflow unchanged |
| Reports (five consecutive long grids) | CONVERT_TO_HORIZONTAL_TABS | Existing `_enterprise-wms-reports.blade.php`, page-local `report_panel`, one grid rendered per GET |
| Van loading | SKIP_SECURITY/WORKFLOW | #2588/#3317 and #2844/#3921 remain OPEN/DRAFT; title/custody/purchase order/Van lifecycle not certified; **no speculative Van load UI or stock mutation** |

## Security and legal-stock boundary

1. `store_id` and `warehouse_id` travel unchanged in each report-panel link, sourced from the server-authorized `$storeId / $warehouseId`. Changing `report_panel` never changes the selected store or warehouse. The controller's `assertStore` and `authorizedWarehouseIds` remain mandatory and cannot be bypassed with forged GET parameters.
2. The report panel key is **presentation-only**, a strict scalar allowlist in Blade. No reflected raw JSON/ID, query-based supplier override, or API mutation; no trust in user-supplied `legal_owner`, `supplier_id` or physical custodian.
3. **Owned vs consignment:** the existing `inventories` / `stock_movements` are canonical *physical quantity*, not proof of legal title. WMS report summaries are **not** relabeled as FOODEX-owned or supplier-owned. Neither a supplier on a purchase receipt nor a warehouse/store/Van possession proves ownership. Independent signed title event/valuation/accounting proof remains #2844; Van physical loading and single inventory debit remain #2588/#2606. This PR makes no ownership calculation or certification claims.
4. No changes to goods receipt, returns, transfer service, inventory owner projection, valuations, reservation/availability ledger, maker-checker or mobile APIs.
5. **Customer: NO_IMPACT** — no Flutter Customer or API contract changed. **Driver: NO_IMPACT** — no B2C Driver fulfillment contract changed. **Van: NO_IMPACT** — no Van custody/loading/dispatch/stock API or Flutter changed. B2B VAN ONLY and B2C DRIVER ONLY remain untouched.

## Test and audit contract

New independent `V2WmsReportsHorizontalPanelScopeTest.php` uses disposable schema fixtures and existing `EnablesEnterpriseWms` test-only setup to check:
- all five selected report sections with exact AR/EN and RTL/LTR, only one rendered data grid, five native links, one active, selected `tab=reports`, authorized `store_id`, `warehouse_id`, `report_panel` query;
- missing, unknown, array/object-like user GET input fallback;
- direct cross-store/foreign-warehouse/unauthorized-role/disabled-module HTTP DENY;
- forged legal-owner/supplier parameters do not change physical stock quantities or expose foreign-store product rows.

**REVIEW_RISK: HIGH** (reports of sensitive inventory/valuation despite read-only Blade change).
**TECHNICAL_REVIEW: PENDING** exact-head CI and file-by-file review. Current source may have separate unfinished legal-title producer dependencies: do not mark their missing owner ledger resolved by a green UI test.

Before integration: Repository Policy, FOODEX V2 Isolation, localization AR/EN, Laravel/Pint/static/security, full Backend and MySQL/Redis acceptance, terminal Required CI all SUCCESS on the exact final SHA; recheck branch base and lease before only `Foodex_V2` merge. Final manual UX/staging acceptance belongs to #2750/#2503; source-incomplete #2844/#2588 remain open. No main, tag, release, cPanel or production.
