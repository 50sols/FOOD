# V2 #3752 — App Preview session creation store guard AR/EN

Integration: Foodex_V2 ONLY. Authenticated POST /admin/app-preview/sessions via AppPreviewController::storeSession and AppPreviewSessionService::create.

## Scope and ordering

HTTP input validation checks target_user_id, target_type, channel, store_id, support_access and TTL. AppPreviewSessionService::resolveAuthorizedStore rejects a missing Retail store and a noncanonical Wholesale principal store before target lookup, token creation, session insertion or audit. Existing HTTP 422 JSON message shape, all tenant/auth/permissions, B2B canonical principal, Retail support-access handling and state transitions are unchanged. Only user-facing 422 messages are resolved by paired preview_sessions AR/EN Laravel translation keys.

## Negative and positive evidence

V2DashboardPreviewSessionStoreLocaleTest uses RefreshDatabase and CoreReferenceSeeder, seeded store-admin and B2B_ADMIN actor fixtures, and real authenticated POST for both Arabic and English actor locales. Retail missing store_id and Wholesale mismatched principal store_id return exact localized HTTP 422. The test asserts zero new app_preview_sessions, app_preview_invalidation_events and audit_logs. Foreign Retail store and roleless actor are still forbidden (403).

Existing AppPreviewDashboardBridgeTest retains positive 201 preview session creation, hashed token, scope and 204 revocation/audit regression; full CI must prove this still passes. No preview authentication or credential transfer behavior changes.

## Surface and security audit

- Dashboard: existing Preview Center session POST message only; no navigation, Blade/UI, responsive, RTL/LTR changes.
- Customer: no public/mobile API or Flutter changes.
- Driver: no public/mobile API or Flutter changes; B2C DRIVER-only untouched.
- Van: no public/mobile API or Flutter changes; B2B VAN-only untouched.
- REVIEW_RISK HIGH for session creation/security path; same-account COMMENTED file-by-file technical audit is not independent peer approval.
- Required before integration: PHP/Pint/PHPStan/security, AR/EN, Laravel targeted/full, APP-PREVIEW acceptance, real MySQL/Redis and exact-final-head Repository Policy, V2 Isolation, Required CI SUCCESS. Manual/staging business UAT deferred to #2503.
- No main, release, tags, APK, production deployment or cPanel mutation.
