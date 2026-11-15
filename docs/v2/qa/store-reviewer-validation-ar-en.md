# V2 #3705 — Store reviewer validation AR/EN and no-write regression

**Integration:** `Foodex_V2` exclusively. No `main`, production, APK build, release, cPanel or tags. Parent #2503 retains final human/staging acceptance.

## Authorization and placement

Reuse the existing Management Dashboard **Mobile Settings → Store Reviewers** form and the existing `PUT /admin/settings/mobile/reviewer` action. `EnsureManagementDashboardAccess` selects authenticated actor `ar`/`en` before the controller, and `StoreSubmissionController::authorize` requires `mobile_settings.manage`. No new route, page, menu item, entitlement, provider or role is introduced.

Only two existing `ValidationException` field errors are localized using the canonical paired `mobile_settings` keys: missing reviewer secret on first create (`reviewer_secret` / HTTP 422), and a chosen store belonging to a different channel (`reviewer_store_id` / HTTP 422). This does not change successful reviewer creation, credential encryption or masking, context selection, audit or readiness flows. Existing store-type `B2B`/`B2C` lookup is still authoritative. The validation occurs before `updateOrCreate` and its audit call.

## Regression and security evidence

`backend/tests/Feature/StoreSubmissionReadinessTest.php::test_reviewer_secret_and_store_channel_validation_use_selected_ar_en_without_writes` exercises real authenticated Dashboard JSON HTTP requests in both actor locales, exact field-scoped Arabic/English messages, the real Retail `B2C` store against submitted `b2b` channel, no reviewer account creation, no reviewer-update audit on either rejection, no credential echoed in 422 payload, and a roleless actor denied HTTP 403. Existing positive secret masking, readiness and structured store-context tests remain unchanged.

Risk: **HIGH** — credential setup and store-channel scoping. Source review must inspect controller, both language catalogs and the HTTP regression file. Verify exact-final-head PHP syntax, Pint, PHPStan, security, AR/EN, full Laravel, MySQL/Redis installer/updater/recovery Required CI before merging. Same-account COMMENTED review is an engineering audit, not independent GitHub APPROVED.

## Customer / Driver / Van impacts

- **Customer:** reviewer persona metadata is Dashboard-only; no public mobile API, Flutter model, authentication or launch policy change.
- **Driver:** identical no-impact conclusion, and existing Driver-only B2C fulfillment contract unchanged.
- **Van:** identical no-impact conclusion, and existing B2B VAN ONLY dispatch contract unchanged.

The two machine-readable validation field names and HTTP 422 status remain stable. Dashboard RTL/LTR layout does not change; only the selected-language error wording changes. No mobile-impact implementation Issue is required because no application runtime/API contract changes.
