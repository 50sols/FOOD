# Issue #2305 QA evidence

This scope is an operator-run Artisan command. RBAC, tenant isolation, and browser AR/EN presentation are not applicable; the command uses existing protected legacy materialization tables, never promotes native fixed assets, and preserves both Arabic and English source names.

## Automated acceptance cases

Run from `backend/`:

```text
php -d memory_limit=1G vendor/bin/phpunit --filter=LegacyFinanceAssetOneShotCommandTest
```

- Dry-run with a 78-row isolated legacy SQLite fixture: 74 zero-value/no-history placeholders are excluded from native preflight; two capitalized rows, one zero-value row with history, and one row with unknown age remain candidates. Missing account/category/offset mappings keep all candidates blocked. No target, mapping, or run rows are written.
- Apply with the same 78-row fixture: every row is materialized and verified, Arabic and English names are preserved, the candidate-only preflight remains blocked, and no native promotion occurs.
- Repeated apply: target and mapping counts remain 78 while a second completed import run is recorded, proving idempotent row coverage.

These are disposable SQLite fixtures; they do not connect to a live legacy database or production data. GitHub Actions results and the exact tested commit are recorded in the linked PR.
