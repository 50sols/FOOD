# V2 #3814 — public merchandising event Idempotency-Key HTTP 422, AR/EN

**Integration:** `Foodex_V2` only. No main/release/production. Parent #2503.

## Source contract and exact change

- Route: `POST /api/smart-ordering/carts/{cart}/merchandising/events` in `backend/routes/smart-ordering-api.php`, public restaurant QR/cart API.
- `SmartOrderingMerchandisingController::event` preserves the input validation, `trim`, key length range **8–100**, HTTP 422, JSON `message`, and existing success HTTP 202 response. Only the existing English error text becomes an AR/EN translation selected from supported `Accept-Language` preferences, falling back to the configured application locale (or safe `en`).
- Exact English: `Idempotency-Key is required.`; Arabic: `مفتاح منع التكرار (Idempotency-Key) مطلوب.`. New paired language keys live in `backend/lang/{ar,en}/smart_ordering_merchandising_event.php` to avoid conflicts with existing Admin merchandising catalogues.
- `context()`, `CartService::authorized`, site/store/branch/customer resolution, `SmartOrderingMerchandisingService::trackPublicEvent`, event deduplication, transaction and event persistence are **not changed**.

## Negative, functional and security evidence

- `V2SmartOrderingMerchandisingEventKeyLocaleTest` uses real Laravel HTTP POST with valid event shape but missing, short (5) or overlength (101) headers, and supported regional `ar-KW` / `en-US` headers. It asserts precise localized HTTP 422 `message` and zero new cart or merchandising-event rows.
- Unsupported `fr-FR` Accept-Language is tested with Arabic app-locale fallback.
- Invalid header returns before cart lookup, so test cart UUID is intentionally absent; **this does not purport to prove successful authorized cart context**. Full-suite existing positive and replay regression `SmartOrderingCoreTest::test_sold_out_recommendation_is_suppressed_and_add_conversion_is_idempotent` must also pass and proves valid cart-based event creation and deduplication.
- Preserve original malformed event payload validation, token-based cart authorization, cross-restaurant isolation and no changes to event write paths. Do not infer manual/staging success from automated checks.
- REVIEW_RISK: HIGH because this is the public idempotent telemetry conversion boundary. Review each of the five changed paths for response compatibility, untrusted locale input, missing translations, no mutation/authorization changes and non-overlap with active workers.

## Cross-surface impact classification

| Surface | Impact |
| --- | --- |
| Backend public Smart Ordering API | Only existing invalid event Idempotency-Key **human-facing** error message becomes AR/EN; status/body structure/key length unchanged |
| Dashboard | Not affected: no navigation, route, permission, view or Admin API change |
| Customer Flutter | Not affected: restaurant QR guest endpoint is separate; no Customer DTO, route or UI change |
| Driver Flutter | Not affected: B2C DRIVER ONLY dispatch flows remain unchanged |
| Van Flutter | Not affected: B2B VAN ONLY dispatch/stock/settlement flows remain unchanged |

## Acceptance gate

Run focused test, existing positive/replay test, full Laravel and applicable PHP/Pint/PHPStan/security and localization checks. Require exact-final-HEAD **SUCCESS** for Repository Policy, FOODEX V2 Isolation and terminal Required CI including MySQL/Redis fresh-install/upgrade/recovery checks before merging the single issue PR into `Foodex_V2`. Record file-by-file same-account COMMENTED technical audit; do not represent it as independent GitHub approval. Close leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only with verified merge/SHA/run evidence. Comprehensive device/staging/business acceptance stays OPEN in #2503.
