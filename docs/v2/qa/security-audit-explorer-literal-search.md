# V2 #3691 — literal Security Audit Explorer filters

**Parent:** #2818 P082, master #2503; previous sibling #3675/#3676 (Security
user list), already merged and **not** reimplemented.
**Destination:** Foodex_V2 ONLY. No main, release, tag, cPanel, APK, or production.

## Existing route, placement, actors and data scope

- Domain: Security / Audit. Canonical route:
  \`GET /admin/security?section=audit\`, existing Security Center Audit tab.
  Placement: **Reuse** existing view and audit table, not a new screen.
- Navigation owner: Security Center (Admin domain); existing Sidebar entry.
  Navigation type: PAGE_TAB. Parent Security Center; standard Security Center
  back-path; active section \`audit\`; existing query-string filters and
  independent \`audit_page\` paginator.
- Permission: existing server-side \`Gate::authorize('security.view')\`.
  Roleless/non-authorized users remain HTTP 403. No new tenant, user, store,
  module, entitlement, readiness or audit visibility policy.
- Surface and UI: read-only Dashboard Audit Explorer, bounded 25 rows/page.
  Existing AR/EN text, RTL/LTR, 320px+ mobile-web, tablet and desktop shell,
  keyboard/focus states, date ranges, pagination and accessibility unchanged.
  Existing actor name/email is real source data, not a typed internal user ID.

## Defect and fix

\`SecurityController::auditExplorer\` had raw \`LIKE '%'.input.'%'\`
on 5 optional filters: actor (name/email OR group), event, auditable type
and after/correlation. User-entered \`%\` and \`_\` became wildcard operators,
returning unrelated but otherwise authorized audit entries.

The five existing comparisons now use bound \`LIKE ? ESCAPE '!'\`
with **literal substring** semantics: escape user-supplied \`!\` → \`!!\`,
\`%\` → \`!%\`, \`_\` → \`!_\`, preserve typed backslash and add only
intentional outer \`%\` operators. The actor name/email disjunction remains
grouped, the filters remain AND-composed, and the date range and pagination
are unchanged. This is **not** a claim of SQL injection or a new cross-tenant
breach: the prior query already used parameter binding and \`security.view\`.

## Automated regression

Existing \`EnterpriseRbacTest\` adds a focused authenticated
\`RefreshDatabase\` + \`CoreReferenceSeeder\` feature test. Four punctuation
cases (\`%\`, \`_\`, \`!\`, backslash) each have a target and decoy audit
record and actor, and each of the four audit search fields is exercised
via real Security Center HTTP GET. Assert exactly one target record, not
a widened result. Combined actor/event/entity/correlation/date filters,
\`audit_page\` query-string retention, English/Arabic RTL, unauthorized B2B
Admin HTTP 403 and unchanged audit/user/role assignment counts are covered.
Existing user-search regression, Security Center route, audit history and
governance surfaces remain intact.

## HIGH-risk file-by-file audit and mobile classification

1. \`backend/app/Http/Controllers/Admin/SecurityController.php\`:
   existing auditExplorer read WHERE clauses only; no authorization,
   join, read scope, pagination, export, mutations or data model changed.
2. \`backend/tests/Feature/EnterpriseRbacTest.php\`:
   full-stack test positive/negative data search, denied access and side-effects.
3. \`docs/v2/qa/security-audit-explorer-literal-search.md\`:
   route, source, risk, test and impact evidence.

**Risk: HIGH** because Audit Explorer includes sensitive user and security
history. Same-account GitHub COMMENTED engineering review is *not* an
independent GitHub APPROVED review. Required terminal exact-head Policy,
V2 Isolation, fast-fail, Laravel, localization, MySQL/Redis and
\`required-ci-gate\` all must pass before merger.

**Customer:** NOT affected — no customer public API/DTO/UI change.
**Driver:** NOT affected — no Driver API, Flutter, dispatch or GPS change.
**Van:** NOT affected — no Van API, Flutter, offline or sales change.
B2B VAN ONLY / B2C DRIVER ONLY unchanged.

Final human/staging/browser UAT is deferred to #2503 after atomic
source code completion; parent remains open.

## JSON-escaped correlation repair (Laravel CI rescue)

Full Laravel validation on original head `ce0e8c1e6826e0651b9ec8af19ced6bc7798c4ce` failed **only** the new `EnterpriseRbacTest` audit-filter regression (line 230): 3979 passed, 1 skipped, 1 failed, while PHP/Pint/PHPStan, AR/EN and MySQL/Redis acceptance succeeded. The failure was reproducible specifically for backslash inside `audit_correlation`: the source `audit.after` is serialized JSON, so a typed literal `\` is stored as two consecutive backslashes. A single-backslash SQL substring cannot match that serialized JSON value, although it correctly matches the unencoded actor/event/entity columns.

The correlation-only read predicate now doubles *typed* backslashes **before** building the same bound `LIKE ? ESCAPE '!'` pattern, preserving exact literal matching against the stored JSON representation. Actor, event, entity, dates, role checks, store scope and audit records are untouched; no data mutation is introduced. The existing four-symbol authenticated HTTP regression exercises this case and is retained (not weakened). The independently tested SQLite LIKE simulation confirmed the original one-backslash query does not match JSON, and the normalized two-backslash query does; production correctness still requires terminal exact-head Laravel/MySQL CI.

Rescue commit: `cadd23dd3df05fe5dbe6579272945dbf746a95b0`; latest final source/head and CI must be reverified after this documentation update. Never merge on old-head green checks.
