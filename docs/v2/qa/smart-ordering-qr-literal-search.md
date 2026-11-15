# V2 #3625 — Scoped Smart Ordering QR metadata literal search

**Issue #3625** · Source P082 authorized View/Search · Parent #2818 · Master #2503 · **Integration: `Foodex_V2` only**.

## Source defect and bounded change

The existing Restaurant SaaS QR Management list `admin.smart-ordering.qr.index` filters at most 25 authorized `SmartOrderingQrCode` records. Its `q` filter previously escaped `%` and `_` using implicit backslashes but left the SQL `ESCAPE` clause unspecified and did not protect literal backslash; SQLite and MySQL/MariaDB can match user text differently. This is an accuracy bug inside existing authorized scope, not an asserted cross-tenant leak or SQL injection.

The five existing fixed (developer-owned) columns `label`, `campaign`, `source`, `branch_reference`, and `table_reference` now receive one parameter-bound `LIKE ? ESCAPE '!'` pattern using `str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim(q))`. This treats `!`, `%`, `_` and backslash as literal search text. No identifiers come from users. All remaining query predicates, 25-row pagination, stable order, status/type selection, QR tokens, public redirects, attribution, promotion scope and QR mutations stay unchanged.

## Navigation / access / UX

Reuse existing Restaurant SaaS → Smart Ordering QR Manager, without any new route, sidebar, input, label, widget or DTO. Actor: active B2C Restaurant store manager; `smart_ordering.qr.view` permission, RestaurantSaasStoreAccess, explicit `store_id` 404, entitlement and module readiness remain authoritative. Existing AR/EN RTL/LTR responsive listing preserved, no new strings. Search is a legitimate free-text input, not an internal-ID control.

## Real HTTP verification

`backend/tests/Feature/V2SmartOrderingQrLiteralSearchTest.php` uses `RefreshDatabase`, real enabled Restaurant store fixtures, authorized B2C store role, existing QR manager and GET route. Assertions cover:
- Literal `%`, `_`, `!` and backslash in label/source/campaign/branch/table columns, plus ordinary Arabic and English, exact IDs and wildcard-lookalike exclusion.
- B2C store A/B isolation and 404 for explicitly requested foreign store, roleless and disabled Restaurant entitlement 403.
- Existing Arabic RTL and English LTR rendering; read-only QR, token and audit counts.

Reference `SmartOrderingQrTest` supplies full QR lifecycle, redirect, attribution and manager validation. The new test introduces no new QR write path.

## Five-surface impact matrix

| Surface | Impact |
| --- | --- |
| Backend | Same authorized read query matching only; no schema, token, routing or QR mutation |
| Dashboard | Existing search returns literal matches, no UI markup/strings/nav changes |
| Customer | NOT affected: no public QR redirect, app API/DTO or Flutter changes |
| Driver | NOT affected: no mobile/API/dispatch code; B2C DRIVER ONLY unchanged |
| Van | NOT affected: no B2B route/assignment code; B2B VAN ONLY unchanged |

## Integration gates

One Issue, one branch, one PR base `Foodex_V2`. Require Repository Policy, FOODEX V2 Isolation and FOODEX Required CI (Laravel, PHP/Pint/PHPStan/security/OpenAPI, AR/EN, MySQL/Redis where applicable) **terminal SUCCESS on the exact final HEAD**, verified source/branch protection/reviews and SHA-guarded merge. Never claim CI green before actually checking GitHub Actions; an unavailable local runner is not evidence.

After merged commit verification, close only #3625 as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with source/merge SHA and CI URLs. Keep master #2503 and P082 umbrella #2818 open for consolidated acceptance and human/staging UAT. No main merge, tags, releases, APKs or production.
