# V2 #3651 — literal Purchase Request number-prefix filtering

Parent P082 #2818 / ERP Stage 3 #2707 / master #2503. Integration **Foodex_V2 ONLY**, never main, tags, releases or production.

## Verified source defect and canonical placement

`PurchaseRequestManager::paginate` previously discarded literal `%` and `_` typed into the `q` search. Thus `REQ_%` silently became `REQ` and searched other numbers; all-symbol input unintentionally returned every scoped request. This is search correctness, **not** evidence of SQL injection or a cross-store bypass.

Reuse the existing authorized Procurement Workbench Purchase Requests list and search. There are no new routes, menu items, pages, permissions, API responses, write operations, business fields or strings. Input `q` remains validated to 120 characters and trimmed; bound, fixed-column `request_number LIKE ? ESCAPE '!'` escapes `!`, `%` and `_` and appends exactly one intentional prefix wildcard. Backslash remains a literal character across SQLite and MySQL/MariaDB. Existing status filter, max 10–100 pagination, descending row order and scope from `authorizedScope` and `visibleQuery` are unchanged.

## Risk, authorization and client impact

- `REVIEW_RISK: HIGH`: the lookup is a procurement-privileged, tenant-scoped record read. The query change is exclusively grouped within the **preexisting** authorized scope; it adds neither a dynamic SQL identifier nor a naked OR branch. A user without `procurement.purchase_requests.view` remains denied 403. The selected store/company/legal entity and existing entitlement guards are retained. Supplier ownership, maker/checker approval and audit writes are untouched.
- **Dashboard:** Existing Procurement Workbench list search only. Arabic/English RTL/LTR display and responsive layout reuse the currently shipped view; no translation change.
- **Customer app:** NOT affected — no public customer API, DTO, login, order or Flutter change.
- **Driver app:** NOT affected — no B2C dispatch, wallet, delivery or Flutter change.
- **Van app:** NOT affected — no B2B route, consignment, stock, trip, API or Flutter change.
- B2B = VAN ONLY; B2C = DRIVER ONLY remain unchanged.

## Automated regression and acceptance

Add one `RefreshDatabase` test to the existing `EnterprisePurchaseRequestFoundationTest`, reusing its actual scoped retail actor/permission and enterprise hierarchy fixtures. Create authorized requests, ordinary decoy and separate-store request; assign **unique** artificial request numbers containing `%`, `_`, `!` and backslash. Require literal symbol-positive/prefix-negative matching, ordinary prefix search, a separately authorized foreign-store result, status filter, minimum pagination and roleless 403. The test counts request and audit rows after setup and checks that lookup reads make no writes. All fixture numbers remain globally distinct to respect unique database constraints.

Applicable focused command: `cd backend && php artisan test --filter=test_purchase_request_number_prefix_search_is_literal_and_store_scoped`. Mandatory exact final GitHub HEAD: Repository Policy, V2 Isolation, Required CI (backend PHP syntax, Pint, PHPStan, Composer security, AR/EN localization, real MySQL/Redis deployment acceptance and full Laravel suite) **all SUCCESS**, including nested `required-ci-gate`. No Flutter changes requiring an app-specific analysis job; existing CI changed-file classification still applies.

## Review/closure rules

Record a file-by-file HIGH-risk engineering audit of `PurchaseRequestManager.php`, the single added Laravel test and this QA note, checking SQL injection/wildcard behavior, tenant leakage, unauthorized reads, changed-field/no-write guarantees and rollback (no schema/DB mutations by this PR). A same-account COMMENTED audit is not independent GitHub APPROVED. Recheck original issue lease, single PR and branch, current Foodex_V2 head/overlap, source completeness and all exact-final-head required checks. Only then SHA-guard merge **Foodex_V2**, verify integration SHA and close issue #3651 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Final staging/manual business acceptance remains with #2503.
