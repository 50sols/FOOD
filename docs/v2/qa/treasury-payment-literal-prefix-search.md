# V2 #3661 — Treasury Payments literal number/reference prefix search

Parent #2503. Target integration **Foodex_V2 ONLY**. No main, tag, release, APK, cPanel or production deployment.

## Source defect and placement

The canonical Treasury Workbench → Payments read-list `TreasuryPaymentService::paginatePayments` previously deleted operator-entered `%`, `_` and backslashes using `safePrefix` and then ran implicit SQL LIKE on payment_number OR reference. Thus `PAY_%` became `PAY`, and punctuation-only queries became unfiltered pages. This is a literal-search correctness defect, not proof of SQL injection or tenant bypass.

**Reuse** the existing Dashboard Treasury Payments list; no new route/sidebar/page/API. Preserve existing nullable `q` max 120 validation. Keep `q` trimmed but literal, escape `!`→`!!`, `%`→`!%`, `_`→`!_`, leave backslash literal, and append exactly one intended trailing prefix wildcard. Use fixed identifier and bound `payment_number LIKE ? ESCAPE '!'` OR `reference LIKE ? ESCAPE '!'`, retaining the existing parenthesized OR under all AND scope/filters. Empty q still skips the search condition.

## Permission, finance and scope contract

The authoritative `authorizedScope` enforces existing Treasury module `treasury.payments.read` STORE entitlement plus `finance.view` effective scope. `paymentQuery` stays AND-restricted by organization, company and store. Existing status, target type and date filters, sort, 10–100 paginator and workflow remain unchanged. No mutations, currency rounding changes, maker/checker bypass, idempotency changes, migration, money write or stock movement.

`REVIEW_RISK: HIGH` — financial record visibility and scope. Require source/abuse review for SQL wildcard broadening, parenthesized OR, foreign-store leakage, roleless denial and absence of payment, audit or outbox writes during search. Standard code revert is available; no data rollback needed.

## Real automated regression

Extend `backend/tests/Feature/Enterprise/EnterpriseTreasuryPaymentFoundationTest.php` within its existing `RefreshDatabase`, seeded roles, real Treasury module lifecycle, Retail store/enterprise scope and cashbox fixtures. Create authorized and foreign-store payment drafts with distinct literal %, _, !, backslash values in payment numbers and references. Verify leading/internal symbols, decoys, normal prefix, reference-only match and foreign exclusion, status/target/date filters, min page cap, roleless 403, and read-only counts for payments, audit and outbox.

Focused test: `cd backend && php artisan test --filter=test_payment_number_and_reference_prefix_search_are_literal_scoped_and_read_only`.

## Surface impact matrix

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend Treasury | Affected | Existing read-filter literal matching only |
| Dashboard | Affected read-only | Reuses existing Treasury Payments list and AR/EN RTL/LTR components |
| Customer Flutter | Not affected | No public API, auth, customer financial data endpoints, DTO or Flutter change |
| Driver Flutter | Not affected | No B2C dispatch, wallet, collection, endpoint or app change |
| Van Flutter | Not affected | No B2B custody, route, remittance, endpoint or app change |

B2B = VAN ONLY; B2C = DRIVER ONLY remain intact. Flutter jobs may be skipped by CI's unchanged-area matrix only if no app/runtime contract impact is found.

## Acceptance

Require file-by-file same-account `TECHNICAL_REVIEW: PASS` (not independent APPROVED), full exact-final-head Repository Policy, V2 Isolation, Required CI child jobs including Laravel/Pint/PHPStan/security/AR-EN/MySQL/Redis and terminal `required-ci-gate` SUCCESS. One original Issue/branch/PR. SHA-guarded merge into Foodex_V2 only, then close as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with PR/CI/SHAs; master #2503 retains final human/browser/staging UAT.
