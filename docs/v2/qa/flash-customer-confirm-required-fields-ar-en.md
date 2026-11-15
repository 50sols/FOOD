# V2 #3853 — Customer Flash confirmation missing fields AR/EN

## Reuse and source
- Parent #2503; existing `POST /api/v1/flash-reservations/{reservation}/confirm`; no new UI, route, DTO or permission.
- Existing `FlashOfferController::confirm` requires `store_id`, `address_id`, and `payment_method` for Customer checkout, and already rejects missing/empty values as HTTP422 `errors.<field>.0`. One hard-coded human English literal was localized with paired `flash_checkout.customer_required_field` keys.
- Authenticated User persisted `ar/en` wins over conflicting `Accept-Language`. Only when unsupported, negotiate supported regional `ar-KW` / `en-US` preference, default Arabic. Original English is unchanged.

## Security, negative and positive test contract
- Real seeded Retail B2C store/product/Flash offer and reservation, live Customer record/address and authenticated Sanctum actor; supply a valid `Idempotency-Key` with requests.
- For each of `store_id`, `address_id`, and `payment_method`, omit that one field and verify exact localized HTTP422 `errors.<field>.0`; saved ar/en vs contradictory header and unsupported actor locale vs supported regional header.
- Error occurs before Customer checkout service or any inventory/finance/notification writes. Regression asserts the reservation remains active with null order and count invariants for reservations, Flash events, orders/order items, invoices, payments, audit logs, stock movements and reserved inventory.
- Preserve the existing correct full Customer Flash confirm HTTP201, invoice/reservation/order creation, stocked reservation behavior and idempotent replay. No arbitrary input is logged or reflected; no secrets or cross-store data revealed by the new message.
- No schema, user-role, tenant/store/permission, channel, checkout formula, price, stock allocation, idempotency or write-transaction changes. The source change is one localized message in a preexisting fail-closed branch.

## Impact and navigation matrix
| Surface | Impact | Existing workflow |
|---|---|---|
| Backend/API | Existing 422 error text only | Reuse existing authenticated Flash reservation confirm route |
| Dashboard | No impact | No admin page/Sidebar/permission/action modifications |
| Customer | Localized message only | Existing Flash offer purchase/confirmation; no Flutter, navigation, DTO, status, payload or API schema changes |
| Driver | No impact | B2C DRIVER ONLY fulfillment routing untouched |
| Van | No impact | B2B VAN ONLY fulfillment routing untouched |

## Risk audit and rollback
**REVIEW_RISK HIGH** — authenticated checkout, inventory, payments and Customer scope nearby. Review file-by-file:
1. `backend/app/Http/Controllers/Api/V1/FlashOfferController.php`: add only supported actor-header locale selection and replace one fixed 422 message; original field loop and guard unchanged.
2. `backend/lang/ar/flash_checkout.php`: one Arabic key.
3. `backend/lang/en/flash_checkout.php`: matching key and exact prior English.
4. `backend/tests/Feature/FlashOfferBackendTest.php`: real seeded HTTP negative matrix before existing positive checkout; missing-field errors and no-write assertions.
5. This QA: trace, impact, threat, rollback, exact-head acceptance.

Rollback only these message/catalog/test/docs changes, no migration/data restoration needed. Same-account `COMMENTED` file-by-file audit is NOT independent `APPROVED` review. Must have exact-final-head Repository Policy, FOODEX V2 Isolation, Laravel/Pint/PHPStan/security, AR/EN, MySQL/Redis install-upgrade-recovery, terminal Required CI all SUCCESS before SHA-guarded PR merge ONLY to `Foodex_V2`, then CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED closure with source/CI evidence. Parent #2503 stays OPEN for final staging/device/manual UAT. NO main, release or production.
