# V2 P031 / #4303 — Restaurant profile in global Order search

## Authority and scope
P031 parent #2782 and V2 master #2503. This is independent from #4301 / PR #4302, which guards the canonical `/admin/b2c/orders` GET. This leaf touches only the global search Orders provider, a dedicated security Feature regression and this QA record. No user-facing UI, mutation, finance, dispatch, API or Flutter changes.

## Source behavior
The existing `OperationalTenantScope::allowedStoreIds(actor, orders.view)` is the authority for tenant/channel/store and explicit DENY. It remains the first gate. Restaurant-profile B2C stores for which the user has a `B2C_STORE_ADMIN` assignment are removed *before* the Orders database query and result LIMIT, because the Restaurant Dashboard profile excludes Retail Orders. Authorized Retail store results remain available, including an explicit mixed Restaurant+Retail account's Retail results. The existing separate Super Admin authorization is unchanged. No independent sidebar permission is granted and no cross-store, expired-actor, or cross-channel access is added.

## HTTP regression
`backend/tests/Feature/V2RestaurantGlobalOrderSearchAdmissionTest.php` exercises `admin.global-search` in AR and EN:
- Restaurant-only store admin receives no Restaurant Order search data.
- Retail-only store admin receives its Order and not foreign store identifiers.
- Mixed Restaurant+Retail store admin receives only Retail results with limit=1; excluded rows never crowd out Retail.
- Explicit Super Admin support may return a Restaurant Order.
- GET does not mutate Orders.

Existing `EnterpriseGlobalSearchTest` retains literal LIKE escape tests, role/tenant scope, limits and original route behavior.

## Security technical review checklist
HIGH risk: disclosure of order identifiers/store names. Review `OrderGlobalSearchProvider`, authoritative profile and store assignment predicates, dual-role cases, inherited role effective authorization, role overrides and explicit Super Admin support, cross-store/cross-channel rows, search result LIMIT and negative HTTP tests. Verify exact HEAD Policy, Isolation and Required CI on the original PR before merge; do not claim independent review under a single GitHub account. Manual V2 final UAT can be deferred, not source/CI completeness.

Mobile impact: Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Admin-only search read output has no customer, driver or van API/fulfillment contract change. No main/tag/release/production.
