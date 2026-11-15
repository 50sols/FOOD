# V2 #3647 — Finance Assets literal prefix lookup

**Source:** P082 Finance View, master #2503. **Integration:** `Foodex_V2` only. No `main`, production, release, tags, or deployment.

## Verified problem

The existing `FinanceAssetService::paginateAssets` silently removed `%` and `_` from an entered query and used the remaining text as an SQL prefix wildcard. For example, an operator searching for the literal fixed-asset identifier `EQ_001` could receive other `EQ...` identifiers, while an entirely symbolic query returned all scoped assets. This defect is search correctness, **not** a claim of SQL injection or authorization bypass.

## Delivery

- REUSE the existing Admin **Finance Workbench → Assets** tab and route; no new screen, sidebar, permission, or business field.
- Keep the existing validation, authorized legal-entity scope, `finance.view` and `finance.foundation.read` entitlement, filters, status reconciliation, bounded pagination, ordering, and asset detail/action endpoints unchanged.
- The search applies `LIKE ? ESCAPE '!'` to asset code and name with only the intended final `%`; all entered `!`, `%`, and `_` are literal. Bound pattern is portable across MySQL/MariaDB and SQLite. Arabic and English names remain searchable without changed labels.
- New `RefreshDatabase` regression uses actual Finance module enablement and scoped entitlement/roles, a foreign legal entity, Arabic text, literal special-character patterns, false-positive exclusions, category/status filters, minimum pagination bound, and unauthorized 403.

## Impact / risk audit

`REVIEW_RISK: HIGH` because Finance domain data and authorization-protected reads are involved. The patch changes **search matching only**; it neither writes ledgers nor changes permission decisions, approval/maker-checker, tenant resolution, schema/migrations, public API, or cash collection.

| Surface | Impact |
| --- | --- |
| Laravel Finance service | Corrected literal scoped read-only prefix matching |
| Dashboard | Reuses existing Assets tab, AR/EN RTL/LTR and responsive layout |
| Customer | Not affected: no public endpoint or Flutter asset contract |
| Driver | Not affected: no API, role, wallet, dispatch, or Flutter change |
| Van | Not affected: no API, custody, trip, or Flutter change |

Store and legal-entity scoping predate and remain outside the OR search group; unauthorized users still fail before DB reads. Financial SoD, immutable ledger, B2B VAN ONLY and B2C DRIVER ONLY remain unchanged.

## Verification / acceptance

Focused: `cd backend && php artisan test --filter=V2FinanceAssetsLiteralPrefixSearchTest`; `vendor/bin/pint --test app/Enterprise/Finance/FinanceAssetService.php tests/Feature/V2FinanceAssetsLiteralPrefixSearchTest.php`. The GitHub branch CI provides authoritative full backend, static/security, AR/EN, MySQL/Redis and required-gate evidence. **Do not assert those tests passed without checking the exact final commit's completed runs.**

Final merge gate: inspect original PR, all current V2 worker leases, three-file fence, expected source SHA, `base=Foodex_V2`, exact-final-head Policy, V2 Isolation and Required CI (including nested terminal `required-ci-gate`) all `SUCCESS`. Record a file-by-file HIGH technical audit (not a claim of separate independent GitHub approval) before merging. Then close only this atomic leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`, retaining master #2503 open for consolidated UAT.
