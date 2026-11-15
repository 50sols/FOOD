# V2 #3757 — public Smart Ordering checkout idempotency conflict AR/EN

**Integration:** `Foodex_V2` only. Parent #2503 remains open. No `main`, release, tag, APK, cPanel or production.

## Exact source boundary

The existing public restaurant QR endpoint `POST /api/smart-ordering/carts/{cart}/checkout` delegates to `CheckoutService::checkout`. The branch/key SHA-256 lookup and `hash_equals` on the canonical request fingerprint are unchanged. A repeated Idempotency-Key with *different* checkout payload still rejects with HTTP 409 and the existing JSON `message` envelope, but now uses paired `smart_ordering.checkout.idempotency_conflict` AR/EN text instead of fixed English. Valid identical-payload replay still returns HTTP 200 and the same canonical order reference. The existing 422 invalid key-length validation and its string are out of scope.

## Real HTTP negative and positive evidence

The seeded `SmartOrderingCoreTest` fixture creates real B2C store/branch, active cart, product, inventory and QR checkout. The new method performs a legitimate first checkout (201), then replays the identical branch/key with changed `order_note` using each of `Accept-Language: ar` and `en` (and aligned app locale). The second request must be 409 with an exact localized `message`; order, context, event, payment, cart, item and audit table counts must remain identical to the post-first-checkout baseline. Existing `test_duplicate_checkout_replays_same_order_without_duplicate_rows` retains positive 200 replay regression.

## Role/surface/compatibility impact

| Surface | Impact |
| --- | --- |
| Public restaurant Smart Ordering | Only human-readable conflict message changes by AR/EN; same HTTP 409, JSON field, key hashing, branch scope and no-write guard |
| Dashboard | No route, permission, menu, layout, responsive or RTL/LTR change |
| Customer | No Customer app/API/DTO/Flutter code change; QR guest checkout text can be displayed by its public API consumer |
| Driver | No API or Flutter change; B2C remains DRIVER ONLY |
| Van | No API or Flutter change; B2B remains VAN ONLY |

**REVIEW_RISK: HIGH**: changing the error displayed by a sensitive duplicate-order guard. File-by-file security audit must confirm `hash_equals`, branch scoping, fingerprint, transactions and non-duplicate fulfillment remain unmodified. Required exact-final-head gates: Repository Policy, FOODEX V2 Isolation, localization quality, PHP/Pint/PHPStan/security, Laravel seeded HTTP tests, MySQL/Redis and terminal Required CI. Same-account technical review is *not* independent GitHub approval. Defer final multi-surface manual/staging acceptance only to #2503.
