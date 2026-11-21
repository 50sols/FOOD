# V2 #3797 — Reject non-finite Flash reservation quantities

## Source defect and invariant

`FlashOfferService::reserve` originally rejected `$sellingQuantity <= 0` but PHP comparisons with `NAN` are false. `INF` also passes that non-positive check. A direct internal service caller could therefore enter the idempotency lookup, allocation arithmetic and stock-reservation path with a non-finite float despite upstream HTTP validation. This is a defense-in-depth service-boundary defect.

## Change and failure handling

Use `! is_finite($sellingQuantity) || $sellingQuantity <= 0` before the idempotency key check or opening a transaction. Preserve HTTP 422 and the **exact existing English message**, with no route, API field, database, permission, state, translation, checkout or order change. No migration/backfill or rollback of business data is necessary. Existing positive finite requests still enter the same idempotency/offer lookup flow; invalid quantity never creates a reservation, event, payment, order or inventory movement.

Dedicated `V2FlashReserveNonFiniteQuantityTest` executes the real Laravel service with `NAN`, `INF`, `-INF`, zero and negative input, verifies 422 and exact original message for each, and compares reservation, event, stock movement, order, payment and reserved inventory snapshots. A finite positive quantity with a non-existent product verifies the unchanged 404 lookup failure and no mutation. Existing Flash integration suite remains intact.

## Impact classification

- Backend service: affected; validation moves to the first guard before any DB query; no new authorization bypass.
- Customer: no route/status/DTO/enum or Flutter code change. Existing public HTTP validation already rejects invalid quantities; internal service hardening preserves rejection.
- Driver: not affected. B2C DRIVER ONLY is unchanged.
- Van: no API/DTO/Flutter change. B2B VAN ONLY and offer/channel access checks unchanged.
- Dashboard: no UI, navigation, roles or permission change.
- AR/EN: introduces no new user-facing text or translation key; existing English error remains byte-for-byte unchanged and all HTTP locale behavior remains handled by unchanged request validation.

## Technical audit and evidence required before merge

**REVIEW_RISK: HIGH** — inventory/reservation arithmetic and idempotency boundary, despite narrow guard. Inspect exact three-file diff, reject path, no-write and finite-positive regression. Record same-account file-by-file COMMENTED engineering review honestly (not independent GitHub approval). Verify Repository Policy, V2 Isolation Gate, Laravel PHP/Pint/PHPStan/security, AR/EN, full backend tests, MySQL/Redis installation/upgrade/recovery and terminal Required CI all **SUCCESS on the exact final PR HEAD**. Only merge into `Foodex_V2`. Final manual staging/device acceptance stays at #2503. Never merge into main or deploy/publish a release.
