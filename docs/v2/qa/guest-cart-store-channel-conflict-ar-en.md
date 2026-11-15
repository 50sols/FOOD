# V2 #3737 — Guest cart cross-store/channel 409 AR/EN

**Integration:** `Foodex_V2` only. Parent #2503 stays open. No main, production, release, APK, deployment, or live users.

## Scope and reusable authority
Existing `GuestCartController` already blocks:
1. Guest `GET /api/v1/cart?store=other` with `X-Guest-Token` belonging to a different Retail store.
2. Guest `POST /api/v1/cart/items` with a token bound to another Retail store.
3. Authenticated B2B Customer merging a Retail/B2C guest token into Wholesale/B2B.
4. Authenticated B2C Customer merging a guest token bound to another Retail store.

These checks intentionally return HTTP 409 and must prevent cross-store or cross-channel cart creation/merge. The source already had AR catalog changes and several partially translated aborts on the original branch; this handoff continues that **same branch** and preserves those commits.

## Fix
Paired `smart_ordering_cart.guest_store_mismatch` and `guest_channel_mismatch` AR/EN keys now provide only human-readable conflict messages. `conflictMessage` selects supported user locale for authenticated Sanctum actors, else `Accept-Language` with an Arabic fallback for guests. Explicit translator locale avoids changing request-global locale, HTTP status, business guard order, token handling, cart ownership, inventory, pricing or order flow. The original protected merge method receives the same Request solely for localized error copy.

## Reproduction and negative assertions
- `php artisan test --filter=GuestBrowsingTest`: same guest token, other Retail store, GET + POST; AR/EN exact 409 JSON `message`; unchanged `carts` and `cart_items` counts. Original valid guest cart and item mutation tests remain.
- `php artisan test --filter=CartDomainTest`: authenticated B2C user with valid store-scoped customer profile attempts Retail cart merge from a different Retail store (AR/EN 409, no cart/item writes). Authenticated B2B user with a Retail guest token attempts B2B merge (AR/EN 409, no B2B cart and unchanged rows). Existing B2B authenticated cart success path retained.
- No migrations/roles/paths/response DTO changes. Preserve cross-store fail-closed semantics and app-cart security, B2B VAN ONLY and B2C DRIVER ONLY.

## Surface impact
- **Backend/API:** only four pre-existing 409 human-facing `message` fields localized. Token, status, channel/store authority and request/response structure unchanged.
- **Dashboard:** none (public Customer cart API only).
- **Customer:** consumes localized 409 errors in existing cart calls; transport/DTO/Flutter route unchanged; Android build not altered.
- **Driver:** no API/Flutter/dispatch change (B2C remains DRIVER ONLY).
- **Van:** no API/Flutter/dispatch change (B2B remains VAN ONLY).

**Technical review risk:** HIGH (cross-store/channel guard). Review each changed path for authorization/injection/no-write, Laravel/Pint/PHPStan/security, AR/EN and MySQL/Redis CI; require exact-final-head required CI SUCCESS on original PR before merging Foodex_V2. Same-account COMMENTED engineering audit is not independent approval. Final staging/device UAT remains master #2503.
