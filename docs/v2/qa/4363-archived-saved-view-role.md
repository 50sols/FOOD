# V2 #4363 — archived roles cannot authorize enterprise Saved View scopes

Atomic P031 security leaf under #2782 / master #2503. Target **Foodex_V2** only.

## Vulnerability and minimal fix

`EnterpriseSavedViewService::authorizeScope` previously accepted `saved_view_roles.is_active=1` for Retail assignments and `roles.is_active=1` for configured Wholesale global roles without excluding archived roles. Historical archived-but-active roles could thus open a saved-view tenant scope. Both membership predicates now require their corresponding `archived_at IS NULL`, matching `User::hasRole` and avoiding unauthorized continuation after archiving. Existing active tenant hierarchy, Super Admin, private-view owner checks and audit behavior remain unchanged.

## Regression

`V2ArchivedSavedViewTenantAccessTest` uses seeded B2C/B2B stores with default enterprise scopes. It proves valid access for active assigned Retail / configured global Wholesale roles; archives each role via DB without clearing the active bit and proves `assertCanAccessScope`/read denial with stale User, plus fresh-actor denial for Retail, without audit mutations.

## Acceptance and scope

HIGH security technical source review; exact-head Repository Policy and V2 Isolation; full Required CI (Laravel shards, MySQL/Redis, AR/EN); SHA-guarded squash merge; postmerge verification and atomic issue closure only after green CI. No main, Flutter, release, tags or production. Customer/Driver/Van NO_DIRECT_SOURCE_IMPACT.
