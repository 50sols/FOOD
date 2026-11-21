# V2 #3807 — Missing Mobile Runtime AR/EN HTTP404

- Parent: #2503; shared mobile contract: #2740.
- Integration: `Foodex_V2` ONLY. No `main`, production, tag, release, installer or APK publication.
- Contract: unauthenticated `GET /api/v1/mobile/runtime?app={customer|driver|van}&environment={development|staging|production}&locale={ar|en}`.
- Existing failure: missing exact `MobileAppSetting` row returned English-only HTTP404 `{"message":"Mobile runtime settings are not configured."}` even for `locale=ar`.
- Fix: select the pre-existing supported query locale (default `ar`) immediately after request validation and render the same response status and JSON envelope using `mobile_runtime_errors.settings_not_configured`. Preserve the original English verbatim. Do not invent header negotiation: when the query `locale` is absent, the existing runtime contract defaults to Arabic even if `Accept-Language` requests English.

## File-by-file risk and negative-scope review

| Path | Bounded edit | Risks / controls |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/MobileRuntimeController.php` | Move existing locale initialization before missing-row guard; replace one literal with Laravel explicit-locale key | Preserve query validation, missing-record lookup, HTTP404 and `message` shape; successful response fields, push secrets redaction and cache/DB behavior unchanged |
| `backend/lang/ar/mobile_runtime_errors.php` | New truthful Arabic missing-settings message | Avoid English-only output in Arabic app mode |
| `backend/lang/en/mobile_runtime_errors.php` | New paired key with exact original English | Preserve legacy English text and AR/EN key parity |
| `backend/tests/Feature/V2MobileRuntimeMissingLocaleTest.php` | Real HTTP matrix for Customer, Driver and Van with locale/header disagreement; default Arabic, invalid locale/app/environment and no DB writes | Regress HTTP404/422 and prevent unexpected mobile-setting, provider or audit mutations |
| `docs/v2/qa/mobile-runtime-unconfigured-ar-en.md` | Source, review, negative proof and impacted app classification | Do not confuse isolated code completion with staging/device/business acceptance |

## Impact classification

- **Backend/API — affected:** existing public mobile runtime lookup returns a localized human error only. No change to path, method, required query, status, auth, machine fields, data payload, credentials or storage.
- **Dashboard — not affected:** no admin route, form, role, entitlement, Sidebar or view modification; the existing settings management remains authoritative.
- **Customer — affected response text only:** existing 404 `message` is Arabic for `locale=ar`, English for `locale=en`; Customer Flutter DTO, auth and navigation contract unchanged.
- **Driver — affected response text only:** exact same error-envelope compatibility, no Driver Flutter code or dispatch permissions touched; B2C DRIVER ONLY preserved.
- **Van — affected response text only:** exact same error-envelope compatibility, no Van Flutter code or assignment/finance behavior touched; B2B VAN ONLY preserved.

## Regression evidence and acceptance

Run `php artisan test --filter=V2MobileRuntimeMissingLocaleTest` and existing `php artisan test --filter=MobilePushSettingsTest` in the Laravel backend, followed by repository policy/AR-EN/Pint/PHPStan/security, full Laravel, MySQL/Redis acceptance and the terminal Required CI on the **exact final PR HEAD**. The new test asserts: all three app selectors × ar/en; the query locale takes precedence over contrary header; unchanged `404/message`; preserved default Arabic; malformed app/environment/locale remains HTTP422; no `mobile_app_settings`, `push_provider_settings`, or `audit_logs` writes.

**REVIEW_RISK: HIGH** (public shared-mobile API) even though the actual change is error-copy only. A separate PR file-by-file same-account COMMENTED engineering audit is required, not an independent approval claim. Do not merge on queued, missing, failed, or superseded CI. Final real-device/staging UAT remains with #2503.
