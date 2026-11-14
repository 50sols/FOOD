# V2 #3657 — Goods Receipt literal number-prefix search

Source P082 #2818, procurement Stage 3 #2707, master #2503. Integration **Foodex_V2 ONLY**. No main/release/tags/APK/cPanel/production.

## Source defect and placement

Existing `GoodsReceiptManager::paginate` removed `%` and `_` typed into `q` before executing `receipt_number LIKE $term.'%'`, thereby widening symbol-containing prefixes and treating all-symbol search as an empty/unfiltered result. This is a read-filter correctness issue, not a claim of SQL injection or an observed tenant bypass.

**Reuse** the existing Procurement Workbench → Goods Receipts list and filtering UI, not a duplicate Sidebar/page/route. `q` remains validated as 120-character nullable string. Implement fixed-column bound `receipt_number LIKE ? ESCAPE '!'` with `!` → `!!`, `%` → `!%`, `_` → `!_`, unchanged literal backslash, and one intentional trailing prefix `%`. No user-controlled raw SQL identifier. The original `authorizedScope`/`visibleQuery` store, organization and company constraints, status/order filter, 10–100 pagination, ordering and view permission remain untouched.

## Scope, security, API and client parity

`REVIEW_RISK: HIGH`: scoped procurement records and potential unauthorized read. The patch alters only matching in an existing AND-scoped read, not business writes. `procurement.goods_receipts.view`, enabled Procurement module, store/company/legal entity, maker/checker, stock movements, posting, cancellation, audit and acceptance controls remain server-authoritative. No schema/migrations or backups changed.

| Surface | Impact |
| --- | --- |
| Laravel Procurement domain | Literal prefix query behavior only |
| Dashboard | Reuses Goods Receipts Workbench list, existing AR/EN RTL/LTR and responsive layout |
| Customer | Not affected — no public API, product, identity, checkout, DTO or Flutter changes |
| Driver | Not affected — B2C DRIVER ONLY, wallet/dispatch/API unchanged |
| Van | Not affected — B2B VAN ONLY, custody/route/sales/API unchanged |

## Automated acceptance

The issue-scoped `EnterpriseGoodsReceiptFoundationTest` addition reuses real `RefreshDatabase`, the existing Procurement module lifecycle, role/store/enterprise context and issued PO→GRN fixture. Distinct receipt-number fixtures contain literal wildcard/escape/backslash symbols at both internal and leading positions, plus ordinary decoy and foreign-store receipt. It verifies literal prefix-only matches, not substring matches; symbol-only queries never expand to every row; status and purchase-order filters, minimum pagination, scope isolation and roleless denial. Read operations are asserted not to change GRN, audit or stock-movement counts.

Focused command: `cd backend && php artisan test --filter=test_grn_number_prefix_search_is_literal_scoped_and_read_only`. Required GitHub exact-final-head checks: Repository Policy, FOODEX V2 Isolation, full Required CI terminal `required-ci-gate`, Laravel suite/PHP syntax/Pint/static/security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery where selected. No Flutter source change; existing changed-area classification applies.

## Final technical review and merge requirements

File-by-file HIGH engineering audit for `GoodsReceiptManager.php`, the existing feature test and this QA note. Confirm source authorization and tenant/store boundaries, literal wildcard behavior, no stock/audit/money mutation, and zero collision with other worker file fences. This read-only patch has no irreversible migration or data rollback; ordinary code revert is possible. Same-account COMMENTED technical review is **not** independent GitHub APPROVED. PR must target `Foodex_V2` and be SHA-guard merged only on exact-final-head mandatory CI SUCCESS; close #3657 only with verified merged status, tested and integration SHAs, CI URLs and `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Parent #2503 retains final human/staging UAT.
