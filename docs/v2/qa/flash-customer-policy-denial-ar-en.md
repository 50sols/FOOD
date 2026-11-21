# V2 #3895 — Customer Flash policy denial AR/EN HTTP422

Parent Customer #2733; V2 program #2503. Integration target: `Foodex_V2` **only**. No `main`, release, signed package, APK distribution, cPanel or production changes.

## Verified authoritative source and distinction

The existing authenticated `POST /api/v1/flash-offers/products/{offerProduct}/reserve` calls `CustomerDomainResolver::forStore` before any reservation, checks finite converted base quantity, then asks `CommercialPolicyService::evaluate` to authorize the Customer product/quantity. When `allowed=false`, the former `ValidationException` used a hardcoded English human sentence and appended internal commercial `reason_codes`. The prior #3884/PR #3887 translated only the **distinct non-finite converted base quantity guard**. This change preserves its behavior.

## Minimal runtime delta

- Only `FlashOfferController::reserve` Customer commercial **denial text** changes, to the paired `flash_checkout.commercial_policy_rejected` key in `lang/ar` and `lang/en`.
- Locale resolution: stored authenticated `users.locale` of `ar` or `en` overrides a contradictory header; unsupported stored locale uses `Accept-Language: ar-KW` or `en-US`, with Arabic default.
- The returned status remains 422; JSON continues to include `message` and `errors.flash_offer[0]`. No new response fields; internal reason codes remain decision-only, not human display copy. No policy/stock/pricing/service, auth, tenant/channel, validation, route, idempotency, order/finance, migration, or DB-schema modifications.
- Do **not** interpret localized error copy as authorization. Only `CommercialPolicyService::evaluate` makes the policy decision. No bypass, fallback reservation or extra write is introduced.

## Regression and negative security matrix

The existing real seeded `FlashOfferBackendTest::test_customer_flash_contract_converts_reservation_to_order_invoice_quota_and_order_inventory_reservation` uses Sanctum B2C Customer/Store, actual Flash Offer, 10x conversion, stock and commercial flags. It now temporarily deactivates the product to force a canonical policy `PRODUCT_INACTIVE` denial with an otherwise valid finite quantity; no mocks or bypass.

| Persisted actor locale | Header | Exact 422 human copy |
| --- | --- | --- |
| `ar` | `en-US` | Arabic |
| `en` | `ar-KW` | English |
| Unsupported `fr` | `ar-KW` | Arabic |
| Unsupported `fr` | `en-US` | English |

For every request, the test verifies exact `message` and `errors.flash_offer.0`, absence of `PRODUCT_INACTIVE` in the response, a fresh idempotency key, and identical counts for Flash reservations/events, stock movements, commercial quota reservations, orders, payments, invoices, audit logs and notifications, plus unchanged reserved inventory. Product active status and original saved nonnull actor locale are restored before the **existing positive HTTP201** reserve → confirm/checkout → order/stock assertions.

Previous #3884 HTTP `PHP_FLOAT_MAX` conversion overflow + AR/EN no-write checks stay intact. Scope and error status are unchanged; revert only the four existing-file edits and this note to roll back wording without changing records or policy state. A failed policy denial does not need a DB recovery operation.

## Surface impact / navigation

- **Backend/API:** affected, human `message` and `errors.flash_offer` localized for an existing authenticated B2C Customer endpoint; status 422, field/error shape, auth and behavior unchanged. There is no new route/screen or user action.
- **Customer Flutter:** API text is affected but no Dart source or DTO/status change. Existing `apps/customer_app/lib/features/retail/offers/customer_flash_offers.dart::_decode` maps non-2xx to a generic exception and does not display the server message; this is **API-only localization**, not a claim of new native visible translated text.
- **Driver:** not affected; no Driver API, B2C dispatch/assignment, UI or tests changed. B2C fulfillment remains DRIVER ONLY.
- **Van:** not affected; no Van wholesale Flash service or B2B fulfillment/stock/route change. B2B fulfillment remains VAN ONLY.
- **Dashboard:** not affected; no Admin page, placement, navigation, permission, Blade or asset changes.
- **Data/security:** no auth/tenant/RBAC, schema, migrations, queues, receipts or read/write-path changes. Existing server-side policy denial and store isolation remain authoritative.

## Acceptance and audit gates

**REVIEW_RISK: HIGH** (authenticated Customer stock/finance adjacency). Before merging the original one-issue/one-branch PR, record a distinct file-by-file same-account COMMENTED technical audit (**not** independent GitHub APPROVED), inspect exact-head diff, current leases and all relevant terminal CI results: Repository Policy, FOODEX V2 Isolation, localization AR/EN, Backend PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel, MySQL/Redis installation/upgrade/recovery and Required CI terminal gate. A green earlier commit, skipped required check or unverified job never authorizes merge.

Close only this atomic code-complete issue after SHA-guarded `Foodex_V2` integration and published check/merge SHA evidence; master #2503 stays **OPEN** for consolidated staging/device, business, visual and final V2 acceptance.
