# V2 #3660 — Literal, scoped purchase-return number prefix search

Source P243 original #2573 already integrated, P082 authorization #2818, V2 master #2503. Integration **Foodex_V2 ONLY**. No main, tags, releases, APK publication, cPanel or production.

## Defect, scope and placement

`PurchaseReturnManager::paginate` removed user-entered `%` and `_` from validated `q` before performing a prefix SQL LIKE comparison. This silently widened searches such as `RTN_%` into `RTN`, and wildcard-only input became unfiltered. This is a correctness flaw on an already authorized list, **not** evidence of SQL injection or a tenant bypass.

Reuse existing Procurement & Inventory → Purchase Returns tab in the canonical Procurement Workbench, including the existing record detail path. Placement: existing `PAGE_TAB` with read-only list filtering; no additional Sidebar item, route, API or new GUI is necessary. Validated 120-character `q` is trimmed but otherwise preserved; a fixed `return_number LIKE ? ESCAPE '!'` binds a pattern with literal `!`, `%`, `_` (escaped as `!!`, `!%`, `!_`), unchanged backslash, and exactly one intentional trailing prefix wildcard. Empty query remains unfiltered by design.

Preserve `procurement.purchase_returns.view` and Procurement module enabled/certification gates, authoritative `authorizedScope` and `visibleQuery` for organization/company/store, postable/readonly boundaries, `status` and `goods_receipt_id` filters, descending ID sort and pagination limits 10–100. No mutations, financial journals, stock adjustments, supplier records, migrations or schema changes.

## Security and tenant impact matrix

| Surface | Effect / evidence |
| --- | --- |
| Laravel Procurement | Only the existing, fixed-column, parameter-bound return-number read predicate changes |
| Admin Dashboard | Existing authorized Purchase Returns list and filters; current AR/EN, RTL/LTR, responsive layout and accessibility unchanged |
| Customer | NOT affected: no Customer API, auth, payload, invoice, Flutter or checkout delta |
| Driver | NOT affected: no Driver API, B2C dispatch, wallet, notification or Flutter delta; B2C DRIVER ONLY retained |
| Van | NOT affected: no Van API, route, custody, stock, offline replay or Flutter delta; B2B VAN ONLY retained |

`REVIEW_RISK: HIGH` for possible store-scoped procurement data disclosure. The literal pattern appears inside the existing scoped query; a user-entered value cannot add a column or an OR bypass. Existing `procurement.purchase_returns.view` 403 and disabled-module 404 must remain fail-closed. Same-account technical audit must not be represented as independent GitHub APPROVED.

## Executable regression and negative cases

`backend/tests/Feature/EnterprisePurchaseReturnFoundationTest.php::test_purchase_return_number_prefix_search_is_literal_scoped_and_read_only` uses the existing real `RefreshDatabase`, Procurement enabled lifecycle, authorized Retail roles/stores, posted GRN → draft-return creation fixtures, distinct synthetic return numbers and audit history.

Checks: ordinary prefix and false-positive decoy; literal underscore/percent/bang/backslash in the same prefix and as the leading search character; foreign-store matching return excluded, other receipt/status filters; page bounds 10/100; roleless 403; list reads do not mutate purchase returns, audit logs or stock movements. Existing tests separately cover actual return posting/idempotent inventory reversal, module-disabled read and detail isolation.

Focused: `cd backend && php artisan test --filter=test_purchase_return_number_prefix_search_is_literal_scoped_and_read_only`. Required acceptance also needs exact-final-head Repository Policy, FOODEX V2 Isolation, Required CI terminal `required-ci-gate` including full Laravel/PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN localization, real MySQL/Redis deployment acceptance where applicable.

## Delivery and recovery

Files: `backend/app/Enterprise/Procurement/PurchaseReturnManager.php` (paginate only), `backend/tests/Feature/EnterprisePurchaseReturnFoundationTest.php` (one regression), this QA note. No new dependencies or data migrations, and normal source revert can restore old behavior without destructive rollback. Issue-scoped original PR must target only `Foodex_V2`; review the current head and worker leases before a SHA-guarded merge. Close #3660 `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only after verified exact-head full CI, technical audit, and merged integration SHA. The consolidated manual/staging acceptance belongs to open #2503.
