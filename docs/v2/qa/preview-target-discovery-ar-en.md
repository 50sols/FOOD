# V2 #3748 — App Preview target discovery AR/EN 422

Integration: **Foodex_V2 only**. Route: authenticated Dashboard GET /admin/app-preview/targets, under the existing Preview Center. No new route, UI, menu or release.

## Source and negative boundary

AppPreviewController::targets validates target type, channel, store, support access and search, then calls AppPreviewTargetService::discover. authorizedStore rejects a missing Retail store and a noncanonical explicit Wholesale principal store *before* discovery, retaining HTTP 422 semantics. Two fixed English strings now use matched Laravel AR/EN keys preview_targets.retail_store_required and preview_targets.wholesale_principal_required.

TenantContextResolver::retail still denies unauthorized Retail stores. TenantContextResolver::wholesale, WholesalePrincipal, app_preview.view, and per-target impersonation permissions are unchanged. Discovery is advisory and read-only: AppPreviewSessionService independently reauthorizes targets on session creation.

## Deterministic acceptance

php artisan test --filter=V2DashboardPreviewTargetLocaleTest uses RefreshDatabase and CoreReferenceSeeder on the actual authenticated route. AR and EN Retail missing-store and Wholesale mismatched-principal requests assert exact HTTP 422 JSON message and no app_preview_sessions, app_preview_invalidation_events or audit_logs changes. Foreign Retail store and roleless user receive 403. Valid Retail store and canonical Wholesale driver targets retain 200, with B2B driver discovery empty after Van-only cutover.

Existing AppPreviewDashboardBridgeTest, full Laravel suite, AR/EN localization, Pint/PHPStan/security, MySQL/Redis deployment acceptance and exact-final-head required GitHub CI must pass before merge.

## Surface and review

- Dashboard: error copy only, existing navigation and accessible responsive RTL/LTR layout unchanged.
- Customer: no public/mobile API, Flutter source or DTO change.
- Driver: no public/mobile API or Flutter change; B2C DRIVER-only preserved.
- Van: no public/mobile API or Flutter change; B2B VAN-only preserved.
- REVIEW_RISK: HIGH because tenant-scoped preview targets; same-account file-by-file engineering audit is not independent peer approval. Final manual/staging acceptance deferred to open #2503. No main, release, tags, APK or production.
