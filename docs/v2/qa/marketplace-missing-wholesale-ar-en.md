# V2 #3740 — Public Marketplace missing principal Wholesale 404 AR/EN

**Parents:** #2733 Customer storefront and #2503 final V2 acceptance. **Integration:** `Foodex_V2` ONLY; not `main` or production.

## Source, placement and protocol

Existing unauthenticated `GET /api/v1/marketplace` (public Customer marketplace) is implemented in `StorefrontController::marketplace`. The first query must identify an **active B2B Wholesale store**. The original abort returns HTTP 404 with a fixed English message if none exists; it must never fabricate a Wholesale principal or substitute an active B2C Retail store.

Change only the human-readable error to the paired server translation `platform_marketplace.wholesale_store_missing` selected through the request's `Accept-Language` language negotiation (existing `PublicInformationController` convention). Preserve the original EN message byte-for-byte, HTTP 404 and JSON `message` key. No route/auth, query, store selection, 200 payload, caching, persistence or entitlement change. Existing Marketplace screen/RTL/LTR layout is unchanged.

## Negative and positive evidence

`V2MarketplaceMissingWholesaleLocaleTest` runs an actual public HTTP request on a seeded Laravel `RefreshDatabase` fixture. It verifies active Wholesale marketplace 200, deactivates **all** B2B stores while retaining a distinct active Retail store, then asserts 404 localized error for `ar-KW` and `en-US`, no false Retail-to-Wholesale fallback, no store/order/cart/audit mutations and no requirement for an authenticated user.

## Surface contracts

- **Customer:** existing public Marketplace 404 message is localized; response status/schema and valid data remain unchanged, with no Flutter source/DTO change
- **Dashboard:** no controller, admin page, navigation or permissions changed
- **Driver:** no API/Flutter behavior affected, B2C orders remain DRIVER ONLY
- **Van:** no API/Flutter behavior affected, B2B orders remain VAN ONLY

## Review and acceptance

REVIEW_RISK: HIGH (public authoritative Wholesale principal and channel boundary). Source review must verify missing-store failure remains fail-closed and no data or active tenant scope leaks; same-account COMMENTED audit is not independent GitHub APPROVED. Require exact-final-head Repository Policy, FOODEX V2 Isolation, AR/EN key parity, PHP/Pint/PHPStan/security, full Laravel, MySQL/Redis and terminal required-ci-gate SUCCESS before SHA-guarded squash merge into `Foodex_V2`. Staging, device and final end-to-end acceptance remain in open #2503. No main/production/release/APK/ZIP operation.
