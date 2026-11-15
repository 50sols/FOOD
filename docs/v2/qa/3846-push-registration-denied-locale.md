# V2 #3846 — authenticated Driver/Van push registration HTTP403 AR/EN

## Scope and source
- Parent: #2503; related access domains #2734 Driver and #2737 Van.
- `POST /api/v1/push/devices` already validates the payload, enforces matching Sanctum `app:*` token abilities and independently checks active Retail Driver authorization or permitted Van runtime. No new access policy, claim, route, API field, response status, database migration or user/device mutation is introduced.
- Before this change, two explicit HTTP403 denial strings were always English even for Arabic authenticated accounts. Now both resolve via paired `push_device_errors` keys, using the authenticated account's `ar/en` locale (Arabic fallback for invalid locale) and **not** a contradictory `Accept-Language` header.
- Existing English messages remain byte-for-byte unchanged. Both new Arabic messages express the same denial without exposing account identities or server diagnostics.

## Impact matrix and placement
| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend/API | Human-readable `message` in two denied HTTP403 responses only | `PushDeviceController::upsert`; no status, payload shape or authorization branch changes |
| Dashboard | No impact | No admin files or web routes changed |
| Customer | No impact | No Customer device branch, customer public schema, Flutter source or auth behavior change |
| Driver | Existing denied registration shows localized `message` | B2C-only profile, active/role/permission checks remain enforced |
| Van | Existing denied registration shows localized `message` | B2B-only runtime resolver and permission/fallback remain enforced |

Mobile consuming apps already process server error messages. No new Flutter code or additional app issue is required for this text-only API response correction. B2B=VAN ONLY; B2C=DRIVER ONLY.

## Negative HTTP/tenant/security regression
`backend/tests/Feature/V2PushRegistrationDeniedLocaleTest.php` seeds core roles and uses real `User` records, matching `app:driver`/`app:van` Sanctum abilities with no authorized active driver or Van context. It asserts actor `ar/en` locale beats contradictory request language, exactly HTTP403 and localized `message`, and zero device/audit writes. This is fail-closed and does not convert a role/token into an entitlement.

Existing `V2PushDeviceAppRegistrationAbilityTest`, `V2GuestPushInstallIdLocaleTest` and `MobilePushSettingsTest` cover cross-app token rejection, anonymous Customer install ID, and successful authorized registrations. Run alongside this regression, full Laravel, PHP syntax/Pint/PHPStan, security, AR/EN, Repository Policy, V2 Isolation and terminal FOODEX Required CI on the final PR SHA.

## REVIEW_RISK: HIGH
Authorization-adjacent API response wording. File-by-file technical audit must confirm unchanged guards and positive flows, exact bilingual catalog parity, no writes or private leaks, exact-head CI and safe rollback of these five paths. Review can be a same-account COMMENTED engineering audit, never misrepresented as independent GitHub approval. Final device/staging UAT is deferred to V2 master #2503 **only after** source completion and green CI.

**Isolation:** one issue #3846, one branch `fix/3846-v2-push-driver-van-denial-ar-en`, one PR, base `Foodex_V2` only. Never main, production, releases, tags, APK or cPanel.
