# #4386 — Admin reporting API role revocation

P031 authorization leaf under #2782 and #2503, integration target Foodex_V2 only.

## Scope and source review
`AdminReportController::dashboard` previously admitted any historical active store role with `reports.view`, regardless of `roles.archived_at` or store activity. Its final store-selection check also allowed a historical store assignment. The controller now requires the persisted user to remain active, and the fallback store role to be active, unarchived, store-scoped and attached to an active store, at both admission and store selection. `Gate::allows`, its real permission checks, mandatory selector and platform SUPER_ADMIN behavior remain unchanged. No new grants, role mutations, audit writes, migrations, result payload changes or extra routes.

## Test matrix
`AdminReportingTest` keeps existing positive SUPER_ADMIN platform/store reporting, B2C admin own store 200, foreign store 403, missing store 422. Added seed-backed Sanctum HTTP test with active B2C store role -> 200, archived-but-active -> 403, inactive role -> 403, disabled store -> 403, restored role/store -> 200, disabled persisted user with stale authenticated actor -> 403, historical `user_store_roles` row preserved, no report audit writes. Sensitive sales/inventory aggregates not returned on denied cases.

## Acceptance boundaries
HIGH security risk; full exact-head Repository Policy, V2 Isolation and FOODEX Required CI including Laravel shards, AR/EN, MySQL/Redis acceptance must finish SUCCESS; file-level technical review and SHA-guarded merge into Foodex_V2 only before closing #4386. This does not certify broad P031 completeness or production UAT. Customer: NO_DIRECT_SOURCE_IMPACT. Driver: NO_DIRECT_SOURCE_IMPACT. Van: NO_DIRECT_SOURCE_IMPACT. No Flutter/app/API payload contracts altered; B2B Van-only and B2C Driver-only fulfillment unchanged. No main/release/tag/deployment.
