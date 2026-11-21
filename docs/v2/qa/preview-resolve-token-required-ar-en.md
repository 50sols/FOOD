# V2 #3753 — Public App Preview Resolve missing token 401 AR/EN

**Parents:** #2740 Customer/Driver cross-app preview parity and #2503 V2 final acceptance. **Integration:** `Foodex_V2` exclusively, never `main` or production.

## Source, route and invariant

The existing public `POST /api/v1/app-preview/resolve` route invokes `AppPreviewSessionController::resolve`. It accepts the opaque token from `X-Foodex-Preview-Token` header then `preview_token` JSON/form fallback. A missing/non-string token is denied **HTTP 401** before `AppPreviewSessionService::resolve` retrieves any session. Its fixed English-only message was returned even when the client requested Arabic. The token is never an ordinary Sanctum authorization token. We use `Request::getPreferredLanguage(['ar', 'en'])`, mirroring existing public server language-negotiation convention, for exactly this human-facing 401 message via paired `preview_session.token_required` keys.

No token extraction, signature/hash, TTL, revocation, role, store/channel, support-access or tenant checks, JSON response key, HTTP status, session lookup, audit event, queue, API version or success path is changed.

## Real HTTP rejection regression

`V2PreviewResolveMissingTokenLocaleTest` uses `RefreshDatabase + CoreReferenceSeeder` and submits missing-token and numeric-token requests with `ar-KW` and `en-US` Accept-Language; asserts exact translated 401 `message`, no success `data` on missing token and unchanged session, audit and notification counts. Existing `AppPreviewSessionTest` verifies valid hashed token resolution, isolation, revoke and expiry behavior and must remain green in full Laravel CI.

## Client and accessibility impact

- **Customer:** public read-only preview-token resolve error copy only; unchanged 401 JSON contract and Customer Flutter code/DTO
- **Driver:** same public preview-token entrypoint; error copy only, unchanged Driver Flutter and preview driver authorization
- **Van:** no preview-token contract or Flutter code change; B2B VAN ONLY remains
- **Dashboard:** existing preview session creation, authorization, UI/navigation and RTL/LTR unchanged; no new page/route

## Security review and integration gates

REVIEW_RISK: HIGH because read-only preview tokens are sensitive identity-bound credentials. Source review must confirm no bypass and no leaked tokens or fallback into normal auth. Same-account COMMENTED technical review is **not** independent GitHub APPROVED. Require exact-final-head Repository Policy, V2 Isolation, localization, PHP/Pint/static/security, full Laravel tests, MySQL/Redis deployment acceptance, terminal `required-ci-gate` all SUCCESS before SHA-guarded squash merge solely to `Foodex_V2`. Full staging/device business acceptance tracked by open master #2503; no release or production claim.
