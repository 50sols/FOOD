# V2 P031 / #4335 — Restaurant exclusion in central Operations order creation

## Source-proven gap
The central Dashboard new-order workflow obtains allowed stores from `OrderOperationsController::orderCreateStoreIds`, merging canonical scoped `orders.create` and `orders.manage`. Before this leaf, no Restaurant business-profile admission was enforced here, even though canonical Retail Orders, generic Operations list, details, new-order feed and search already deny Restaurant-profile `B2C_STORE_ADMIN`. This mismatch allowed direct authenticated `admin.operations.orders.lookups` access to central Retail customer/product/address data for Restaurant store admins. The same store list also feeds the wizard, quote and create entrypoints.

## Fix
Filter the B2C store list after authoritative scoped permission resolution: remove any Restaurant-profile store where an actor with `B2C_STORE_ADMIN` is not `SUPER_ADMIN`. The centralized Retail workspace remains inaccessible to Restaurant store-admin roles, but a mixed Restaurant+Retail actor can explicitly choose its authorized Retail store. Super Admin, Wholesale channel, and normal Retail remain as before. No changes to dedicated Restaurant workspace, mobile-facing APIs, product, pricing, database or write logic.

## Acceptance
`V2RestaurantCentralOrderAdmissionTest` uses real seeded roles, explicit create/manage grants, three stores (Restaurant, Retail, foreign Retail), Restaurant-only, Retail-only, mixed and Super Admin users, authenticated AR/EN direct customer lookup GET, positive Retail and Super Admin, negative Restaurant and unauthorized foreign store, and no Orders/Payments/Invoices writes. Existing new-order quote/create validation and ERP tests must remain green.

HIGH-risk auth/PII review. Exact last-head Repository Policy, V2 Isolation, Required CI (four Laravel shards and MySQL/Redis) terminal SUCCESS, file-level review and protected SHA merge required. Source-complete only atomic #4335, parents #2782/#2503 stay OPEN. MOBILE IMPACT — Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No main, release, production or Flutter changes.
