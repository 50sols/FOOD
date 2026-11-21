# V2 #3652 — Literal scoped General Ledger journal-number prefix

**Source:** P281 Finance Journals, P082 authorized View, master #2503. **Integration:** Foodex_V2 only. No main, production, release, tag, APK or cPanel promotion.

## Verified discrepancy and bounded fix

The canonical Finance Workbench Journals list resolves q through GeneralLedgerService::paginateJournals. Previously safePrefix deleted user-entered % and _ before applying SQL LIKE prefix. Literal journal numbers containing these characters could not be searched accurately; wildcard-only queries became unfiltered. This does not demonstrate SQL injection or unauthorized cross-tenant access, because actor, legal entity, role and entitlement guards already existed.

Only the journal-number **read query** changes: validated/trimmed q is encoded as a bound SQL pattern with LIKE ? ESCAPE '!', escaping !, %, _, retaining literal backslash, and appending one intentional % for prefix matching. No user-derived SQL identifier or interpolation. MySQL/MariaDB and SQLite use the same explicit escape marker. Existing finance.view, LEGAL_ENTITY finance.gl.read, organization/company/legal-entity constraints, status/fiscal-period/date filters, date/id ordering, 10–100 pagination and query string remain intact. No journal create/post/maker-checker, ledger writes, audit or migration touched.

## Placement and acceptance

- **Navigation:** existing Finance Center → Journals (PAGE_TAB in Finance Workbench), no new route, Sidebar item, form, translation or UI component. Existing localized AR/EN, RTL/LTR, responsive and server-authorized flow unchanged.
- **Backend:** GL journal read search affected. A real RefreshDatabase service test covers literal %, _, !, backslash; normal prefix/decoy exclusion; foreign organization/legal-entity isolation; draft/posted filter; no audit/journal writes. Unprivileged actor must get HTTP 403.
- **Dashboard:** existing Finance Journals read query now has literal prefix semantics, without layout or persisted-state changes.
- **Customer app:** no public API, Flutter, DTO, authentication, store/credit/checkout impact.
- **Driver app:** no API, Flutter, B2C dispatch impact.
- **Van app:** no API, Flutter, B2B route/custody impact. B2B=VAN ONLY and B2C=DRIVER ONLY unchanged.

## Verification and high-risk audit boundary

Finance data requires a HIGH-risk file-by-file technical audit: fixed column, bound parameter, escaping, exact authorization, no OR scope bypass, read-only semantics, negative tenant/role tests. Run focused and full Laravel/Pint/PHPStan/security/OpenAPI, AR/EN, MySQL/Redis and exact final-head Repository Policy, V2 Isolation, Required CI including nested terminal gate. Merge original issue PR only to Foodex_V2 after all terminal SUCCESS. Close atomic leaf as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED with PR, SHA and CI evidence; consolidated staging/manual acceptance remains on #2503.
