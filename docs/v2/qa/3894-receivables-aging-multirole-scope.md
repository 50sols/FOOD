# FOODEX V2 #3894 — P288 Receivables aging multi-role HTTP authority

**Parents:** #2618 (P288 أعمار الديون) and master #2503. **Integration target:** `Foodex_V2` ONLY. **Issue-scoped PR:** #3899. This acceptance increment is built on the already-merged P288 original PR #3085 and full-ledger pagination child #3217 / PR #3218; neither was recreated.

## Source intent and existing placement

One authenticated Retail operator holds `B2C_STORE_ADMIN` at Store A and `RETAIL_FINANCE` at Store B. Stores A/B/C deliberately share a legal entity. Finance is operationally ENABLED/certified with legally scoped `finance.foundation.read` and `finance.ar.read` entitlement. A per-store `finance.view` grant permits A and B; no operational role assigns Store C.

- **Reuse**, do not invent a separate route/Sidebar: Finance & Treasury → Accounts Receivable → Aging, `admin.erp.finance.index?tab=receivables&store_id=...`.
- Scope/authority: actual store-role assignment + `finance.view` including scoped explicit DENY + module readiness + scoped Finance entitlements; the real Finance Workbench HTTP response is the source of truth.
- No new public API, persistence schema, application permission grant, customer/driver/van route or financial mutation.

## Verified original defect and bounded runtime correction

The canonical `FinanceWorkbenchController::index` filtered `contextOptions()` to readable Finance choices *before* resolving an explicitly selected `store_id`. A signed-in user authorized for B, but explicitly denied Finance at **assigned** Store A, therefore saw **404** for A. This was fail-closed but broke P288/#3894's explicit **403** acceptance for assigned-but-denied stores.

The issue-scoped patch preserves `$assignedContexts` (already scoped to the authenticated actor's B2B/Retail role and store assignments), independently filters readable `$contexts`, and checks only a positive **explicitly selected** Store ID:

- **Assigned, Finance-denied Store A:** 403, even if B remains allowed. Do not call tenant scope/ledger data resolvers for A.
- **Independently assigned and Finance-authorized Store B:** 200, including the aging tab.
- **Unassigned/foreign Retail Store C:** 404. Never leak a foreign store as a distinguishable Finance denial.
- The behavior is read-only; no permission expansion, role/tenant/store fallback, ledger/journal/document writes, or redirect to another store.

The bounded controller hunk is **index pre-context selection only**. Open DRAFT #3309 modifies separate AR settlement and receivable read-model sections, and open DRAFT #3212 modifies reporting access/tab sections; this branch must preserve those distinct file fences. Coordination notes are on #2633 and #2639, and no peer branch or PR is altered.

## Test matrix and exact local evidence

`backend/tests/Feature/V2ReceivablesAgingMultiRoleScopeTest.php` uses `RefreshDatabase`, `CoreReferenceSeeder`, real Retail store-role assignments and `finance.view` test grants, same-legal-entity synthetic A/B/C, enabled/certified Finance entitlements, scoped `user_permission_overrides`, actual Finance index HTTP and both AR RTL/EN LTR. The fixture correctly reads `OrganizationHierarchyService::scopeForStore()` as a dictionary with `legal_entity_id`, while `ResolvedTenantContext::enterpriseScope` remains a typed object.

On isolated GCC, PHP **8.3.33**, PHPUnit **11.5.57**, SQLite in-memory and a disposable **noncommitted test APP_KEY**:

- `php -l` for the controller/test: **PASS**.
- Laravel Pint `--test` for both changed PHP files: **PASS**.
- Focused #3894 test: **1 test, 33 assertions, PASS**.
- Finance/Receivables regression filter `V2(ReceivablesAgingSidebarContract|FinanceEntitlementParity|ArAgingFullLedgerPagination|ReceivablesAgingMultiRoleScope)Test`: **12 tests, 128 assertions, PASS**.
- Financial safety assertions compare `enterprise_finance_journals`, `enterprise_finance_ledger_entries` and `enterprise_ar_documents` counts before/after authorization reads.

The isolated GCC copy uses its **own physical Composer vendor directory**, and `ReflectionClass` verified that `FinanceWorkbenchController` resolves to the checked-out #3894 source (not an older cloned source). Local SQLite does not substitute for mandatory GitHub MySQL/Redis install/upgrade/recovery acceptance.

## HIGH risk / acceptance gates

**REVIEW_RISK: HIGH** — Finance permission/tenant existence signaling, explicit DENY, cross-store and legal-entity isolation. A file-by-file engineering audit must cover the three changed paths and adversarial 403/404/200 behavior, AR/EN, no mutation, peer-hunk preservation and failure modes. A same-account COMMENTED review is **not** independent GitHub approval.

**Status: SOURCE_PATCH_AND_LOCAL_REGRESSION_COMPLETE / EXACT_FINAL_HEAD_CI_PENDING.** The Issue cannot close and the PR cannot merge merely from a local PASS. Check the final head for terminal **Repository Policy, V2 Isolation and Required CI Gate SUCCESS**, including applicable Laravel/Pint/PHPStan, security, AR/EN and real MySQL/Redis paths. Preserve original PR #3899 and issue scope. On failure, repair this branch and rerun all exact-head gates; no checks may be waived. Parent #2618 requires separate source-completeness review before closure; final manual/staging/browser UAT remains under #2503.

## Surfaces

- **Backend/Dashboard:** canonical Finance Workbench GET authorization read-path fix and tests; no new page, Sidebar, API or state-changing flow.
- **Customer:** NOT affected; no API/Flutter contract or B2B checkout change.
- **Driver:** NOT affected; no API/Flutter contract or B2C dispatch change.
- **Van:** NOT affected; no API/Flutter contract or B2B custody change. B2B remains VAN ONLY and B2C remains DRIVER ONLY.

No `main` merge, release/tag, production install, cPanel change or APK/ZIP publication is authorized.
