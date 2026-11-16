# V2 #3932 — Portable ZIP member names: source and security QA

## Integration boundary

- Repository: `walidatiyaai2025-gif/FOOD`
- Target: `Foodex_V2` only; base at implementation start: `8a0ba1e185cb3b97106d93deb3ac5eb0e00a1c66`
- Issue branch: `fix/3932-v2-updater-portable-zip-names`
- Risk: **HIGH** — this code validates paths used by updater preflight, file backup and extraction.
- Runtime impact: Backend updater only. No Dashboard route/view, public API, Customer, Driver or Van source changes; no fulfillment or finance behavior changes.

## Cross-app impact matrix

- **Customer:** no Customer API, authentication/session, storefront, cart/checkout, order or Flutter source changes; no Customer runtime impact.
- **Driver:** no Driver API, B2C dispatch, assignment, collection, proof-of-delivery or Flutter source changes; no Driver runtime impact.
- **Van:** no Van API, B2B assignment, territory/visit, direct-sale, fulfillment or Flutter source changes; no Van runtime impact.
- **Dashboard:** no route, view, RBAC, navigation or localization source changes; the existing Admin ZIP upload UI contract is unchanged.
- **Backend:** only the internal update-package archive validation boundary and its regression/QA files change.

## Source behavior

1. Read each ZIP member name in both decoded and raw form; reject a mismatch with `Update archive contains an unsafe path.` before opening the member stream.
2. Require every target name, including explicit directory-only entries, to contain printable ASCII bytes `0x20–0x7E`. ZIP file contents remain byte-transparent, including UTF-8 and Arabic payloads.
3. Validate directory entries instead of silently skipping them. Normalize only after the printable-ASCII check, then use ASCII `strtolower()` for case-insensitive target identity because non-ASCII target names are no longer admitted.
4. Preserve and exercise existing traversal, protected dotenv/runtime path, symlink/special-file, member-count, expanded-size, duplicate target, file/directory shadow, public-webroot executable and backup/rollback guards.
5. Perform archive path validation before ZIP metadata size checks and before any backup/extraction work. All invalid archives fail before release mutation.

## Changed paths

- `backend/app/Domain/Updater/LaravelUpdateRuntime.php`
- `backend/tests/Feature/V2UpdaterPortableZipNamesTest.php`
- `backend/tests/Feature/V2UpdaterNoncanonicalZipMemberPreflightTest.php` — one leading-tab fixture is now asserted as an unsafe control-byte path, matching the #3932 exception contract; whitespace and backslash noncanonical cases remain unchanged. This historical test file is not owned by any open PR; its original PR #3407 is merged.
- `docs/v2/qa/3932-updater-portable-zip-unicode.md`

## GCC verification

Environment: Windows GCC, PHP 8.3.33, ZipArchive, PHPUnit 11.5.58. `pdo_sqlite` and `sqlite3` extensions were enabled from the installed PHP DLLs. A temporary dependency install used the existing FOODEX Composer lock from the G004 test checkout; its `composer.json` differs from this base only by the PHP 8.2 platform override. The temporary `composer.lock`, `.env` and `vendor/` are local-only and are not included in the PR.

- Real-ZIP reflection harness against the actual `archiveEntriesFrom()` implementation: **16/16 cases passed (including raw CP437/decoded-name mismatch and control-byte directory entry)** (Unicode single and casefold/NFC/NFD aliases, Unicode directory-only entry, tab/control bytes, ASCII case collision, file/directory shadow in both orders, ancestor shadow in both orders, ZIP Slip, dotenv protection, and UTF-8 content on ASCII paths).
- Focused PHPUnit: **9 tests / 585 assertions passed**.
- Adjacent updater regressions: **58 tests / 1,242 assertions passed; 1 skipped** (Windows host cannot exercise the dangling-symlink case).
- PHP syntax: all 3 changed PHP files passed `php -l`.
- Pint: all 3 changed PHP files passed `pint --test` after formatting.
- PHPStan: full backend analysis, **946 files, no errors**.
- Composer manifest validation: `composer validate --strict --no-check-lock` passed.
- A broader 25-file updater sweep on Windows returned 116 tests / 1,679 assertions, with 18 errors, 6 failures, 1 skipped and 13 risky tests. After generating a local APP_KEY, a four-file rerun still reproduced 5 errors and 6 failures in unrelated database-backup snapshot / execution-lock path / test-database setup cases in untouched `UpdateManager` and backup-path code. These host-specific results are recorded, not waived; GitHub Linux Required CI remains mandatory.

## Review and remaining gates

- Engineering source review: HIGH-risk, self-review only; no independent approval claimed.
- Exact-head GitHub Repository Policy, V2 Isolation and Required CI: **pending** until the committed branch runs.
- Linux full Laravel tests and MySQL/Redis deployment acceptance: pending exact-head CI.
- Publisher-trusted signed package, staging installation and parent G005/P112 UAT remain separate final acceptance under #2746 / #2846 / #2503; this leaf does not claim release readiness.
- No `main`, tag, release, production, cPanel or deployment changes.
