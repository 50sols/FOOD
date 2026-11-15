# FOODEX V2 #3809 — Derived Flash reserve base-quantity overflow

## Source defect and scope

An already accepted V2 validation guard (#3797) rejects non-finite `sellingQuantity` at entry to `FlashOfferService::reserve`. It does **not** constrain the **derived** `$baseQuantity = round($sellingQuantity * conversion_factor, 3)`. The existing check `$baseQuantity <= 0` does not reject positive infinity. A valid finite PHP `PHP_FLOAT_MAX` with the real Flash test offering's 10x selling-unit factor overflows to `INF`. An unbounded derived allocation could then enter inventory and reservation processing.

## Implementation and tests

- In the authenticated Customer Flash API `FlashOfferController::reserve`, reject a non-finite resolved base quantity **before** calling `CommercialPolicyService::evaluate`. This prevents an overflowed finite JSON quantity reaching the shared commercial policy. Keep the existing 422 human error text.
- Extend the single existing *resolved base quantity* service HTTP 422 guard to `! is_finite($baseQuantity) || $baseQuantity <= 0`. Keep the exact existing error sentence, state machine, transaction, idempotency, audience, channel, eligibility, stock locks and all other business logic unchanged.
- The seeded `FlashOfferBackendTest::test_finite_selling_quantity_overflow_is_rejected_before_stock_writes` creates a real active B2C offer/product and authoritative conversion factor 10; verifies that `PHP_FLOAT_MAX` is finite, converted base is non-finite, and service rejects with HTTP 422 and exact existing message. It snapshots reservation, event, stock movement, orders, payments and reserved inventory before and after, ensuring no partial write. A normal finite quantity still reserves ten base units and creates one reservation and event.
- The same existing seeded real Sanctum Customer Flash HTTP journey now posts `PHP_FLOAT_MAX` to `/api/v1/flash-offers/products/{id}/reserve`, checks 422 and the exact existing response `message`, and compares reservation/event/stock/order/payment counts before and after. The original successful Customer reservation → checkout → invoice and idempotent flows remain in place.
- Existing Flash backend checkout/idempotent happy path and negative tests remain unchanged. No migration or data backfill; rollback is simply reverting the validation guard if explicitly authorized.

## Per-surface impact

| Surface | Classification and evidence |
| --- | --- |
| Backend service | Affected: rejects overflowed invalid base quantity earlier; no new persisted state, auth or role behavior |
| API / Customer | Controller adds a pre-commercial-policy finite guard on the existing public reservation route. Existing status `422` and `message` shape/copy, no added or removed DTO field or endpoint. Normal Customer reservation/checkout unchanged; finite extreme invalid input now fails closed rather than reaching stock arithmetic |
| Driver | Not affected: no Driver route or Flutter app change; B2C DRIVER ONLY fulfillment stays authoritative |
| Van | No new Van app API schema, role or entitlement change; normal B2B Van reservation path unchanged and B2B VAN ONLY preserved |
| Dashboard | No page, Admin permission, navigation, write workflow or UI change |
| AR/EN, RTL/LTR | No new human-facing literal/translation key and English error text unchanged; existing locale behavior and UI unchanged |

## Review and CI gate

REVIEW_RISK: HIGH due to potential stock/price/financial math contamination. File-by-file engineering audit must check precisely the 4-file diff (service, API controller, seeded service/HTTP tests, QA), `is_finite` behavior, rollback and no-write tests, channel/tenant invariants, app impact and whether the invalid-input error shape remains compatible.

Required before merging: latest exact PR HEAD has SUCCESS from Repository Policy, FOODEX V2 Isolation Gate, PHP syntax/Pint/PHPStan/Composer security/OpenAPI, Laravel full, AR/EN and MySQL/Redis fresh install / upgrade / recovery, terminal `required-ci-gate`. Same-account COMMENTED audit is **not** an independent GitHub approval. Merge only into `Foodex_V2` with SHA guard; close source-complete leaf with PR, tested SHA, integration SHA and CI runs. Manual staging/business sign-off remains master #2503. **No `main`, release, tags, APK, cPanel or production.**
