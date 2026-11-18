# V2 #3969 — Treasury Master tab/context and hidden-editor isolation

**Evidence basis:** `Foodex_V2` initial source at 2026-11-17; original issue #3969 (G009 #2750, P082 #2818, program #2503). **No production/UI device test claim.**

## Placement and ownership
- Domain: Finance & Treasury → existing Treasury Master (cashboxes, bank accounts).
- Entity/roles: cashbox/bank-account Master Data; actors with store-scoped `finance.view`; Create/Edit only existing store-scoped `finance.manage`.
- Goal: select a sibling banking/cashbox tab without changing organization/store/filter context, and open/edit only the record type on the active tab.
- Frequency/density: daily master-data lookups and occasional changes; existing data grids + one conditional create/edit form.
- Canonical route: `GET /admin/treasury/master-data`, existing `admin.treasury.master.index` and `TreasuryMasterController`. Navigation: existing Finance/Treasury Sidebar/Workbench → Master Data; PAGE_TAB for cashboxes/bank accounts; no Sidebar/route addition.
- Placement: **Reuse → Extend** existing two-tab `foodex-tabs` rail. Not independent new screens or wizard. Existing Cash Closing, Reconciliation, Receipts, Payments, Cheques and Treasury Dashboard remain in `/admin/erp/treasury?tab=...` and unchanged.
- Mobile: nowrap horizontal scroll/touch min-height 44px, no new shell; desktop/tablet same existing layout; AR/RTL and EN/LTR via existing translations and HTML dir.
- State: `tab` scalar allowlist from existing validation; store/query filters validated; form intent must match visible tab, no simultaneous edits; active link aria-current. Bank-account references remain masked in read grid and are not populated into edit inputs.

## Source changes in this bounded leaf
1. `treasury-master.blade.php`: use shared `.foodex-tabs/.foodex-tab` plus horizontally scrollable single-row scoped rail. Preserve validated `store_id`, `q`, `status`, `per_page` in tab, create, edit and cancel links; omit unsafe `create/edit_*` and paginator cursors when switching. Preselect a previously selected authorized store. Show only active matching edit/create form. Existing grid/actions and independent pagination preserved.
2. `TreasuryMasterController::index`: reject (404) form edit/create intents belonging to a different tab, conflicting simultaneous edit parameters or create+edit in one GET, **after finance.view authorization** and **before loading hidden editor records**. For explicitly selected store, prevent an edit of a record from another store even when actor can view both. Existing authorized cross-store model scopes remain.
3. Four existing cashbox/bank-account successful POST redirects now retain the validated target `store_id` and active corresponding tab. Existing `Request::validate`, redirects back on validation error, CSRF, audit, transaction, role/store authority and money/financial posting paths unchanged.
4. Dedicated `V2TreasuryMasterTabsContextTest` uses real authenticated HTTP and RefreshDatabase to cover AR/EN/RTL/LTR, safe tab links, filtered store, hidden forms, contradictory and foreign-store GET, roleless denial, error return, bank/cash creation redirects, and array tab rejection/no audit writes.

## File ownership / integration hazards
- New: this QA file and `backend/tests/Feature/V2TreasuryMasterTabsContextTest.php`.
- Edited: only `backend/resources/views/admin/treasury-master.blade.php`, `backend/app/Http/Controllers/Admin/TreasuryMasterController.php`.
- **Excluded concurrent workers:** #2632/PR #3942 owns `EnterpriseTreasuryController.php`, `enterprise-treasury.blade.php` and `EnterpriseTreasuryWorkbenchTest.php`; #2858/PR #3065 also touches that Workbench test. No edits to those files, Finance posting/ledger, entitlement/readiness, routes, APIs, mobile apps, or legal entity model.
- **Security note:** an independently noted read-entitlement gap in `EnterpriseTreasuryController::index` is out of this file fence and must be resolved by the owning #2632 worker; this leaf does not certify the broader Treasury Workbench.

## Acceptance register
- Laravel HTTP regression: dedicated test in PR; terminal exact-head CI links must be attached before PASS.
- Repository Policy, V2 Isolation, required-ci-gate, backend test suite, localization/UI structural rules: **PENDING CI**, not PASS on source review.
- Layout/viewports (320/360/390/412/430/768/1024/1280), actual browser keyboard and visual inspection: **DEFERRED / NOT EXECUTED**; no screenshot evidence mandated.
- No Customer/Driver/Van code, contracts or endpoints changed: **NO_DIRECT_MOBILE_IMPACT** (source fence); no claim of app tests passing.
- Risk: **HIGH** (Treasury Master finance-adjacent; no payment/GL mutation). Technical review **PENDING exact-head tests**.
- Final V2 UAT remains with #2750/#2503; do not close parent gates based on this leaf.

## Exact-head CI rescue — 2026-11-17

- Earlier head `10a253a6ab964d46317bd4e03184c5180924e4b7` failed Required CI #43966399805: two existing tests expected the historical tab-only Treasury Master redirect for direct POST, and the new test called an unavailable PHPUnit `assertSameCanonicalizing`. This was **3 failed, 4213 passed, 1 skipped**; do not count as PASS.
- Preserve old direct POST redirect when no page-specific selected store context is supplied. A Treasury Master form rendered from an explicitly store-filtered GET sends a *validated boolean* `preserve_store_context` marker; after permitted store creation/edit, the existing post action redirects to the validated business record's authorized store and existing tab. The marker is checked **before** any transaction/writes, so malformed array values fail validation. It adds neither permissions nor a user-facing raw ID/JSON field.
- Dedicated tests exercise selected-context preservation on Bank/Cashbox create/update, invalid marker denial with zero writes, and legacy tab-only redirect. Sort keys and assert exact equality instead of a missing PHPUnit method. The protected historical tests are **not edited**.
- Original branch/PR, 4-file issue fence, and all effective finance view/manage and store guards preserved. New synchronized branch SHA, full Laravel/MySQL/Redis acceptance and technical audit remain required before merge; do not claim exact-head green before actual GitHub CI.
