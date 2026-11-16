# V2 #3801 — Bilingual existing-empty Customer quote HTTP409

**Integration:** `Foodex_V2` only; parent #2503 remains open for staged/manual acceptance. No `main`, production/cPanel, releases, tags, ZIP/APK publishing.

## Source and defect

`POST /api/v1/quote` authenticates the Customer, resolves the authorized B2B or B2C store/customer, then fetches the owned `carts` row. Two distinct negative cases exist:
- **Missing owned cart** is already handled by `QuoteController` with `checkout_errors.quote_cart_missing` (#3765, previously merged). **Not modified.**
- **Existing owned cart with zero `cart_items`** reaches `CommerceQuoteService::quoteCart(..., strict: true)` and was rejected by HTTP409 with hard-coded English `The cart is empty.`. That exact guard now renders `checkout_errors.quote_cart_empty` from the paired Arabic/English catalogs. The English string, status and JSON `message` envelope are preserved. Supported persisted actor `User.locale` takes precedence over contradictory `Accept-Language`. For other locales, HTTP negotiation selects supported Arabic or English, default English.

The empty-cart branch executes before pricing recalculations and the `cart_items` snapshot update loop. No authorization, tenant, channel, store, credit, price, coupon, payment, stock, transaction, idempotency, schema, route or mobile DTO change.

## Real HTTP regression

`backend/tests/Feature/V2QuoteEmptyCartLocaleTest.php`:
- Creates isolated active Wholesale and Retail stores with seeded reference data, registers canonical Platform Customers, authenticates with Sanctum and opens their own existing, empty carts through `GET /api/v1/cart?store=...`.
- For each B2B/B2C actor, persists both `ar` and `en` (with opposite `Accept-Language`), then invokes real `POST /api/v1/quote`. Asserts HTTP409 and exact translated message.
- Confirms no cart/cart-item/order/order-item/invoice/payment/audit row count changes, no inventory quantity or reserved quantity mutations and same authorized empty cart persists.
- Checks unsupported saved `fr` locale negotiating `ar-KW` and `en-US` headers. Unauthenticated quote remains HTTP401, with no new cart.
- Existing positive pricing, Customer Cart and Checkout regressions remain unchanged. These new tests complement, not replace, full Laravel/MySQL CI.

## Per-surface impact matrix

| Surface | Code impact | Existing contract |
|---|---|---|
| Backend / API | Localized error **only** in existing strict empty-cart quote guard | Same auth, 409, JSON message, store/channel guards |
| Dashboard | Not affected | No new form, route, sidebar or authorization |
| Customer Flutter | No app-code or DTO change; customer-facing error string improves | B2B/B2C price/checkout contracts unchanged |
| Driver Flutter | Not affected | B2C DRIVER ONLY unchanged |
| Van Flutter | Not affected | B2B VAN ONLY unchanged |

**REVIEW_RISK: HIGH** (commercial pricing boundary). Required engineering file-by-file review must verify the strict rejection runs before any mutations; preserve existing actor/store/customer resolution and preceding missing-cart guard. Same-account COMMENTED review is not an independent GitHub APPROVED review.

**Integration gate:** Repository Policy, FOODEX V2 Isolation, PHP syntax/Pint/PHPStan/Composer security/OpenAPI, AR/EN quality, complete Laravel feature suite, MySQL/Redis real install-upgrade-recovery acceptance and terminal `required-ci-gate` must all pass **on exact final branch HEAD** before SHA-guarded PR merge to `Foodex_V2`. Only then close this implementation leaf `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; staging/manual Customer display and full V2 business acceptance remain under #2503.
