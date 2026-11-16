# V2 #3664 — Finance Opening Balances literal batch-number prefix search

Parent #2503. Target **Foodex_V2 ONLY**. No main, tag, release, APK, cPanel or production.

## Defect and placement

Canonical Finance Workbench → Opening Balances read-only list `OpeningBalanceService::paginate` previously removed user-entered `%` and `_` via `safePrefix` then used implicit `batch_number LIKE $term.'%'`. `OB_%` was silently widened to `OB`; symbol-only input became an unfiltered authorized list. Backslashes could also behave inconsistently across SQLite/MySQL. This is literal search correctness, **not** proof of SQL injection or tenant bypass.

Reuse the existing Finance Workbench list. Retain `q` max:120 and whitespace trim, preserve symbols, bind `batch_number LIKE ? ESCAPE '!'` on a fixed column. Escape `!` → `!!`, `%` → `!%`, `_` → `!_` and leave backslash literal, with only one deliberate final prefix `%`. Empty query does not add a filter. No new UI route, navigation or translation string.

## Financial authority and rollback

`REVIEW_RISK: HIGH` — finance/opening-balance scoped read. Preserve existing `finance.view` via `EnterpriseScopeAuthorizer`, Finance `finance.opening_balances.read` LEGAL_ENTITY capability, scoped `batchQuery` organization/company/legal-entity predicates, date/fiscal period/status filters, 10–100 pagination, entry-date/ID sort. Do not change account postings, journal creation, maker/checker approval, money precision, mutations, migration or audit. Normal code revert is possible; no data rollback required.

## Tests

The new case in existing `backend/tests/Feature/EnterpriseOpeningBalanceFoundationTest.php` uses its authentic Finance lifecycle/entitlement fixtures, Retail Finance actor, and two separate organization scopes. It creates valid synthetic draft batch rows matching its existing bounded-read fixture contract, plus a foreign real draft, covering literal %, _, !, backslash at leading and internal positions, ordinary wildcard decoy, cross-company/legal-entity isolation, invalid foreign fiscal period, status/date filters, roleless 403, min pagination, and no write to batches/audit/journals/ledger during queries. Existing tests separately certify posted GL lifecycle and no-entitlement denial.

Focused command: `cd backend && php artisan test --filter=test_opening_balance_batch_prefix_search_is_literal_scoped_and_read_only`. Require full final-head Laravel, PHP syntax/Pint/static/security, AR/EN and real MySQL/Redis acceptance CI, plus terminal `required-ci-gate`.

## Surface matrix

| Surface | Impact |
| --- | --- |
| Backend Finance | Only authorized batch-number read query |
| Dashboard | Existing Opening Balances Workbench list, existing AR/EN and RTL/LTR |
| Customer | Not affected — no API, DTO, auth, Flutter or storefront change |
| Driver | Not affected — no B2C dispatch, wallet, API or Flutter change |
| Van | Not affected — no B2B Van stock, custody, offline replay, API or Flutter change |

B2B=VAN ONLY, B2C=DRIVER ONLY remain unchanged.

## Merge gate

Exactly one issue-scoped PR on Foodex_V2, no overlapping worker fences. Record file-by-file HIGH engineering `TECHNICAL_REVIEW: PASS` transparently as same-account COMMENTED audit (not independent GitHub APPROVED), with negative proof and CI URLs. Require terminal SUCCESS on **all** required workflows at exact final PR HEAD, then SHA-guard squash merge into Foodex_V2, verify integration SHA, close only #3664 as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED and retain master #2503 for later manual/staging/business acceptance. No main promotion or production deployment.
