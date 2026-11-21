# V2 #3763 — Guest cart token validation AR/EN (HTTP 422)

**Source:** Guest B2C browsing contract `backend/docs/GUEST_BROWSING.md`; public REST /cart and /cart/items, `docs/api/openapi.yaml` `GuestToken` header / `ValidationError` 422. **Integration:** `Foodex_V2` ONLY. Parent #2503 stays OPEN. This is distinct from issue #3737's HTTP 409 store/channel isolation already merged into `Foodex_V2`.

## Functional change
The existing `GuestCartController::guestToken()` checks remain unchanged: missing token is allowed for guest GET/init, required for guest PATCH/DELETE item ownership; present token is still trimmed and must be between 16 and 200 characters. Only the **two validation error strings** now use server-side paired `smart_ordering_cart.guest_token_required` and `guest_token_invalid` AR/EN translations, through the existing explicit locale resolver (renamed from `conflictMessage` to `localizedCartMessage` since it now serves both 409 and 422 errors). For authenticated Sanctum actors, supported profile locale wins; anonymous clients use supported `Accept-Language` with Arabic fallback. `ValidationException::withMessages` retains the exact `errors.X-Guest-Token` JSON key and HTTP 422.

No new token parsing behavior, additional queries, cart creation, item mutation, session state, auth bypass, customer domain selection, store/channel/fulfillment control, API schema, errors envelope, or migration. Existing 409 cross-store/channel and positive guest cart behaviors remain unaffected.

## Real HTTP acceptance and negative assertions
`backend/tests/Feature/GuestBrowsingTest.php`, seeded with two Retail stores, product catalog, promotions:
- Anonymous **short (5) and too-long (201) token** over GET /api/v1/cart?store and POST /api/v1/cart/items, AR and EN: exact localized `errors.X-Guest-Token.0`, 422, no Cart/CartItem DB writes.
- Anonymous **missing token** on PATCH and DELETE item after creating a valid cart/item, AR and EN: 422 with exact required-token error, unchanged Cart/CartItem count and existing quantity.
- **Guest GET without token**: still 200 and initializes fresh empty cart.
- **Authenticated** actor with explicit `locale=ar/en` and opposite `Accept-Language`: exact profile-localized invalid-token 422 before any cart/customer creation, no DB writes.
- Existing GuestBrowsing and CartDomain successful/negative paths remain in full required backend CI.

## Impact matrix
| Surface | Impact | Evidence |
|---|---|---|
| Backend / API | Affected: two validation error strings only | Identical 422/JSON key/token parsing; seeded no-write regression |
| Dashboard | Not affected | No admin/dashboard/controller/permission/nav code changed |
| Customer | Existing human-readable error message now AR/EN | No Flutter source, DTO, HTTP field, auth, routing, token boundary, locale setting or API signature changed |
| Driver | Not affected | No Driver API or dispatch routes changed; B2C DRIVER ONLY preserved |
| Van | Not affected | No Van API or dispatch routes changed; B2B VAN ONLY preserved |

**Risk:** HIGH because customer/guest token boundary. A source-level COMMENTED engineering review must check no path traversal, information disclosure, tenant/store bypass or state write. It is not an independent APPROVED review. Final merge into `Foodex_V2` requires exact-final-head Repository Policy, V2 Isolation Gate and terminal Required CI SUCCESS, including Laravel full suite, AR/EN key parity, PHP lint/Pint/static/security/OpenAPI and MySQL/Redis acceptance. Any external/device/staging UAT remains tracked by #2503. No main/production/release/tag/APK/cPanel operation is authorized.
