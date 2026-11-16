# V2 #3666 — Finance Foundation four literal code/name prefix lists

Parent #2503. Integration **Foodex_V2 ONLY**, never main, release, tag, APK, cPanel or production.

## Source and canonical surfaces

`FinanceFoundationService` provided four Finance Workbench read lists with the same destructive `safePrefix`: Chart of Accounts, Cost Centers, Fiscal Years and Fiscal Periods. It deleted typed `%` and `_`, then used implicit SQL `LIKE` on `code` OR `name`. A `COST_%` query could become broad `COST`; symbol-only input could show an unfiltered authorized list. This is literal search correctness, not proven SQL injection or scope bypass. Previous #3641 Profit Centers and #3647 Assets fixes are in separate source services and are not reproduced here.

Reuse existing UI and routes; preserve validated `q` max 120 and all filters. In four `paginate*` methods, trim without stripping symbols; bind fixed-column `code LIKE ? ESCAPE '!'` OR `name LIKE ? ESCAPE '!'`, with `!`→`!!`, `%`→`!%`, `_`→`!_`, unchanged literal backslash and one intentional trailing prefix wildcard. Parenthesized OR remains inside the existing permission-scoped AND predicates, not a top-level scope bypass. No new Sidebar nodes.

## Authority, data safety, rollback

`REVIEW_RISK: HIGH`: finance organization/legal-entity data. Existing `finance.view`, `finance.foundation.read` module entitlement, exact enterprise organization/company/legal entity checks, action permissions, status/type/parent/fiscal_year filters, sorting and 10–100 pagination are unchanged. Create/update accounts, cost centers, fiscal year/period close/reopen, maker/checker, journal/currency/financial logic, user data, schema and audit writes are untouched. A pure source revert is possible without data rollback.

## Source regression

New `EnterpriseFinanceFoundationTest::test_four_finance_foundation_prefix_lists_match_literal_symbols_and_scope` reuses real Finance lifecycle, RETAIL enterprise stores and a separate foreign organization; creates canonical account, cost center, fiscal year and fiscal period through existing FinanceFoundationService methods. Verifies literal %, _, ! and backslash, leading symbols, normal decoys and source sort, foreign scope exclusion, roleless 403, original filters and no record/audit mutation from list reads. Existing tests preserve disabled-module denial, entitlement, cross-company references, fiscal overlap/close validation and finance migration.

Focused command: `cd backend && php artisan test --filter=test_four_finance_foundation_prefix_lists_match_literal_symbols_and_scope`. Also require the full exact-final-head Laravel, Pint, PHPStan/security, AR/EN, MySQL/Redis and terminal Required CI checks.

## Impact matrix

| Component | Impact |
| --- | --- |
| Laravel Finance Foundation | Four authorized list search filters only |
| Dashboard | Existing Chart of Accounts, Cost Centers, Fiscal Years, Fiscal Periods read lists, existing AR/EN RTL/LTR/responsive components |
| Customer | NOT affected — no API, DTO, user identity, checkout or Flutter changes |
| Driver | NOT affected — no B2C dispatch, finance collection API or Flutter changes |
| Van | NOT affected — no B2B custody/route/settlement API or Flutter changes |

B2B=VAN ONLY and B2C=DRIVER ONLY unchanged.

## Completion gate

Exactly one issue-scoped original PR targeting Foodex_V2, no overlapping worker fence. HIGH technical file-by-file same-account COMMENTED audit (not independent GitHub APPROVED), negative/rollback/scope check, all three exact-final-head Repository Policy, FOODEX V2 Isolation and Required CI Gate (including nested children) terminal SUCCESS before SHA-guard merge. Verify integration SHA, close only CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with test/merge/run URLs, leave #2503 open for final browser/staging/business UAT. No main or production.
