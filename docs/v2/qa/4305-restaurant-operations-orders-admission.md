# V2 P031 / #4305 — Restaurant profile exclusion on generic Operations Orders

## Independent authority and scope
Parent #2782 / master #2503. Already completed #4301 protects canonical `/admin/b2c/orders`, while #4303 protects Admin global search. This leaf fixes the remaining distinct direct GET route `admin.operations.orders.index` (`/admin/operations/orders`), which calls `OrderOperationsController::index` directly, bypassing the canonical wrapper's Restaurant gate.

## Server behavior
After existing role/channel/store authorization selects real `OperationalTenantScope` store IDs, the generic Orders listing denies a Restaurant-profile store if the actor has `B2C_STORE_ADMIN` there, except explicit Super Admin access. Unfiltered Restaurant-only or mixed Restaurant+Retail store-admin queries fail closed (404) rather than disclose Restaurant identifiers, counts, filters or rows. Mixed users may explicitly select an authorized Retail `store_id`; a B2B-only authorized actor's B2B scopes are not changed. The original generic index, real store scope and other action authorization remain intact. No mutation, migration, new UI/sidebar, service API or fulfillment changes.

## HTTP test acceptance
`V2RestaurantOperationsOrdersAdmissionTest.php` uses seeded real roles, Retail/Restaurant/foreign stores, real orders and authenticated HTTP requests under both Arabic and English. Negative: Restaurant selected/unfiltered and mixed unfiltered/Restaurant selected, foreign Retail. The existing `channel=all` selection is rejected with 403 by `assertOperationalChannelAllowed` for all actors and is separately verified without changing that contract. Positive: mixed Retail selected, Retail-only, trusted Super Admin Restaurant. Assert real view row IDs for positive selection and no Orders/Payments/Invoices writes. Existing #4301/#4303 tests continue independently.

## Security and delivery
REVIEW_RISK: HIGH. Review candidate store selection after authoritative `OperationalTenantScope`, RESTAURANT profile, store-admin effective role, protected Super Admin, mixed channel/store, all-channel and negative direct HTTP. Existing per-store explicit user permission deny remains enforced upstream. Verify four Laravel shards, MySQL/Redis, Repository Policy, V2 Isolation, Required CI on exact last commit and applicable GitHub merge rules. Same-account technical review is distinct from independent GitHub APPROVED; do not invent latter.

Customer: NO_DIRECT_IMPACT. Driver: NO_DIRECT_IMPACT. Van: NO_DIRECT_IMPACT. Dashboard read-only GET, no mobile API, dispatch, payment or fulfillment contract changed. Integrate only into Foodex_V2; no main/release/production.
