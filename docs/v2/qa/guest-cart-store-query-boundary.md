# FOODEX V2 #3926 — Guest cart store selector validation

- Parent source: Customer #2733 and V2 master #2503. Isolated coding leaf; target `Foodex_V2` only.
- Existing route: public/authenticated `GET /api/v1/cart` (`GuestCartController::show`). Existing OpenAPI `/cart` GET already specifies optional `store` as integer >=1 and field-scoped 422 response, so no shared OpenAPI mutation is necessary.
- Root cause: `Request::integer('store')` silently converted malformed user-controlled inputs to 0 before guest-token or authenticated cart context resolution.
- Fix: validate the actual GET query bag with `sometimes|required|integer|min:1` before using a supplied store selector. A missing selector is still allowed with an existing token. Malformed selectors raise HTTP422 `errors.store` in the existing actor/preferred-language localization flow. All valid store IDs and the existing guest-cart response/X-Guest-Token are unchanged.
- Security: no permission grant, Store ownership change, schema migration, role change, user secrets or new persistence. B2C Guest token retains its canonical store boundary, valid mismatched store still HTTP409, and guest B2B/inactive stores remain HTTP404. Existing auth Customer B2B/B2C scope is unchanged. Invalid requests create no carts, items or audit writes.
- Customer: uses existing guest/public cart route and unchanged successful response model. Only invalid explicitly supplied selectors now return 422. Driver: not a guest cart consumer. Van: not a guest cart consumer; B2B=VAN only, B2C=DRIVER only unaffected.
- AR/EN and RTL/LTR: paired translation keys in `backend/lang/{ar,en}/guest_cart_store_query.php`, seeded tests assert exact localized 422. No HTML screen, UI text or layout changes; device/staging UAT remains in #2733/#2503.
- Tests: `php artisan test --filter=V2GuestCartStoreQueryValidationTest`, existing `GuestBrowsingTest`, PHP syntax/Pint/PHPStan/security/OpenAPI + exact-head Repository Policy/V2 Isolation/Required CI including real MySQL/Redis deployment acceptance. Do not claim success before runs finish.
- Rollback: revert only issue-scoped controller validation plus translation/tests/docs. No data schema/irreversible migration.
- Risk: HIGH because public cart query and authenticated customer cart scope. Require file-by-file technical audit and negative verification.
