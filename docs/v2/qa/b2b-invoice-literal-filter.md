# V2 #3679 — Customer Wholesale B2B invoice literal search

Parent #2503 / Customer Finance #2732. **Integrate ONLY into Foodex_V2; no main, release, version tag, APK, cPanel or production.**

## Defect / canonical placement

Existing `GET /api/v1/b2b/invoices` is consumed by Customer Flutter `B2bJourneyScreen` on `/b2b/invoices`. Its authorized `B2bFinanceController::invoices` query previously used `addcslashes($search, '%_\\')` inside an implicit Eloquent SQL `LIKE` comparison. Backslash escape defaults differ across MySQL/MariaDB and SQLite, so a legitimate invoice number containing percent, underscore or backslash may not match literally. This does not establish an injection or unauthorized data leak; it is a cross-driver read-filter correctness issue.

**Reuse** the existing Customer Wholesale invoices list and existing API, no new page, route, UI token, translation key or schema. Preserve `q` max 120 and trim behavior; use existing `App\\Enterprise\\Search\\LiteralLikePattern::contains` (prefix and suffix intentional wildcards, `!`→`!!`, `%`→`!%`, `_`→`!_`, backslash literal). Query fixed `invoice_number LIKE ? ESCAPE '!'` with a bound value. Keep the original optional numeric `DisplayNumber::referenceId` exact-ID OR nested inside `ownedInvoiceQuery`.

## Source scope / security audit

`REVIEW_RISK: HIGH` — customer finance/identity and private invoices. The existing `approvedCustomer` requires authenticated, approved B2B account. `ownedInvoiceQuery` constrains `b2b_customer_id`, excludes B2C invoices, and allows only valid Wholesale stores. `from`, `to`, `store_id`, `status`, ordered pagination, summary, currencies/ledger and canonical Invoice JSON remain unchanged. The existing `b2b.finance.invoices_viewed` audit event remains intentionally emitted on reads; **do not claim audit-log counts stay unchanged**. No payment, ledger, invoice/line, role or identity mutation. Rollback is source-only; no migration or data restoration.

The original `B2bFinanceTest` `RefreshDatabase`+CoreReferenceSeeder+Sanctum contract is extended by one authenticated HTTP regression with actual approved and pending B2B account profiles. Use real invoice records containing leading and embedded literal `%`, `_`, `!`, backslash; ordinary decoys; a second foreign B2B customer; a malicious-looking Retail-store/channel invoice; numeric display reference; explicit status/date/store/page filters, invalid per_page validation 422, foreign denial and no invoice/payment mutations. Existing B2B finance settlement/PDF/account-statement tests remain.

Focused test: `cd backend && php artisan test --filter=test_approved_wholesale_invoice_search_matches_literal_symbols_without_cross_account_leakage`. Full final-head Repository Policy, V2 Isolation, Required CI with Laravel tests, PHP syntax/Pint/static/security/OpenAPI, AR/EN, real MySQL/Redis fresh/upgrade/recovery and required-ci-gate must all be terminal SUCCESS before merge. Same-account detailed COMMENTED HIGH-risk engineering audit is not independent GitHub APPROVED.

## Cross-app and UI contract

| Surface | Classification |
| --- | --- |
| Laravel API / Wholesale customer invoices | Affected — bound literal `q` matching only; response schema and ownership unchanged |
| Customer Flutter | Existing `/b2b/invoices` route forwards query parameters to `/api/v1/b2b/invoices`; result semantics corrected, no Flutter widget/DTO/navigation/auth code change or new permission; existing Customer API contract covered |
| Dashboard | Not affected — B2B Admin Finance Workbench uses independent read controller; no UI files changed |
| Driver Flutter | NOT affected — no B2C dispatch, wallet, driver collection/API contract change |
| Van Flutter | NOT affected — no B2B Van trips, remittance, custody or delivery/API contract change |

Van-only fulfillment for B2B and Driver-only for B2C unchanged. No new AR/EN strings or visual changes; current localization/accessibility tests remain valid. Manual Customer device/browser UAT stays with master #2503 and the existing Customer parity parent #2732.

## Delivery constraints

Exactly three files: `backend/app/Http/Controllers/Api/V1/B2bFinanceController.php` (only `invoices` q block + shared helper import), `backend/tests/Feature/B2bFinanceTest.php` (one regression), this doc. No overlap with the currently open Foodex_V2 PR file fences, no extra branch or PR. Use one SHA-guarded PR targeting Foodex_V2, final exact-head required checks and user/customer privacy review, verify integration SHA, then close only this atomic leaf as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with links. Do not claim complete FOODEX V2 or production release readiness.
