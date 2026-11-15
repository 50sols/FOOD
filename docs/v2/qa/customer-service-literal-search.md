# V2 #3643 — literal Customer Service case and customer lookup

Source P082 / parents #2818, #2503. **Foodex_V2 only**; no main, release, APK, tag, cPanel or production.

## Defect / bounded fix
Existing `CustomerServiceCaseManager::customerOptions` and `::applyCaseFilters` used parameterized LIKE with implicit backslash escaping (`addcslashes`). SQLite and MySQL/MariaDB SQL modes interpret backslash differently, so a typed literal `%` or `_` may mismatch or broaden results. The unchanged two filters now build a bound literal substring with `str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q)` and explicit `LIKE ? ESCAPE '!'` across their fixed internal columns. Literal backslash remains literal. No SQL identifier or predicate sourced from client input.

## Existing behavior retained
REUSE existing `admin.erp.customer-service` Cases/Customer picker, no new UI, route, Sidebar, copy, schema, permission or mutation. Preserve viewStoreIds, visibleCustomersQuery, caseListQuery, role/store B2B/B2C gates, status, assignment, SLA, pagination and query. Existing AR/EN RTL/LTR responsive Dashboard unchanged.

## Automated tests
`CustomerServiceWorkspaceTest.php` append-only authenticated RefreshDatabase HTTP scenarios: Retail Store A/B, percent/underscore/exclamation literals and lookalikes, backslash, Arabic name/subject, missing term, roleless 403, foreign store 404, no GET customer/case/audit mutation, 26-row/25-per-page case grid and query/status/store preservation. Existing case create/update/note/feedback and authorization tests retained.

## Impact matrix
- Backend: affected, read-only query filter only.
- Dashboard: affected, same case grid/customer selector search results.
- Customer: public API/DTO and Flutter unaffected.
- Driver: dispatch/collection API and Flutter unaffected.
- Van: trips/visits/finance API and Flutter unaffected.
No impact on B2B VAN ONLY / B2C DRIVER ONLY.

Risk HIGH: personal data and store scope. One original PR only in Foodex_V2; full Laravel/Pint/PHPStan/security/OpenAPI/AR-EN/MySQL CI and explicit owner-approved one-account technical audit before exact-head SHA-guard merge. Human/staging UAT deferred to #2503; code completion not certified until CI and merge evidence.
