# V2 #3659 — Literal, store-scoped Treasury receipt/reference prefix search

V2 parent #2503, authorized Treasury Receipts read in existing Finance/Treasury Workbench, `Foodex_V2` ONLY. No main, production, releases, tags, cPanel or APK publication.

## Verified defect and smallest placement

`TreasuryReceiptService::paginateReceipts` validated operator `q` to 120 characters and then `safePrefix` deleted `%`, `_` and literal backslashes. A query consisting only of those characters became an unfiltered list, and a legitimate reference or receipt number containing such characters could not be retrieved. This is a scoped read-filter correctness flaw, **not** evidence of SQL injection or an observed RBAC bypass.

Reuse the existing authorized Treasury Receipts Workbench list, `PAGE_TAB` and its current filters; no second menu, route, controller, API or new screen. The revised private helper trims and escapes `!` to `!!`, `%` to `!%`, and `_` to `!_`; it retains typed backslash and appends exactly one intentional prefix wildcard to a fixed-column, parameter-bound `LIKE ? ESCAPE '!'` predicate for both `receipt_number` and `reference` inside the original grouped OR. The empty query retains its preexisting intentionally unfiltered behavior.

## Authority, negative scope and effects

Risk: `REVIEW_RISK: HIGH` (Treasury source, tenancy, financial read). `authorizedScope` continues to check `treasury.receipts.read` entitlement and `finance.view` authorization before any data query. The same `receiptQuery` remains conjunctively scoped by organization/company/store, regardless of OR search terms. Status, target type, value-date filters and descending date/ID order, paginator 10–100, privacy, maker/checker, posting/cancellation, ledger and audit logic do not change. Parameter binding fixes values, not a user-controlled SQL column. This fixes search semantics only, not authority.

| Surface | Impact |
| --- | --- |
| Laravel Treasury | Literal prefix predicate of the existing authorized receipts listing only |
| Dashboard | Existing Treasury Receipts list, filters, AR/EN RTL/LTR and responsive layout unchanged |
| Customer | Not affected: no Customer API/identity/order/Flutter or financial snapshot delta |
| Driver | Not affected: no Driver API, B2C settlement/wallet, dispatch or Flutter change; B2C DRIVER ONLY intact |
| Van | Not affected: no Van API, B2B collection, custody, route, offline replay or Flutter change; B2B VAN ONLY intact |

## Executable acceptance

`backend/tests/Feature/Enterprise/EnterpriseTreasuryReceiptFoundationTest.php::test_receipt_number_and_reference_literal_prefix_are_store_scoped_and_read_only` uses real `RefreshDatabase`, `CoreReferenceSeeder`, Treasury module lifecycle/store entitlement, the existing Finance actor and cashbox fixtures, posted-policy-safe draft receipts, foreign organizational scope and parameter-bound service pagination.

Asserts number and reference literal `%`, `_`, `!`, backslash, normal prefix, decoy, leading-symbol-only input, foreign store/organization exclusion, status/target/date filtering, page bounds and roleless 403. Database receipts, audit and integration/outbox counts must remain unchanged by read operations. Existing suite separately tests disabled/unentitled Treasury 403 with no receipt query, maker/approver SoD, idempotent transaction and cancellation, cashbox visibility and financial event provenance.

Focused: `cd backend && php artisan test --filter=test_receipt_number_and_reference_literal_prefix_are_store_scoped_and_read_only`. Acceptance also requires exact-final-head Repository Policy, FOODEX V2 Isolation Gate, full Required CI including PHP Syntax/Pint/PHPStan/Security/OpenAPI, Laravel, AR/EN, MySQL/Redis production-like isolated upgrade/recovery and terminal `required-ci-gate`. CI results are never presumed until run completion.

## Recovery, file fence and close gate

Files owned are exactly `backend/app/Enterprise/Treasury/TreasuryReceiptService.php` (paginate + own helper), `backend/tests/Feature/Enterprise/EnterpriseTreasuryReceiptFoundationTest.php` (one test) and this new QA note. No migration/dependency or persistent data write; safe source revert available. Do not change any sibling Finance/Treasury files, particularly active #3661 Treasury Payments.

A same-account `COMMENTED` file-by-file technical audit is not an independent GitHub `APPROVED`. Revalidate current lease, exact final head required CI, mergeability, and base=`Foodex_V2` before guarded integration. Only close #3659 after verified `MERGED` and `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with SHA and CI links. Master #2503 retains consolidated business/staging/device acceptance.
