# V2 #3669 — Treasury financing facilities and investments literal scoped search

Parent #2503; Treasury Workbench native financing / investments already integrated from original Treasury surface. **Foodex_V2 only.** No main, production, tags, releases, APKs or cPanel.

## Verified existing bug and product placement

`TreasuryPlanningService::safePrefix` deleted typed `%`, `_` and backslashes after max:120 input validation. `paginateFacilities` and `paginateInvestments` then ran prefix SQL LIKE over fixed columns; literal punctuation in real facility code/lender or investment code/institution/instrument type became impossible to find, and symbol-only queries silently expanded to an unfiltered list. This is search correctness, not demonstrated SQL injection or a tenant bypass.

**Reuse** canonical Finance → Treasury Workbench → Financing and Investments tabs and current page filters; `PAGE_TAB` navigation, no new Sidebar, route, controller, API or UI widgets. Preserve authorized `finance.view` and `treasury.payments.read` module entitlement, organization/company/legal_entity/store AND scope, status, ordering and bounded 10–50 pagination. Revised helper trims and uses `!` as SQL LIKE escape marker to preserve literal `%`, `_`, `!`; backslashes remain literal. Fixed-column `LIKE ? ESCAPE '!'` binds each pattern and appends one deliberate prefix wildcard. Facilities search `facility.code` and `facility.lender_name`; investments search `code`, `institution`, `instrument_type` in existing grouped OR.

## Security/negative cases and cross-app impact

`REVIEW_RISK: HIGH` because these lists contain money-sensitive, scoped Treasury records. No authority expansion, uncontrolled SQL identifiers, cross-store/company/lender leakage, finance formula/currency mutation, ledger journal, facility/investment create/edit/settle, idempotency or migrations are changed. Source data queries still constrain all four scope identifiers outside the parenthesized OR before pagination. Fail closed on missing module entitlement, 403 for unauthorized role and 404 for inaccessible source where applicable; existing Treasury Workbench security remains canonical.

| Surface | Impact / contract evidence |
| --- | --- |
| Laravel Treasury | Read-only literal search on two existing enterprise planning lists; no persistence, schema or money transition changed |
| Admin Dashboard | Existing authorized Financing/Investments list filters; AR/EN, RTL/LTR, accessible responsive UI and canonical route unchanged |
| Customer | Not affected — no Customer public API, account, invoice, pricing, checkout, Flutter or notification changes |
| Driver | Not affected — no Driver API/Flutter/collection/dispatch; B2C DRIVER ONLY unchanged |
| Van | Not affected — no Van API/Flutter/route/stock/offline; B2B VAN ONLY unchanged |

## Executable regression and CI acceptance

`backend/tests/Feature/EnterpriseTreasuryWorkbenchTest.php::test_financing_and_investment_prefixes_are_literal_and_store_scoped` uses existing `RefreshDatabase`, CoreReferenceSeeder, actual Treasury module activation, Retail finance roles, entitled store contexts, canonical `TreasuryPlanningService::createFacility` and `createInvestment` fixtures. Covers code/lender/institution/instrument search: embedded and leading literal percent/underscore/exclamation/backslash, correct normal-prefix matched decoy, foreign-store scoped exclusion, status filters, roleless direct service 403, limits 10/50, and read-only facility/investment/audit counts after fixture creation. Existing Workbench tests separately certify AR/EN page reachability, disabled module HTTP denial, financing/forecast creation and route authority. No Flutter diff, so relevant mobile jobs may be skipped by changed-area CI but are not claimed to build.

Focused: `cd backend && php artisan test --filter=test_financing_and_investment_prefixes_are_literal_and_store_scoped`. Mandatory exact final SHA workflows: Repository Policy, FOODEX V2 Isolation, Required CI with PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel, AR/EN, real MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate`. CI outcomes must be inspected rather than inferred.

## Delivery and rollback

Three-file ownership fence: `backend/app/Enterprise/Treasury/TreasuryPlanningService.php` (facilities and investments paginators + their private helper), `backend/tests/Feature/EnterpriseTreasuryWorkbenchTest.php` (one test and imports), and this new QA document. No overlapping source with active Treasury receipts #3659 or payments #3661. No data/schema migrations, so standard code revert returns previous behavior without financial backout. A same-account `COMMENTED` HIGH technical review documents source analysis, **not independent GitHub APPROVED**. Require exact-final-head required checks, lease and conflict recheck, SHA-guard merge **only** to Foodex_V2, verified integration SHA, then close #3669 `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Parent #2503 stays OPEN pending consolidated staging/manual/device acceptance.
