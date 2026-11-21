# V2 #3851 — B2B statement export HTTP503 AR/EN

## Source and narrowed safety boundary
- **Source / tracking:** #3851; parents #2732 (B2B Customer financial parity) and #2503 V2 master. Base **Foodex_V2 only**. No main, release, tags, APK, cPanel or deployment.
- **Authenticated route:** `GET /api/v1/b2b/account-statement/export?format=xlsx|pdf`, authoritative approved active B2B account and tenant scope. Existing report `ReportExportService::build` failure is caught as `RuntimeException` and sanitized to HTTP503 JSON `message`.
- **Existing defect:** on Arabic-selected report export, failure text was English `Document export is temporarily unavailable.`. A renderer error may contain internal file/system details; do not echo it.
- **Minimal change:** translate only the existing `statementExport()` 503 text using already validated explicit `locale` query parameter, or saved user locale, with the existing fallback to English for other values. Do **not** change locale resolution, supported formats, auth, B2B scope, report totals, output files, transactions or ordering. The separate Invoice PDF generation 503 branch is not changed or claimed by this atomic issue.
- **Risk:** HIGH, finance API error path and unauthorized data leakage defense.

## File-by-file engineering audit
1. `backend/app/Http/Controllers/Api/V1/B2bFinanceController.php`: catch still runs only on renderer `RuntimeException`, retains HTTP503/message and no exception leak; only named translation key replaces literal. No finance, statement, account or audit authority changes.
2. `backend/lang/ar/b2b_statement_export_errors.php`: truthful Arabic message.
3. `backend/lang/en/b2b_statement_export_errors.php`: retains exact original English and matching flattened key.
4. `backend/tests/Feature/B2bFinanceTest.php`: real authenticated seeded approved B2B account and real HTTP statement-export route; container-bound test-only deterministic failing renderer (class is final, but the controller resolves `ReportExportService` dynamically), explicit AR/EN vs saved locale and contrary header, unsupported saved locale fallback EN; status 503 and exact message, no exception details, no new order/invoice/payment/stock/audit writes on failure. Existing positive invoice PDF and finance tenant tests retained.
5. `docs/v2/qa/b2b-statement-export-unavailable-ar-en.md`: scope/security/impact and exact-head CI evidence.

## Backend and app impact
- **Customer:** existing B2B Customer statement export API error wording localized only; no Flutter route, DTO, request, payment or auth change. The customer can still initiate existing xlsx/pdf formats. B2C unrelated.
- **Driver:** not affected (no Driver API/Flutter).
- **Van:** not affected (no Van API/Flutter), B2B=VAN ONLY unchanged.
- **Dashboard:** not affected (no Admin view, menu or financial data path).
- **Backend:** only sanitized 503 text; no storage/schema/queue/cache/release changes. Original audited preflight and failed export postconditions retained. No OpenAPI response shape change.

## Verification gate
`cd backend && php artisan test --filter=B2bFinanceTest`. Exact-final-head Repository Policy + V2 Isolation + terminal Required CI must be SUCCESS, including PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN, full Laravel and MySQL/Redis install/upgrade/recovery. A separate file-by-file HIGH COMMENTED technical review is required (same-account engineering audit is NOT independent APPROVED review). No merge until current head, lease, CI and source completeness verified. After code-complete merge only into Foodex_V2, defer final business/staging UAT under parents #2732/#2503.
