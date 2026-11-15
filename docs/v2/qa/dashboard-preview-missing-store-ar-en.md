# V2 #3721 — Dashboard Retail preview SSE missing-store 422 localization

## Authorization and source
Existing `GET /admin/app-preview/events?channel=b2c` uses the authenticated Dashboard event-stream route. The Retail stream requires `store_id`; otherwise the controller returns HTTP 422 before tenant resolution and stream creation. The field's existing English-only message violated `docs/quality/LOCALIZATION_CONTRACT.md` in Arabic mode. Existing source #550/#557 already owns the streaming engine and store isolation; no duplicate route or feature added.

## Implementation contract
- Pair `preview_invalidation.retail_store_required` in server AR and EN catalogs; use the existing actor-selected Laravel locale.
- Keep exact HTTP 422 and `message` JSON shape for an authenticated missing-store request. Preserve auth, Retail tenant resolution, store-scoped permission, B2B principal check, SSE event shape and Last-Event-ID behavior.
- No guest/full-store bypass, new route, tenant access, data write, stream side effect or localization fallback.
- Existing `AppPreviewDashboardInvalidationTest` covers valid Retail SSE, B2B canonical principal, exact store scoping, Last-Event-ID and denied scope.
- New `V2DashboardPreviewMissingStoreLocalizationTest` tests real AR/EN authenticated missing-store 422 message; no session/event/audit writes; foreign Retail store 403.
- CI: focused and full Laravel, PHP/Pint/PHPStan/security, AR/EN parity, Repository Policy, V2 isolation, real MySQL/Redis acceptance and exact final HEAD Required CI required for integration.

## Impact
- Dashboard: localized error copy on existing Retail SSE validation; no UI markup/navigation/layout/RTL change.
- Customer: public preview API, DTO, event protocol and Flutter unchanged.
- Driver: public API, DTO and Flutter unchanged.
- Van: public API, DTO and Flutter unchanged.
- No schema, financial, dispatch, release or production impacts.

Review classified HIGH under V2 governance because it is a tenant-scoped authenticated stream, despite minimal text-only delta. Same-account COMMENTED file-by-file technical audit must not imply independent GitHub approval. Isolated integration: `Foodex_V2` only; #2503 stays open for final UAT.
