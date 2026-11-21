# V2 #3865 — Wholesale order-create warehouse rejection AR/EN

## Source and exact scope
- Canonical route: authenticated Dashboard `POST /admin/b2b/orders`, `B2bWorkspaceController::storeOrder`.
- Preserve `HTTP 422`, existing `warehouse_id` lookup and active constraint, Wholesale principal store guard, `OperationalTenantScope`, `AdminOrderManagementService`, order/stock/payment/audit behavior.
- Localize **only** the two existing errors through `B2bWorkspaceController::msg(ar,en)`; the Dashboard access middleware selects persisted actor `ar` or `en` before the controller. Contrary `Accept-Language` must not override persisted Dashboard language.
- Both English messages remain byte-for-byte identical. No new localization catalogs, dependencies, routes, fields, permissions, schema or transactions.

## Acceptance and regression
- `B2bAdminWorkspaceTest`: separate Arabic and English saved B2B_ADMIN users, real HTTP `postJson` with missing/zero and active foreign Retail warehouse IDs, opposing `Accept-Language`, exact HTTP422 text. No `orders`, `invoices`, `payments`, `inventories` or `audit_logs` count changes on rejected calls.
- Direct Retail-role attempts remain forbidden; guest is denied by actual authentication middleware. Existing authenticated successful order creation and warehouse reservation suites remain unchanged.
- HIGH engineering review, particularly preservation of active-stock warehouse and tenant/cross-store guards; exact-final-head PHP syntax, Laravel Pint/PHPStan/security, full Laravel seeded tests, AR/EN checks, MySQL/Redis install/upgrade/recovery and terminal Required CI Gate must be SUCCESS before merge. This file is a verification plan, not independent approval or claim that CI has run.

## Cross-app impact and invariants
- **Dashboard:** affected, localized existing rejection text (no new UI route/screen).
- **Customer:** not affected; no Customer API, app, checkout, store scope or DTO changes.
- **Driver:** not affected; no Driver API, dispatch, assignment or Flutter changes.
- **Van:** not affected; no Van API, territory, assignment or Flutter changes.
- Fulfillment remains **B2B VAN ONLY**; failed Van routing remains `awaiting_dispatch`; **B2C DRIVER ONLY**.
- No change to server authorization/RBAC, Wholesale principal, finance/SoD, order creation, stock reservation, application state or sensitive data.

## Failure/rollback
If tests or required CI fail, repair the existing issue branch/PR; never bypass policy, relax guards or run production mutations. Before merging this tiny localization-only change, rollback is to revert the original issue commit. No data migration or production deployment occurs. Final manual multilingual browser/UAT is deferred to master #2503 only after source and all required exact-head checks pass.

## Isolation
Issue #3865 / parent #2503, one issue-scoped branch and PR **base Foodex_V2 only**. No `main` merge, release tags, APK/ZIP publication, cPanel or production action.
