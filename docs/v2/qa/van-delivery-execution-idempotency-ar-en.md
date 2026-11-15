# V2 #3815 — Van delivery execution Idempotency-Key HTTP 422 AR/EN

- Integration: `Foodex_V2` only; isolated branch `fix/3815-v2-van-execution-idempotency-ar-en`. Parents #2739 and #2503. No `main`, production, tags, releases or APK promotion.
- Existing authorized API: `POST /api/v1/van/orders/{order}/execution/{transition|proof|fail|retry}`. Keep `app:van` Sanctum, runtime current Van assignment, actor/order tenancy, B2B-only fulfillment, delivery state machine, idempotent replay and all financial/stock guards unchanged.
- Change only the field-level `errors.idempotency_key.0` human text of existing HTTP 422 validation for missing, shorter-than-eight, longer-than-128 or invalid-character headers. Prefer authenticated Van actor persisted `locale` (ar/en) over contradictory `Accept-Language`; fall back to supported Accept-Language for unset/unknown saved locale. Preserve English original verbatim and Arabic paired key.
- Seeded `VanDeliveryExecutionTest::test_van_execution_rejects_invalid_idempotency_headers_in_saved_actor_language_without_writes`: 2 persisted locales × 4 rejected headers × 4 authenticated mutation routes. Check exact field message and no order status transition, no Van execution state/event and no order payment. Existing positive B2B lifecycle, replay, proof and cross-Van negative tests remain authoritative.
- Impact matrix: **Van** = localized HTTP validation text only; no endpoint, JSON key, DTO, Flutter or route changes. **Customer** = no API/mobile change. **Driver** = no API/mobile change, B2C DRIVER ONLY preserved. **Dashboard** = no UI/navigation change. No direct financial/schema/WMS writes changed. This is not acceptance of parent #2739.
- Risk: **HIGH** due to protected Van execution endpoint. File-by-file engineering review, exact-final-head policy, V2 isolation, backend tests, PHP formatter/static analysis, localization and terminal CI are mandatory. Same-account COMMENTED technical audit is not an independent approval. Record tested and merged SHA before code-complete closure; #2503 retains staging/device/UAT.

## Exact negative HTTP cases and assertion boundary

| Header | Expected |
| --- | --- |
| Blank/missing `Idempotency-Key` | `errors.idempotency_key.0`, HTTP 422 |
| `tiny` (shorter than eight) | Same field, no execution service call |
| `invalid key` (space) | Same field, forbidden character |
| 129 ASCII `x` characters | Same field, exceeds 128 |

Each runs against the four existing protected mutation actions for persisted Arabic and English Van operators with the opposite `Accept-Language` preference. The test checks unmodified B2B order status, assigned execution state, no execution events and no order payments; successful lifecycle/replay and cross-Van cases remain covered separately.
