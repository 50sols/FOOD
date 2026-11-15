# V2 #3881 — Retail storefront revision missing-store AR/EN fail-closed guard

**Status:** Implementation candidate for isolated `Foodex_V2`, parent #2503 retains final staging/UAT acceptance.  
**REVIEW_RISK: HIGH:** Admin authoring API, store tenancy and preview state are security-adjacent; this change affects only the existing HTTP422 human message and adds AR/EN fail-closed tests.

## Canonical contract and threat model

Routes: `GET /api/v1/admin/app-preview/storefront-revisions?channel=b2c` and `POST /api/v1/admin/app-preview/storefront-revisions/draft` with `channel=b2c` but no `store_id`. The authenticated actor is resolved by existing `actor()`; `authorizeScope` rejects absent Retail store before tenant resolver, role permission, revision service or database write. Retain exactly HTTP 422 and response JSON `message`; preserve prior literal English `Retail storefront revision requires a store.`. Saved supported user `ar/en` locale overrides contradictory `Accept-Language`; unsupported saved locale uses supported regional header and a safe Arabic fallback. A supplied foreign store must still be forbidden, not translated into permission to access another tenant.

## File-by-file technical audit checklist

| File | Change | Negative / rollback |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/StorefrontRevisionController.php` | Replace only existing missing-Retail-store 422 literal with `__('storefront_revision_errors.retail_store_required', [], $this->locale($request, $actor))` and add a minimal locale helper based on authenticated user/header. | Existing request validation, scope, support override, permission checks, B2B routing, publishing, revisions and preview-token read methods untouched. Rollback is code-only. |
| `backend/lang/ar/storefront_revision_errors.php` | One genuine Arabic message key. | Exact AR/EN parity, no untranslated English fallback. |
| `backend/lang/en/storefront_revision_errors.php` | One matched key with exact previous English literal. | No contract change to English clients. |
| `backend/tests/Feature/V2StorefrontRevisionMissingRetailStoreLocaleTest.php` | Seeded Retail store admin: AR/EN saved actors vs contradictory headers and unsupported saved locale; each GET/POST missing-store request exact 422; count checks of revision/session/events/settings/audit; anonymous 401 and cross-store 403. | No fake auth, no write, no cross-tenant bypass. Original positive `StorefrontRevisionTest` remains intact. |
| This QA document | Source/negative tests/CI gate register and per-app impact. | Document does not authorize release. |

## Acceptance and exclusions

- Validate each rejected request leaves `storefront_revisions`, `app_preview_sessions`, `app_preview_invalidation_events`, `storefront_settings`, `storefront_sections`, `audit_logs`, stores and role assignments unchanged.
- Run existing `StorefrontRevisionTest` positive Retail draft/publish/rollback and Wholesale B2B canonical scope tests; do not modify any API field, returned DTO, route, auth guard, middleware or preview session token resolution.
- **Dashboard:** Admin authoring revision HTTP422 message localized; existing screen/route/sidebar unaffected. The Admin API remains an explicit non-Sidebar exception; it belongs to the existing storefront editor flow.
- **Customer:** No Flutter/public Customer store API or preview-token read contract changed. Only Admin revision error on missing Retail `store_id` changes, so no mobile issue or app build is warranted by this atomic patch.
- **Driver:** No Flutter/driver API/assignment effect; B2C DRIVER ONLY unchanged.
- **Van:** No Flutter/Van API/dispatch effect; B2B VAN ONLY unchanged.
- No migration, data conversion, finance or stock operation; rollback by reverting this PR through a separately reviewed safe change. Do not silently widen permissions to avoid the guard.

## Evidence (must be populated from exact final HEAD, not assumed)

- [ ] Seeded real Laravel HTTP negative test and existing positive source regressions PASS.
- [ ] Backend full tests, PHP syntax, Pint/lint, PHPStan, Composer audit, OpenAPI, AR/EN localization PASS.
- [ ] Real MySQL/Redis install, upgrade and recovery acceptance PASS.
- [ ] Repository Policy SUCCESS on exact HEAD.
- [ ] FOODEX V2 Isolation Gate SUCCESS on exact HEAD.
- [ ] FOODEX Required CI Gate including terminal `required-ci-gate` SUCCESS on exact HEAD.
- [ ] Distinct file-by-file `REVIEW_RISK: HIGH` / `TECHNICAL_REVIEW: PASS` GitHub comment-review, not independent APPROVED.
- [ ] Final SHA-guarded original PR merge **only to Foodex_V2**, verified integration SHA/contents and completed leaf closure. Parent #2503 stays OPEN for manual/device/staging final acceptance.

**No** main merge, production deployment, release tag, APK, Setup ZIP, Update ZIP or cPanel changes are approved.
