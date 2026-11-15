# V2 #3774 — Authenticated cart missing store context, AR/EN HTTP 422

**Integration:** `Foodex_V2` ONLY. Parent master #2503 remains OPEN. Existing cart contracts: `backend/app/Http/Controllers/Api/V1/GuestCartController::show`, `backend/docs/GUEST_BROWSING.md` and `docs/api/openapi.yaml` /cart ValidationError. No main, production, release, APK or cPanel changes.

## Correctness and security
`GET /api/v1/cart` with an authenticated Sanctum Customer, without a positive explicit `store` and without an existing guest token, must reject with **HTTP 422** and `errors.store.0`. The source already enforces this condition before resolving customer context or opening a cart. Only its English-only human error was localized through `smart_ordering_cart.authenticated_store_required` (AR/EN paired keys) and the existing `localizedCartMessage($request, key)` allowlisted locale function. Authenticated supported `User.locale` takes precedence over contradictory `Accept-Language`; unauthenticated fallback behavior is unchanged. The English original wording is preserved exactly.

No new store fallback, hidden Retail/Wholesale selection, guest token downgrade, Cart/CartItem/Order creation on an invalid request, altered status or JSON field, migrations, app routes, permissions, pricing, inventory, dispatch or tenant/channel scope. A valid explicitly selected Retail store still uses existing `CustomerDomainResolver` authorization and can create its normal cart. Guest GET with a valid Retail store and no token remains public.

## HTTP acceptance
`backend/tests/Feature/CartDomainTest.php` seeds Customer/User, Retail+Wholesale stores and inventory. For the same persisted B2C actor, locale `ar` with `Accept-Language: en` and locale `en` with `Accept-Language: ar`, `GET /api/v1/cart` without store/guest token must return exact translated HTTP 422 `errors.store.0`, with identical cart, cart_items and order counts. With an explicit valid Retail store, the same authenticated Customer obtains HTTP 200, scoped channel `b2c`, and one new empty cart. Existing CartDomain/GuestBrowsing positive/cart merge/cross-store/B2B negative tests remain in Required CI.

## Impact matrix
| Surface | Impact and evidence |
|---|---|
| Backend / API | User-facing 422 string only; no change to `errors.store`, status, auth, store resolution, schema, or writes |
| Dashboard | Not affected, no route/nav/permission/UI files |
| Customer | Existing error text localized; no Flutter runtime, route, response DTO, auth or transport change |
| Driver | Not affected; no driver API/dispatch changes; B2C DRIVER ONLY unchanged |
| Van | Not affected; no van API/dispatch changes; B2B VAN ONLY unchanged |

**Risk HIGH** due to authenticated customer/store identity. Technical review must inspect all 5 touched paths, fail-closed/no-write behavior, hostile header/language precedence and possible leakage or fallback. Same-account COMMENTED engineering audit is *not* independent GitHub APPROVED review. Exact final-HEAD Repository Policy, FOODEX V2 Isolation, AR/EN, full Laravel PHP/Pint/static/security/OpenAPI, MySQL/Redis installation/upgrade/recovery and terminal Required CI success must precede any guarded Foodex_V2 squash merge. Consolidated staging/device/UAT and ERP source acceptance remain under parent #2503.
