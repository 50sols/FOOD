# V2 #3782 — Guest cart selling-unit merge conflict, AR/EN

## Existing route, actor and security boundary
`GET /api/v1/cart?store={storeId}` with a valid `X-Guest-Token` merges a B2C guest cart into the authenticated Customer's B2C store cart. Canonical `CustomerDomainResolver`, active store/channel checks and guest token ownership remain unchanged. A B2B actor is still rejected; cross-store guest cart remains rejected. When both carts contain the same product in different selling units, `mergeGuestCart` rejects before merging their line and preserves the original 409 response shape. Existing `localizedCartMessage` selects supported signed-in User.locale ar/en before Accept-Language fallback.

## Patch and tests
The only runtime change replaces the existing literal English 409 response with `smart_ordering_cart.merge_selling_unit_conflict`, sourced from paired AR/EN catalogs. English copy stays byte-for-byte identical. The seeded `CartDomainTest::test_guest_cart_merge_selling_unit_conflict_is_bilingual_and_preserves_both_carts` constructs a valid BAG selling-unit guest line and a pre-existing base-unit owned line, then sends real Sanctum API requests under Arabic and English saved account locales with deliberately contradictory Accept-Language. It asserts exact translated JSON `message`, 409, unchanged guest token and both carts/lines, unchanged cart counts, orders, payments, audit and reserved inventory. Existing success/reprice, other-store, B2B and stock tests remain.

## Surface impact
- Backend/API: existing human-readable 409 message only; URL/method/schema/auth/permission/store/channel/transaction unchanged.
- Customer Flutter: existing localized error-copy consumption only; no DTO, route, enum, UI or native code changes. No new mobile implementation issue is required absent a demonstrated contract change.
- Driver Flutter: not affected; B2C DRIVER ONLY assignment unchanged.
- Van Flutter: not affected; B2B VAN ONLY assignment unchanged.
- Dashboard: not affected; no permissions, views, Sidebar or navigation changes.

## Engineering audit and acceptance
REVIEW_RISK: HIGH due to merge integrity, stock and channel boundary. Review the five paths, negative HTTP regression, translation parity, no leak and no-write invariants; merge only on exact final PR HEAD green Repository Policy, V2 Isolation and full Required CI (Laravel, PHP syntax/Pint/PHPStan/security, AR/EN and MySQL/Redis). Manual staging/device acceptance stays at #2503. **No main, release, tag, install, production or deployment.**
