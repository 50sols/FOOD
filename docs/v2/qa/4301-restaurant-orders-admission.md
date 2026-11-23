# P031 / #4301 — Restaurant profile admission on canonical Retail Orders

## Source and authoritative boundary
The original #4297/#4298 test discovered that GET `/admin/b2c/orders` dispatches to `OrderOperationsController::b2cIndex`, bypassing the Restaurant workspace's module restriction. The already integrated #4294 canonical store-selector hardening is preserved. A Restaurant-profile store administrator must not borrow Retail Orders privileges merely because a generic `orders.view` permission exists.

## Implementation
The compatibility GET resolves its authenticated actor and the authoritative `OperationalTenantScope::allowedStoreIds(orders.view,b2c)`. For a non-SUPER_ADMIN actor, explicitly selected stores with an effective `B2C_STORE_ADMIN` store role are denied if their authoritative business profile is RESTAURANT. When no store is selected, any authorized Restaurant store-admin assignment denies the list rather than leak Restaurant rows by applying a broad union. An explicitly selected Retail store remains accessible if permitted by existing scope. SUPER_ADMIN support remains under existing privileged authorization. Does not alter the generic index, POST, APIs or Flutter.

## Test matrix
`V2RestaurantCanonicalOrdersAdmissionTest` seeds independent Restaurant A, Restaurant B, Retail C and Wholesale D stores. Checks AR/EN, Restaurant direct and no-selector 404, Retail authorized 200, mixed Restaurant+Retail unscoped 404/explicit Retail 200, cross-store/channel denials, contradictory aliases 404, B2B-only and roleless 403, and nonmutating GET counts.

## Safety and acceptance
HIGH security review is required. Verify final exact HEAD on Foodex_V2 PR: Repository Policy, V2 Isolation, full Required CI (four Laravel shards, MySQL/Redis upgrade/recovery where applicable) and required independent approval, with no failed/skipped applicable checks. Verify source and test files in protected merge SHA before closing #4301. No main, production, release or mobile changes.

Customer: NO_DIRECT_IMPACT. Driver: NO_DIRECT_IMPACT. Van: NO_DIRECT_IMPACT. The route is Dashboard-only GET and does not change API or fulfillment semantics.
