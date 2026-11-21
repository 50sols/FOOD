# V2 #3813 — Customer Guest Push Registration (AR/EN install_id HTTP422)

- Parent: #2503; cross-app contract: #2740; integration `Foodex_V2` ONLY.
- Source: existing `POST /api/v1/push/devices/guest` in `PushDeviceController::upsert` returned English-only `install_id is required for anonymous Customer devices.` for a missing or empty `install_id` despite a validated `locale=ar`.
- Fix: replace **one** existing user-facing guest missing-install ID literal with `__('push_device_errors.anonymous_install_id_required', [], (string) ($data['locale'] ?? 'ar'))` after existing request validation. Preserve status 422, message shape, exact English text and default Arabic for absent query locale.
- No modification to driver/van authentication checks, guest application restriction, transaction/device upsert, token hash/encryption, cross-app authorization, store/channel logic, schema, route or DTO.

## HIGH file-by-file source/threat review

| File | Change | Risk control |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/PushDeviceController.php` | Only guest empty/missing install_id 422 message; original `empty` guard preserved | Avoid widening unauthorized guest device registration or permitting Driver/Van by altered control flow |
| `backend/lang/ar/push_device_errors.php` | New one-key Arabic message | No untranslated system English in Arabic |
| `backend/lang/en/push_device_errors.php` | Same key, exact previous English | Preserve existing English clients |
| `backend/tests/Feature/V2GuestPushInstallIdLocaleTest.php` | Live HTTP ar/en, contrary Accept-Language, missing/empty/default input, unauthorized Driver/Van, invalid locale, valid success and no-write negatives | Verify 422/401 and preserve guest success, security and DB invariants |
| `docs/v2/qa/customer-guest-push-install-id-ar-en.md` | Source, acceptance, impact matrix, audit | No spurious full-program acceptance claim |

## Impact by surface

- **Backend/API:** existing guest Customer push device register 422 `message` becomes locale-aware. Identical route/status/JSON envelope, validation, device mutations for valid requests and no new secrets.
- **Customer:** affected human error text on guest registration only; app DTO/API contract and Flutter code unchanged. Applies to Guest B2C Customer app.
- **Driver:** not affected. Guest endpoint still rejects `app=driver` with HTTP401 before missing install ID; authenticated Driver authorization and B2C DRIVER ONLY untouched.
- **Van:** not affected. Guest endpoint still rejects `app=van` with HTTP401 before missing install ID; Van permissions and B2B VAN ONLY untouched.
- **Dashboard:** no changes to settings, routes, screens, responsive layout, roles or Sidebar.

## Regression and delivery gate

The new real HTTP `V2GuestPushInstallIdLocaleTest` probes ar/en with opposite Accept-Language, required and empty install ID, no token/audit DB side effects, default Arabic, unsupported locale HTTP422, unauthorized Driver/Van HTTP401, and successful valid Guest Customer registration; existing `MobilePushSettingsTest` must continue green. Complete Laravel, Pint/PHPStan/security/OpenAPI, AR/EN, MySQL/Redis fresh/upgrade/recovery and terminal exact-final-head Required CI must be GREEN prior to merge. Same-account COMMENTED HIGH engineering file-by-file PR audit is **not** independent approval. Human/staging acceptance remains in #2503. No main, tags, release, APKs, cPanel or production action is authorized.
