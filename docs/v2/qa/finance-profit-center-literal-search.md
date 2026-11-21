# FOODEX V2 — #3641 Finance Profit Centers literal prefix search

## Authority, placement and scope

- Source P082 `View/Search`, Finance Center #2610, ERP Stage 5 #2709, master #2503. Integration **Foodex_V2 only**. No main, production, release, tags, cPanel or APK.
- **REUSE** `ERP → Finance Center → Profit Centers` existing workbench tab; `admin.erp.finance.index?tab=profit_centers&q=...` and its horizontal navigation, AR/EN labels and responsive RTL/LTR form remain unchanged. No new route, Sidebar item, DTO, translation key, design primitive or screen.
- Actor: authorized Finance viewer on an active legal-entity context with `finance.view` and `finance.foundation.read` entitlement. Finance module enabled/readiness guards, multi-organization/company/legal-entity scoping and page-level `foundation_read` gate are unchanged. Existing `FinanceWorkbenchTest` covers page access, roles, English/Arabic and responsive contract; #3212 PR owns that file so this issue does NOT edit it.

## Verified defect and exact source correction

The existing `FinancePlanningService::paginateProfitCenters` removed typed `%` and `_` from `q`, meaning a literal underscore in a profit-center code or punctuation in its Arabic/English name could not be searched reliably and a modified term might match unrelated records.

Keep trim, 120-character validation, status (active/inactive), prefix-match semantics, `code` ordering, pagination 10..100 and `withQueryString`. Instead of removing user characters, escape `!` → `!!`, `%` → `!%`, `_` → `!_`; append the *intended* SQL suffix wildcard. Bind the exact resulting pattern into the two **fixed** predicates `code LIKE ? ESCAPE '!'` and `name LIKE ? ESCAPE '!'`. SQL column identifiers and tenant scope are not interpolated from caller input. Backslash remains literal on both SQLite and MySQL/MariaDB, even with alternate MySQL SQL modes. This is search accuracy and portability, not an observed injection or tenant escalation.

## Regression and no-write evidence

`backend/tests/Feature/EnterpriseFinanceFoundationTest.php` extends its existing `RefreshDatabase` finance data-scope/entitlement fixture with a new focused method verifying:

- Literal code prefix `PC_` vs misleading `PCX`, literal `%_!`, individual `%`, `_`, `!`, backslash and quoted input; ordinary English and Arabic names
- Active/inactive status filtering, stable exact IDs and paginator total, foreign organization/legal-entity exclusion and fixed `finance.view`/module entitlement 403 denials
- Profit center and audit row counts unchanged across successful reads

The full existing Finance Workbench HTTP/AR-EN tests remain mandatory and must pass on final source HEAD, alongside Backend fast-fail PHP/Pint/PHPStan/security/OpenAPI and actual MySQL/Redis CI.

## Surface impact and release gate

| Surface | Impact |
| --- | --- |
| Backend ERP Finance | Single read-only, scoped `paginateProfitCenters` prefix-filter correctness fix; no mutations or ledger changes |
| Dashboard | Existing Finance Center Profit Centers tab reuses same GET filter/form/table and AR/EN RTL/LTR layout; no visual or navigation change |
| Customer Flutter | No API, auth, finance DTO, pricing, checkout or app code change |
| Driver Flutter | No app/API/collection/settlement workflow change |
| Van Flutter | No app/API/custody/trip settlement change; B2B VAN ONLY and B2C DRIVER ONLY preserved |

One issue, `fix/3641-v2-finance-profit-centers-literal-search` branch and one PR targeting **Foodex_V2 only**, exactly three issue-owned paths. High-risk Finance legal-entity query: **qualified independent security/source review APPROVED required**; do not self-approve or bypass ruleset. Before merge: terminal final exact HEAD success from Repository Policy, V2 Isolation and Required CI including full Laravel, AR/EN and MySQL/Redis. Then validate base/head, guarded merge into Foodex_V2, record source/integration SHA, and close #3641 only as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`, tracking final UAT on #2503. No release or production claims.
