# V2 #3839 — public Preview invalidation SSE missing token AR/EN

**Integration:** `Foodex_V2` only. Parent #2503 stays open. No main, tags, releases, APKs, production or cPanel.

## Reproduced source condition and minimal change
- Public `GET /api/v1/app-preview/events` in `AppPreviewInvalidationController::__invoke` checked the `X-Foodex-Preview-Token` header and rejected missing or whitespace tokens with HTTP 401 and the hardcoded English `Preview token is required.`.
- Reuse the existing `preview_session.token_required` entry in both `backend/lang/ar/preview_session.php` and `backend/lang/en/preview_session.php`, established for the different resolve endpoint by #3753. No translation catalogs, API routes, response DTOs, database schema, migrations, authentication policy or SSE stream service are modified.
- Negotiate supported public `Accept-Language` AR/EN before the early abort. Original English wording and JSON `message` remain unchanged in English. For an unsupported negotiated value, choose a supported app-language fallback without leaking a raw translation key.

## Security / negative no-write contract
1. No missing, blank or whitespace header may invoke `AppPreviewSessionService::resolve`, obtain the stream, or bypass a preview capability. HTTP remains 401, never a success/redirect for a JSON client.
2. `V2PreviewInvalidationMissingTokenLocaleTest` calls the actual route with `Accept: application/json` and Arabic-Kuwait or English-US headers, with deliberately opposite Laravel app locale, for missing and whitespace tokens; asserts exact localized response, no `data`, and unchanged `app_preview_sessions`, `app_preview_invalidation_events`, `audit_logs`, and `notifications` row counts.
3. Existing `AppPreviewInvalidationTest` remains the positive security regression for hashed authorized preview token, customer-only, read-only, store-bound SSE cursor and stale/revoked/query-token denial. It must pass without modification.

## Consumer impact matrix

| Surface | Impact | Authorization / contract |
|---|---|---|
| Backend API | Existing 401 message now locale-aware | Unchanged token requirement, hash/session/tenant/scope checks, JSON key and SSE |
| Dashboard | No route/Blade/navigation change | Existing preview session issuance & permissions unchanged |
| Customer | Preview invalidation error copy only | No Flutter/DTO/route change; all data-bound preview access remains server authorized |
| Driver | No direct event-stream change or Flutter change | Driver preview access boundaries unchanged |
| Van | No endpoint/client change | B2B VAN ONLY; no dispatch/routing impact |

No new mobile issue is necessary because no exposed response field, DTO, route, token grant or authenticated mobile runtime behavior changes. B2C remains DRIVER ONLY.

## Verification, review and rollback
**REVIEW_RISK: HIGH** (public preview bearer token boundary, despite copy-only delta). Review must inspect: controller early-guard normalization and unchanged downstream authorization; real HTTP negative/no-write test including opposite app locale; document's app/tenant mapping and unaltered original positive tests. The engineering review is a same-account COMMENT, **not** independent GitHub APPROVED.

Run focused Laravel tests `php artisan test --filter='V2PreviewInvalidationMissingTokenLocaleTest|AppPreviewInvalidationTest|V2PreviewResolveMissingTokenLocaleTest'`; then repository Laravel/Pint/PHPStan/security/OpenAPI/localization and available MySQL/Redis acceptance. Confirm exact-head Repository Policy, FOODEX V2 Isolation and terminal Required CI Gate all successful before merge. Record actual run URLs and SHA in PR/Issue. **Do not claim these tests pass before GitHub supplies results.**

Rollback only this isolated PR/source change if needed; no persistence changes or data rollback are required. Final human/device/staging V2 acceptance remains with #2503.
