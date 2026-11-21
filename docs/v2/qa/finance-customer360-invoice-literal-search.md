# V2 #3649 — Customer 360 finance invoice picker literal search

Parent: #2503. Integration **Foodex_V2 ONLY**. No `main`, release, version promotion, APK, tag, cPanel, or production work.

## Source diagnosis and placement

Reuse the existing Dashboard Customer 360 → Wholesale Account & Finance → invoice selector used for authorized manual ledger entries. This is a READ-only suggestion endpoint `admin.customer-360.finance-invoices`; it is not a new route, menu, page, form, API contract or mutation. Previously `addcslashes` supplied a PHP backslash-escaped LIKE pattern to Eloquent's implicit `LIKE`. SQLite does not treat backslash as an escape without an explicit clause; runtime behavior and literal `%` / `_` / `\\` / `!` handling can vary from MySQL/MariaDB.

Use a fixed-column, bound `invoices.invoice_number LIKE ? ESCAPE '!'` with `!` → `!!`, `%` → `!%`, `_` → `!_` and unchanged backslash. Preserve only the intentional outer substring wildcards. Do not use user input as a SQL identifier or raw SQL string.

## Safety / authority contract

The existing `findVisible` / `access` / Wholesale domain / `finance.manage` or SUPER_ADMIN guards remain unchanged. The invoice query stays inside matching B2B customer, B2B channel, scoped authorized stores and authoritative account currency. Numeric `DisplayNumber::referenceId` fallback remains inside these scope predicates, and the explicitly selected invoice remains independently revalidated using the same scoped base query. Existing 20-item response limit, stable order, original invoice/ledger amount mapping and localized status labels remain unchanged. No invoice or account mutations.

## Automated acceptance

The issue-scoped `DashboardCustomer360Test` regression uses real authenticated GET JSON requests with `RefreshDatabase`, `CoreReferenceSeeder` and actual Wholesale/Retail linked customer and invoice fixtures. It requires literal `%`, `_`, `!`, and backslash queries to select only a matching authorized invoice, excludes a nonmatching wildcard decoy, foreign customer's distinct but matching literal-symbol number (respecting the global UNIQUE invoice-number constraint), and mismatched currency, validates normal query result ordering, selected-ID lookup despite a disjoint search term, AR/EN locale compatibility, Retail-role Wholesale identity invisibility (404) and no invoice/ledger writes. Existing Customer 360 tests continue to cover pagination, selected-ID forgery, authorization and manual ledger workflows. Execute full applicable Laravel, Pint, PHPStan/security, AR/EN and real MySQL/Redis deployment CI at final PR HEAD. These are acceptance requirements, not unverified claims of PASS.

## Client impact

| Client/surface | Classification | Evidence |
| --- | --- | --- |
| Dashboard | Affected internally | Existing Customer 360 finance invoice picker read query only |
| Customer mobile | Not affected | No public API, customer DTO, checkout, invoice, screens, Flutter or authentication change |
| Driver mobile | Not affected | No dispatch, collections, Driver API or Flutter change |
| Van mobile | Not affected | No B2B VAN routing, consignment, Van API or Flutter change |

Maintain B2B = VAN ONLY and B2C = DRIVER ONLY. No customer/driver/van mobile CI requirement is implied by changed-file classification; any cross-app impact discovered subsequently needs a distinct V2 issue.

## Final gate

Risk HIGH (financial account/customer data). Review all three changes; test literal search and role/tenant/money integrity; record `TECHNICAL_REVIEW: PASS|FAIL` truthfully (same-account COMMENTED is not independent peer approval). Require actual all-green Repository Policy, FOODEX V2 Isolation, full FOODEX Required CI and terminal `required-ci-gate` on exact final SHA, conflict-free base check, then SHA-guarded PR merge into Foodex_V2 only. Close #3649 only after real merged status and integration SHA, marking `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; master #2503 remains OPEN for consolidated device/staging/business acceptance.
