# V2 #3765 — Authenticated quote missing-cart 409 AR/EN

Integration scope: `Foodex_V2` only. No `main`, production deployment, tags or release.

## Source, HTTP contract and placement

- Entry point: `POST /api/v1/quote` → `QuoteController::__invoke`.
- Auth: existing Sanctum-authenticated `User`, `CustomerDomainResolver::forStore` scoped to requested `store_id` and B2B/B2C channel.
- State: no owned `Cart` for the resolved customer, store and channel.
- Original defect: HTTP 409 always used English `No authenticated cart exists for this store.`
- Delta: select a supported authenticated account locale (`ar`/`en`); otherwise negotiate `Accept-Language` among `ar`/`en` and safely default to `en` if no Arabic match. Translate only this 409 message through paired `checkout_errors.quote_cart_missing` keys.
- No new UI route, Dashboard placement, schema, response field, cart write, permission, authentication, store/domain scope or quote-calculation change. Success behavior and no-cart status remain intact.

## Negative and regression source-to-test matrix

| Condition | Evidence | Expected |
| --- | --- | --- |
| B2B authenticated customer, absent cart, AR account with EN header | `V2QuoteMissingCartLocaleTest::test_missing_owned_cart_is_bilingual_conflict_for_both_channels_without_writes` | HTTP 409, exact Arabic `message`, no carts/items |
| B2C authenticated customer, absent cart, EN account with AR header | same | HTTP 409, exact English `message`, no carts/items |
| Both channels, both supported account locales | same | AR/EN paired copy, account preference wins over header |
| Unsupported account locale (`fr`), AR/EN regional headers | `V2QuoteMissingCartLocaleTest::test_unsupported_customer_locale_falls_back_to_supported_accept_language` | HTTP 409, negotiated supported message, no carts/items |
| Anonymous actor | `V2QuoteMissingCartLocaleTest::test_quote_still_requires_authentication` | HTTP 401; no cart writes |
| Valid B2B/B2C cart quote and store-authoritative price | Existing `AuthoritativePricingQuoteTest::test_retail_quotes_are_store_authoritative_and_cross_store_products_are_rejected` and `test_wholesale_quote_uses_assigned_tier_moq_increment_and_pack_rules` | Unchanged success, stored scope, pricing and cross-store rejection |

## Per-surface impact

| Surface | Effect |
| --- | --- |
| Backend/API | Affected: localized human-readable error copy for existing 409; identical method, path, status and JSON field |
| Dashboard | Not affected: no web/finance/admin route or UI change |
| Customer Flutter | Existing error message becomes bilingual; no source, DTO, field, enum, route, authentication, transport or public API shape changes |
| Driver Flutter | Not affected: driver does not consume authenticated customer quote |
| Van Flutter | Not affected: van workflow does not consume authenticated customer quote |

No linked new mobile implementation issue is warranted because no mobile source, behavior, authorization boundary or response-shape change was introduced. Full V2 final manual/device/staging UAT remains with #2503.

## Review and release gate

`REVIEW_RISK: HIGH` (authenticated customer/cart ownership boundary). Review `QuoteController.php` for unchanged validation, authentication, scope, read-only 409 and successful quote paths. Review AR/EN translation parity and the seeded B2B/B2C/no-write tests; verify lack of affected Customer/Driver/Van transport changes. Record a separate PR technical review (COMMENTED audit, not independent approval). Exact-final-head Repository Policy, FOODEX V2 Isolation, AR/EN, full Laravel, required MySQL/Redis and terminal Required CI must be SUCCESS before a guarded merge into `Foodex_V2`. Leave #2503 OPEN.
