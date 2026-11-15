# V2 #3633 — Restaurant integration observability literal search

## Authority / placement

- Parent P082 #2818; isolated V2 master #2503. Existing authorized Restaurant SaaS Integration Hub and its events/webhooks list are reused; no new Sidebar, route, tab, form or search widget.
- Tenant/store: the existing `restaurant_saas_integrations.site_id` and `restaurant_saas_webhook_subscriptions.site_id` joins are authoritative. The read-only `q` predicate is nested beneath those site constraints, not an independent access filter. Integration controller's existing site permission/entitlement/active checks remain intact.
- Actor/permission: authenticated Restaurant site/manager subject to existing controller and site scope. Same view/navigation, mobile web, 320–1280px, RTL/LTR, AR/EN strings, action permissions and history. Search uses the existing filter input and returns the existing read-only data structure.

## Source bug

Both `RestaurantIntegrationService::applyEventFilters()` and `webhookDeliveries()` escaped `%` and `_` by implicit backslashes while sending `LIKE` strings through the query builder. SQLite and MySQL/MariaDB may interpret those differently; input containing a literal backslash or the `!` escape marker could match unintended records. Search input was already bound; **this was a result accuracy/portability defect, not a demonstrated SQL injection or cross-site breach**.

## Patch and tests

- Build a literal substring pattern with `str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($q))`, wrapped in intentional `%` wildcards.
- Each of the six fixed columns uses a bound `LIKE ? ESCAPE '!'`; no user-selected identifier or unbound SQL.
- Existing provider, branch, direction, type, date, status, ordering, result limit and store/site join remain unchanged; no write/mutation path modified.
- Append a `RefreshDatabase` regression to existing `RestaurantSaaSIntegrationHubTest`: two isolated Restaurant sites, service-backed connector + webhook fixtures, literal `%_` and `!_`, backslash plus underscore, Arabic/English ordinary query, same-site wildcard lookalike exclusion, cross-site exclusion, row counts unchanged after reads, and absence of encrypted payload/secrets from results.
- Existing Restaurant integration authenticated API/RBAC regression remains unchanged and exercises the same site-authorization boundary. No new display labels; AR/EN quality gate continues to apply.

## Five-surface impact

- Backend: read-only Restaurant SaaS integration event/webhook search accuracy, bounded fixed-column SQL.
- Dashboard: existing authorized Integration Hub log search (REUSE existing UI; no AR/EN or responsive layout change).
- Customer: public endpoints/Flutter, saved identity and pricing contracts unchanged; no impact.
- Driver: dispatch/assignment/Flutter, B2C-only contracts unchanged; no impact.
- Van: B2B Van-only dispatch/Flutter, consignment/offline unchanged; no impact.

## Release and evidence gate

One branch, one issue-scoped PR, base `Foodex_V2` ONLY. Require exact-final-HEAD Repository Policy, FOODEX V2 Isolation and Required CI (full Laravel/Pint/PHPStan/security/OpenAPI/localization/MySQL/Redis) and applicable source review before merge and leaf closure. Final staging/manual UAT remains tracked under #2503. No main, tags, release artifacts, cPanel or production.
