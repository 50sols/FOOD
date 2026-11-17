# V2 #3951 — Enterprise BI page tabs: source authority and performance boundary

**Program:** #2503; **UX gate:** #2750 G009; **original issue:** #3951; **target:** `Foodex_V2` only. FEATURE FREEZE: composition of existing BI functions, not new reports/modules/grants.

## Placement / scope contract

| Field | Decision grounded in current source |
| --- | --- |
| Domain / entity | Reporting / Enterprise BI catalog, report execution, executive overview, exports, scheduled reports |
| Canonical route / entry | Existing `GET /admin/erp/bi` (`admin.erp.bi`), linked from authorized Reports Center / ERP navigation; no duplicate route or Sidebar |
| User goal | Independently revisit BI dashboard KPIs, report builder/results, completed export queue, scheduled reports |
| Role / module / entitlement | Original `EnterpriseBiController::index` / navigation / BI entitlement / module readiness; GET page tabs are **never** a permission or certification grant |
| Placement | **REUSE → EXTEND** existing `.foodex-tabs/.foodex-tab` inside `admin/enterprise-bi.blade.php`; canonical parent unchanged |
| Navigation type | `PAGE_TAB`; native GET links on the same route |
| Responsive | One horizontally scrollable row, 44px min tab target, `:focus-visible` for keyboard; 320/360/390/412/430px, tablet 768/1024px, desktop 1280px. Live viewport screenshots/manual UAT deferred to #2750 |
| Locale | Existing `enterprise_bi_ui` keys `overview.title`, `catalog_title`, `exports_title`, `schedules_title` in both AR/EN; root document `dir` unchanged |

## Independent sibling panels — server-render **only selected one**

1. `overview`: unchanged existing `admin._enterprise-bi-overview` include, its scoped KPI/forecast/saved views/drillthrough; default when no report parameter and no tab is supplied.
2. `reports`: unchanged authorized catalog, selected report builder, bounded data table, source drillthrough, error state and query/pagination. Default for legacy `?report=...` deep links. Hidden `bi_tab=reports` is added to the existing GET run form so a valid report submit stays on the report tab.
3. `exports`: recent export rows, their statuses and **existing** reporting exceptions. No download URL fabrication or mutation.
4. `schedules`: existing schedule listing and POST/PATCH forms; original `canSchedule` + report availability and entitlement checks untouched. POST/PATCH return `back()` from existing controller and preserve tab query in Referer.

Panel selection uses only a **strict scalar allowlist**: `overview/reports/exports/schedules`. Missing/unknown/array `bi_tab` falls back to the overview without string-casting arrays or replacing an unauthorized store. Navigation links preserve all existing query context (`report`, `scope`, `from`, `to`, `page`, `dashboard_store`, `dashboard_from`, `dashboard_to`, breakdown/sort/dimensions/measures). They do not mutate, recompute, rewrite or alter source monetary numbers. The overview partial's existing GET filter receives a view-local hidden `bi_tab=overview` input via minimal JS so refresh does not silently open reports when `report` is also present. Keyboard uses native links; no synthetic tab roles without arrow-navigation implementation.

This implementation deliberately uses Blade `@if` **around the entire expensive panel markup** (not CSS-only hidden panes). A GET response never renders all sibling panels. It does not fetch all child workspaces from the browser.

## Data source / readiness / security boundaries

- `EnterpriseBiController` owns catalog/selection, `ReportRegistry`, `ReportAccessResolver`, `ReportBuilderExecutor`, `EnterpriseBiWorkspaceService`, `ModuleEntitlementResolver`, tenant/store / legal entity, module readiness and report results. **No controller, report query, aggregation, exporter, source owner, API, readiness or module flag is modified by #3951.**
- Existing `$item['available']` hides report-launch links when a report lacks authority. No tab bypasses `canAccessPlatformErp`, Store/RBAC, entitlement, or readiness. Existing report source drill links and export/schedule POST endpoints are untouched.
- Reports Center `admin/reports.blade.php` **already has authorized family navigation** and its GET filters, KPI/cards, download formats XLSX/DOCX/PDF and original `ReportController`. No change to it; separate report family links do not become BI certification or new cross-module permission.
- `#3958 / PR #3959` owns `EnterpriseBiController.php` and `EnterpriseBiScheduleController.php` for explicit foreign Store B fail-closed and schedule parity. Do not edit/merge around its controller changes. `#2858 / PR #3065` owns the existing `EnterpriseBiWorkbenchTest.php`, readiness/Navigation and the signed ERP readiness rule. `#3966 / PR #3967` owns WMS reports source. All unchanged in #3951.
- **Important computational limit:** Existing controller **still calls** `$workspace->overview(...)`, `recentExports()`, `recentSchedules()` and `exceptionSummary()` on every page request, and may execute a report when `run=1`, even if that panel isn't selected. This issue's restricted, exclusively owned Blade path can prove **server render reduction only**, not authoritative SQL execution reduction or performance benchmarks. A controller optimization must be coordinated on original #3958 branch/file fence after owner clearance, not silently added here. Until then, performance acceptance beyond rendering remains BLOCKED.
- **Historical test expectation conflict:** `backend/tests/Feature/EnterpriseBiWorkbenchTest.php::test_export_schedule_and_exception_panels_reflect_persisted_w7_state()` currently assumes default root GET renders export and schedule detail and string values `5001`/`1440` simultaneously. Default root is now a single overview tab, so that test must be updated by the file owner #2858/PR #3065 to request `bi_tab=exports` and `bi_tab=schedules` independently. Do **not** weaken it, spoof summary values in unselected tabs, or edit an actively owned test from #3951. This is a named cross-PR blocker for final merge, not silently waived.
- Once signed P135 workflow attestation from #2858 merges, authorized-positive PHPUnit fixtures in a separate PR may need `SyntheticErpWorkflowEvidence::install()` in test scope; never generate production signing keys or mark BI certified to satisfy UX tests.

## Focused tests and acceptance

`V2EnterpriseBiIndependentPageTabsTest` provides real seeded positive/negative authenticated HTTP cases for AR/EN RTL/LTR, all four selections, one rendered panel and three absent, preserved selected Store/date/report scope, malformed array and unknown fallback, legacy report links, read-only business counts, roleless/inactive actor denial, and uncertified module refusal. Existing backend BI source/Read Model, WMS, finance and source HTTP suites remain required. Customer, Driver and Van are each **NO_DIRECT_IMPACT** (only Dashboard Blade/Feature test/QA doc in diff; no API/Flutter/dispatch changes). B2B VAN ONLY / B2C DRIVER ONLY unaffected.

**Risk:** HIGH, permission/store/report privacy adjacent. **TECHNICAL_REVIEW:** PENDING — source-only change and initial audit do not count as full CI pass. Final technical review must include all changed paths, negative tests, exact-head Repository Policy, V2 Isolation, Required CI, Laravel, localization, security, and coordinated existing-test updates. Do not merge/close while there is a failing or running required gate. Full runtime/visual acceptance remains with #2750/#2503. No `main`, production, tag, release or deployment.
