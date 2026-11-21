# V2 #3834 — Public Smart Ordering Merchandising cart bearer HTTP 401, AR/EN

## Scope
Source parent #2503; integration `Foodex_V2` ONLY. No main, release or production.

The public restaurant QR/cart `GET /api/smart-ordering/carts/{cart}/merchandising?placement=cart_upsell` and `POST /api/smart-ordering/carts/{cart}/merchandising/events` both call `SmartOrderingMerchandisingController::context`. Before this fix a missing or whitespace-only `X-Smart-Cart-Token` produced English `Cart token is required.` even for an Arabic guest. This is independent of the completed root-cart `SmartOrderingController::cartToken` localization (#3788) and the merchandising event Idempotency-Key 422 localization (#3814).

The original missing-token HTTP401, `message` JSON shape and token trimming remain unchanged; the error now calls the already-existing paired `smart_ordering.cart.token_required` AR/EN key. Select the first supported `ar/en` preferred request language (regional variants supported) or a supported configured application default, falling back to en. Exact English remains `Cart token is required.`; Arabic key remains `رمز سلة الطلب الذكي مطلوب.`. No language files, contracts, stored data, route, model or authorization changed.

## Real HTTP / risk review

- New `V2SmartOrderingMerchandisingCartTokenLocaleTest`: real GET placement and POST event with **valid** event key/shape, using absent and whitespace-only bearer headers for `ar-KW` and `en-US`, deliberately conflicting app-default locale to prove header selection, exact 401 message and no writes to cart/items/events/orders/payments/context.
- Unsupported `fr-FR` falls back to the configured Arabic app locale.
- Route UUID is intentionally unknown: the missing bearer is rejected **before** any cart lookup; this regression does not claim valid cart or cross-store success. The existing seeded `SmartOrderingCoreTest::test_sold_out_recommendation_is_suppressed_and_add_conversion_is_idempotent` remains the independent positive/duplicate-event contract, and the full Laravel suite must pass.
- HIGH security risk classification because the denial is at a public bearer-token boundary. Neither `CartService::authorized`, branch/site/customer resolution, event key validation, `trackPublicEvent`, event deduplication nor store/channel scoping changes. Invalid/missing token cannot gain access; do not infer all cross-store scenarios from the isolated missing-token test alone.

## Cross-surface impact

| Surface | Impact |
| --- | --- |
| Backend Smart Ordering public API | Only missing bearer token human error message on existing merchandising GET/POST becomes AR/EN; status and JSON envelope unchanged |
| Dashboard | No effect: no UI, route, permission, navigation or administrative behavior change |
| Customer Flutter | No code/DTO/transport effect: public restaurant QR API error text only, existing error handling unaffected |
| Driver Flutter | No effect; B2C DRIVER ONLY remains unchanged |
| Van Flutter | No effect; B2B VAN ONLY remains unchanged |

## CI and closure
Run targeted HTTP tests, existing successful idempotent merchandising workflow, PHP/Pint/PHPStan/security, AR/EN localization and full Laravel; exact-final-HEAD Repository Policy, FOODEX V2 Isolation and terminal Required CI with real MySQL/Redis install/upgrade/recovery acceptance must all SUCCESS before SHA-guard merge to `Foodex_V2`. A HIGH file-by-file same-account COMMENTED technical audit is required and must not be represented as independent GitHub APPROVED. Close as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only with verified integration SHA, CI and audit evidence; manual device/staging/business source acceptance stays open at #2503.
