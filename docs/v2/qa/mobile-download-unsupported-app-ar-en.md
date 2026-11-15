# V2 #3746 — Administration mobile downloads invalid-app AR/EN

**Base:** `Foodex_V2` only. Parent #2503. No production/release/publishing.

## Root cause and smallest correct fix
Existing Administration Hub mobile downloads modal invokes `GET /admin/apps/downloads/status` and `POST /admin/apps/downloads/{prepare,retry}`. These routes are authenticated, Dashboard-protected and `platform.manage`-authorized. `MobileAppDownloadController::requestedApp` rejected unknown and non-string `app` with HTTP 422 but the human-facing `message` was English regardless of the chosen Dashboard locale.

Only the existing abort message now resolves `admin.mobile_apps.unsupported_app` in the selected AR/EN admin locale. Response status, JSON `message` envelope, allowlist (`customer`, `driver`, `van`), `platform.manage` guard, valid request paths, APK mirror, release policy and signing/integrity behavior are unchanged.

## Reproducible regression
`php artisan test --filter=MobileAppDownloadTest` exercises real seeded, authenticated Super Admin HTTP requests for both `ar` and `en`: invalid app string in status/retry and non-string app in prepare must return exact localized 422 `message`. Asserts no Mirror job, no mobile_release_artifacts row count change, no app version policy creation and no unexpected SystemVersion write. A guest and a logged-in user without Dashboard management privilege cannot bypass authentication/authorization. Pre-existing valid status/prepare/retry, local APK integrity and public download tests run unchanged.

## Impact and risk audit
- **Dashboard:** selected-locale error feedback in existing Administration Hub download modal; no Blade/route/navigation/layout alteration.
- **Backend:** protected admin controller validation message and AR/EN catalog pair only; no API contract other than localized human copy.
- **Customer:** not affected (admin-only web route; no Customer API, Flutter or APK source change).
- **Driver:** not affected (no Driver API or Flutter change).
- **Van:** not affected (no Van API or Flutter change).
- **Security:** HIGH review classification because near mobile release control; malformed input is rejected before mirror scheduling, authorized access is unchanged, no new allowlist member or policy bypass.

Exact-head checks, reviewed SHA, and PR audit are recorded in PR #TBD. Manual/staging acceptance remains under #2503. No merge to main or live deployment is authorized.
