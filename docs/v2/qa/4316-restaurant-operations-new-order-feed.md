# P031 / #4316 — Restaurant admission on Operations new-order feed

## Security boundary
`OrderOperationsController::newOrderFeed` is a Dashboard-only read endpoint that returns new-order popup metadata including order number, Retail store name, customer name and phone, monetary total and URL. A historical dashboard notification may remain assigned to a `B2C_STORE_ADMIN` whose assigned store is now classified `RESTAURANT`. The generic `orders.view` scoped store authorization alone is not admission to Retail Orders; direct GET must enforce the same Restaurant exclusion as canonical Retail Orders and global search.

## Source change
After existing `OperationalTenantScope::allowedStoreIds(actor, 'orders.view', channel)` validation and before order/customer lookup and any PII materialization, skip B2C Restaurant stores for non-SUPER_ADMIN users holding `B2C_STORE_ADMIN` for that store. Resolve current authoritative profile with `StoreBusinessProfileService::isRestaurant`. Unaffected: B2B channel, legitimate Retail store assignments, SUPER_ADMIN authorization, notification source and status constraints, no-`after` initial cursor, and progress past filtered notifications. No writes are introduced.

## HTTP test matrix
`V2RestaurantOperationsNewOrderFeedTest`: seeded Retail and Restaurant stores, valid and foreign orders/notifications, Restaurant-only actor, mixed Restaurant/Retail store-admin actor, Retail-only actor and SUPER_ADMIN. HTTP AR/EN GET with `after=0`: Restaurant-only receives no order/PII and cursor advances; mixed sees only authorized Retail; Retail-only sees own non-foreign order; Super Admin sees both valid stores; all read-only GETs preserve order, notification and payment counts.

## Acceptance and scope
Risk HIGH (sensitive Customer PII, tenant and business profile admission). File-by-file technical review and terminal success for Repository Policy, V2 Isolation and Required CI on exact final PR HEAD (all 4 Laravel shards, Backend security/migration, MySQL/Redis, AR/EN). Merge via guarded reviewed PR to `Foodex_V2` only and verify integrated controller, test and QA document before closing this atomic leaf. Parent #2782 and master #2503 remain open until final V2 acceptance.

**MOBILE IMPACT — Customer / Driver / Van:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT; only read-only Dashboard notification popup GET is affected. Existing API, dispatch, B2B VAN only, B2C DRIVER only and Flutter app contracts unchanged. No `main`, tags, release or production actions.
