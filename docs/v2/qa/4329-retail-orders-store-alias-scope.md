# V2 P031 / #4329 — canonical Retail Orders legacy store alias scope

## Verified source gap
`OrderOperationsController::b2cIndex` accepted both `store` and `store_id` as validated positive store aliases, rejecting malformed values and contradictory dual selectors. It only forwarded `store_id` to the generic `index`, however. With `?store=N` alone, two authorized Retail stores were displayed instead of solely N; a mixed Retail+Restaurant store-admin was rejected even when explicitly selecting authorized Retail N because profile admission treated the request as unscoped. This is a concrete selection/tenant-scope contract mismatch distinct from merged #4301.

## Change
After strict, positive integer alias validation and contradiction rejection, choose the single validated store ID and normalize it into `store_id` **before** Restaurant admission and generic Orders query. When no explicit store selector is present, preserve existing unscoped Restaurant fail-closed behavior. Do not modify `OperationalTenantScope`, `orders.view` checks, channel pinning, Super Admin override or order mutation logic. `store=restaurant_id` remains 404 for Restaurant store-admin; foreign/unassigned store remains denied by the existing generic scope. Both selectors set to the same value remain accepted; conflicting values return 404.

## Real HTTP acceptance
New `V2B2cOrdersStoreAliasScopeTest` seeds two authorized Retail stores, another foreign Retail, Restaurant, real orders and actors with two Retail roles or mixed Restaurant+Retail, plus Super Admin. Arabic and English GET asserts legacy alias limits `rows` to the selected Retail order and excludes other authorized store rows (actual order ID), selected mixed Retail succeeds but Restaurant selection and unscoped mixed fail 404; cross-store 404; malformed/array/contradictory aliases 404; Super Admin retains explicitly selected Restaurant access; no Orders/Payments/Invoices writes. Existing `V2RestaurantCanonicalOrdersAdmissionTest` assertion was updated to reflect legitimate explicit Retail alias admission and added direct Restaurant alias denial.

REVIEW_RISK: HIGH (tenant order row scope). Must have exact-final-head Repository Policy, V2 Isolation and Required CI including four Laravel shards/MySQL/Redis terminal SUCCESS and file-level technical review before SHA-protected merge and verified close. No independent GitHub approval may be inferred from a same-account comment. Source complete only for #4329; parents #2782/#2503 remain open.

MOBILE IMPACT — Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Dashboard authenticated read GET only; no Flutter/API/dispatch/release/main/production changes.
