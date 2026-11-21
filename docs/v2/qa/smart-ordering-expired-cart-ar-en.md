# V2 #3780 — public Smart Ordering expired-cart localization

- Parent: #2503. Integration branch: `Foodex_V2` **only**. No main/release/tag/production.
- Source: `backend/app/Domain/SmartOrdering/CartService.php::authorized`, public `GET /api/smart-ordering/carts/{cart}` via `SmartOrderingController::showCart`.
- Static defect: an expired active cart, whose public ID and SHA-256 bearer token match, returned HTTP 410 with hard-coded English `Cart expired.` irrespective of Arabic locale.
- Change: use `__('smart_ordering_cart.expired')` with paired AR/EN catalog entries; English text is byte-for-byte unchanged. No status, response shape, token validation, expiry time, schema, endpoint, menu, permissions or fulfillment policy changes.
- Automated fixture: `backend/tests/Feature/V2SmartOrderingExpiredCartLocalizationTest.php`. Seeded B2C store/site/branch, active expired cart, AR/EN `app()->setLocale` + matching `Accept-Language`, valid token HTTP 410 exact localized `message`, forged token HTTP 404 before expiration detail, cart status unchanged and exact before/after counts for carts/items/orders/order contexts.
- Existing nonexpired successful show/checkout and independent access guards: `backend/tests/Feature/SmartOrderingCoreTest.php` (not modified because another V2 PR owns it).
- Impact: Backend public Smart Ordering human-readable 410 only. Customer Flutter: no DTO/transport/route change; response `message` already a string. Driver Flutter: none. Van Flutter: none. Dashboard: none. B2C=DRIVER ONLY / B2B=VAN ONLY unchanged. No cross-store authorization expansion.
- `REVIEW_RISK: HIGH`: public bearer-token validation. Preserve hash_equals, state/UUID lookup, fail-closed 404 for invalid token and the existing expiry guard; no sensitive token logged or returned.
- Verification required: exact-head PHP lint, Pint, PHPStan/security, localization AR/EN, full Laravel tests, MySQL/Redis install/upgrade/recovery, Repository Policy, FOODEX V2 Isolation and terminal Required CI. Record final tested SHA and workflow runs on the original issue/PR; do not merge if any required check is pending/failing. Staging/manual UAT remains part of #2503 final acceptance.
