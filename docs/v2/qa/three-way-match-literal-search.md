# V2 #3680 — literal scoped Procurement three-way match search

Parents: #2818 (P082), #2574 (Purchase Invoices), #2707 (Procurement Stage 3), master #2503. **Only integration target: `Foodex_V2`**. No `main`, production, tag, release, APK or cPanel.

## Proven defect and bounded fix

On the isolated branch the canonical `ProcurementCompletionManager::paginateMatches` previously deleted operator-entered `%` and `_` and compared unescaped `LIKE` predicates for `invoice_number`, `supplier_invoice_number` and `supplier_code`. An operator looking for a punctuation-bearing imported reference received an incorrect or overbroad *authorized* list. This is a literal filter correctness issue, **not SQL injection or evidence of a cross-store privilege bypass**.

**Reuse → Extend:** the existing Procurement Workbench three-way match review list. Trim q (max 120), preserve typed punctuation, escape `!` → `!!`, `%` → `!%`, `_` → `!_` while leaving backslash literal; add one intentional trailing `%`. Three constant columns use bound `LIKE ? ESCAPE '!'`, and their OR remains nested under original organization/company/store AND and match-status conditions. Empty q retains existing authorized unfiltered behavior. The service still enforces procurement module state and `procurement.purchase_invoices.view`, order-descending and 10–100 pagination.

No create, post, three-way approval, review decision, finance/GL, invoice handoff, schema, queue or public API contract changes.

## Real regression

`backend/tests/Feature/EnterprisePurchaseInvoiceHandoffFoundationTest.php::test_three_way_match_list_uses_literal_invoice_and_supplier_prefixes_without_writes`

- `RefreshDatabase`, enabled procurement, scoped real actors with a posted Goods Receipt → canonical draft Purchase Invoice → canonical match synchronization. Synthetic imported-number fields cover literal prefix symbols `%`, `_`, `!`, backslash, and combined symbols in invoice number, supplier invoice reference, and supplier code.
- A second Retail store has matching punctuation but must not be disclosed. Separate roleless actor is denied with HTTP 403 at service boundary; status `pending` versus `auto_approved`, ordinary decoys, minimum/maximum page sizes and intentionally empty q are verified.
- Count snapshots verify no invoice/line/match/stock/audit/outbox mutation from read searches. The match-status fixture mutation occurs only **before** the before/after snapshot.

Focused Laravel command: `cd backend && php artisan test --filter=test_three_way_match_list_uses_literal_invoice_and_supplier_prefixes_without_writes`. Exact-final-head Required CI must include backend fast-fail (PHP/Pint/PHPStan/security/OpenAPI), full Laravel backend suite, MySQL/Redis deployment acceptance, AR/EN, Repository Policy, FOODEX V2 Isolation and terminal `required-ci-gate` SUCCESS.

## Impact matrix

| Surface | Effect |
| --- | --- |
| Backend / Dashboard Procurement | Existing authorized three-way match list query correctness; no route/menu/markup or mutation |
| Admin API | No new or altered public/mobile route; existing Dashboard controller delegates to the service |
| Customer Flutter | Not affected: no customer auth, commerce, pricing or order API shape changes |
| Driver Flutter | Not affected: B2C Driver delivery, wallet and dispatch unchanged |
| Van Flutter | Not affected: B2B Van visits, custody, routing and offline unchanged |

AR/EN, RTL/LTR, accessibility and responsive behavior remain as currently implemented because this patch changes no UI strings or layout. B2B VAN ONLY / B2C DRIVER ONLY remain enforced.

## Ownership and acceptance

**REVIEW_RISK: HIGH** — procurement financial document read and tenant visibility. Strictly issue-owned code hunk `paginateMatches` and one existing Foundation test plus this QA doc. In-flight #3677/PR #3678 changes the same PHP file **only within `paginateContracts`**, a disjoint method, with a different test file; preserve their work, examine diffs again before merge and reconcile ancestry non-force if needed.

A same-account `COMMENTED` technical audit is an engineering review, **not independent `APPROVED`**. Exact final SHA, all mandatory CI and source acceptance must be verified before SHA-guarded squash into `Foodex_V2`, followed by proof of integration and atomic leaf closure `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Parent #2818, #2574 and master #2503 stay OPEN for broader source and human/staging acceptance. No main/prod/release action.
