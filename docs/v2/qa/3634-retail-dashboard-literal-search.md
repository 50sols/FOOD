# V2 #3634 — Retail Dashboard literal scoped search

## Source contract
- Parent #2818 (P082); master #2503; original branch `fix/3634-v2-b2c-dashboard-literal-search`. Target: **Foodex_V2 only**.
- Route: authenticated `admin.b2c.module` `module=dashboard&q=…` via existing `B2cWorkspaceController`; no route/Sidebar or permission changes.
- `B2cDashboardService::search()` uses one bound, fixed portable `LIKE ? ESCAPE '!'` pattern; literal `!`, `%`, `_` are escaped as `!!`, `!%`, `!_`. Backslash remains literal, including MySQL/MariaDB SQL modes. No dynamic column identifiers or string-interpolated user input.
- Scope remains `storeIds` inherited from workspace authorization. Orders continue `channel=b2c` with store-matched `b2c_customers` join, and preserve `#id` fallback. Products remain associated by `store_products`, customers by `b2c_customers.store_id`. Preserve 4-per-type and 8-overall limits; no write or migration.

## Automated QA
Focused `backend/tests/Feature/V2B2cDashboardLiteralSearchTest.php`:
- Authorized real Premium Retail Dashboard (`DashboardDemoSeeder`) with names, SKU, order number, customer contact fields and mixed-store fixtures
- `Q%_!X` returns only one permitted order, product and customer; wildcard lookalikes and foreign-store rows excluded
- Explicit denied foreign store route, and retained numeric `#id` order search
- Literal email and phone searches, Arabic product name, backslash in name, no persisted writes
- Existing `B2cTenantWorkspaceIsolationTest` additionally covers store-scoped role denial, unauthorized B2B entry, and explicit support entry

## Security, compatibility and review
- Query contains customer names, email and phone: HIGH review risk. Qualified independent tenant/security source review must be APPROVED before merge, in addition to exact-final-head Required CI success.
- No new permissions or Customer/Driver/Van API/Flutter contracts, no changes to delivery B2B VAN-only / B2C DRIVER-only, finance, billing or identity.
- AR/EN frontend strings unchanged; existing workspace RTL/LTR remains. No Setup/Update ZIP, schema or deployment change.
- Parent #2818 remains OPEN (full per-page action authority matrix); master #2503, release and UX final gates remain OPEN.
- No `main`, production, release, tags, APKs or cPanel changes authorized.
