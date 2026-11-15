# V2 #3767 — Smart Ordering loyalty redemption missing Idempotency-Key AR/EN

Base: `Foodex_V2` only; no `main`, production, tag or release.

## Source and HTTP contract
- Route: `POST /api/v1/smart-ordering/customer/loyalty/rewards/{reward}/redeem` under `auth:sanctum`, `active.user`, `smart.ordering.site`, and rate limiting. The URL's reward value must be a UUID.
- Actor: authenticated B2C customer resolved through `SmartOrderingCustomerController::context`, restaurant site and customer's `b2c_customers.store_id` association.
- Original defect: absent, blank or whitespace `Idempotency-Key` produced an English-only HTTP 422 `Idempotency-Key is required.`.
- Change: paired AR/EN `smart_ordering_customer.idempotency_required` translation with strict account locale preference (`ar`/`en`) else supported `Accept-Language` negotiation and safe English fallback.
- No change to key normalization, HTTP status, reward ID validation, payload fields, tenant authorization, entitlements, loyalty ledger, idempotent redemption, locking, API shape or valid-key path.

## Source-to-test and negative behavior
| Case | Test | Expected |
| --- | --- | --- |
| Supported Arabic customer locale, English header, empty key | `SmartOrderingCustomerGrowthTest::test_loyalty_redemption_missing_idempotency_key_is_bilingual_and_never_spends_points` | 422, Arabic message, zero ledger/accounts rows |
| Supported English customer locale, Arabic header, empty key | same | 422, English message, no writes |
| Unsupported customer locale, Arabic Kuwait header, whitespace key | same | 422, Arabic message, no writes |
| Unsupported customer locale, English US header, whitespace key | same | 422, English message, no writes |
| Valid idempotent loyalty redemption | Existing `SmartOrderingCustomerGrowthTest::test_loyalty_redemption_is_idempotent_locked_and_never_goes_negative` | Same replay, balance and locking behavior |

## Surface impact
- Backend/API: human-readable missing key HTTP 422 translation only.
- Dashboard: not affected (no admin route or UI changed).
- Smart Ordering restaurant web/customer: current 422 copy becomes bilingual, with no route/field/transport change.
- FOODEX Customer Flutter: not affected in Flutter source, DTO, route or auth; no change to three-app checkout.
- FOODEX Driver Flutter: not affected.
- FOODEX Van Flutter: not affected.

No mobile issue needed absent genuine mobile contract changes. Manual/staging UAT belongs to master #2503.

## Audit and gates
`REVIEW_RISK: HIGH` due customer loyalty/points and idempotency boundaries. Inspect `SmartOrderingCustomerController.php` to ensure no scope or points mutation before validation; review paired catalog and seeded HTTP tests. Record file-by-file `TECHNICAL_REVIEW` as a COMMENTED review (same account, not independent approval). Require exact final HEAD Repository Policy, FOODEX V2 Isolation, AR/EN, backend PHP syntax/Pint/PHPStan/Composer audit/OpenAPI, full Laravel tests, MySQL/Redis install/upgrade/recovery and terminal Required CI SUCCESS before guarded merge into `Foodex_V2`; close coded leaf only with merged SHA/CI evidence. Master #2503 stays OPEN.
