# V2 #3900 — enforce Van-origin context for Orders detail selection

Parent P275: #2605; previous filter leaf: #3897 / merged PR #3898; Van mobile dependency #2739; master acceptance #2503. Target: `Foodex_V2` **only**.

## Verified gap and placement

The canonical Operations → Orders Dashboard already supports `channel=b2b&origin=van`, filtering rows on the immutable `orders.pricing_snapshot.commercial_origin` property. Its independent `order=<id>` detail selection only checked the normal `orders.view` channel/store scopes, so a crafted same-store non-Van ID could open inside the Van-only filtered context. This did not override the existing authentication or store RBAC, but it broke the selected-source context.

Reuse / extend existing `admin.operations.orders.index` route and existing detail UI. No additional route, Sidebar item, permission, menu, API or field. The server remains the authorization authority.

## Implementation and negative matrix

In `OrderOperationsController::index`, the direct-detail query retains `whereKey(id)` and `applyOperationalScopes`, and conditionally adds the same immutable commercial-origin predicate already used by its list when `origin=van`.

- An authorized actor can open a genuine Van direct-sale detail under `channel=b2b&origin=van&order=id` (HTTP 200).
- The same actor requesting a same-store `origin=dashboard` order inside that Van filter receives HTTP 404 (not a guessed 403 or leaked alternate origin).
- Removing the optional `origin` filter restores the original same-store authorized detail behavior (HTTP 200).
- Existing cross-store operational scopes, Retail-only B2B denial, cross-channel 403 and malformed-origin HTTP 422 continue unchanged and remain covered by the previous filter test.
- No write operation, order/stock/audit/finance change or new API; the regression verifies unchanged order count.

A companion feature test extends `V2VanDirectSalesDashboardFilterTest` with positive, negative and backward-compatibility HTTP assertions using seeded test-only records. Existing AR/EN view labels and RTL/LTR presentation stay untouched.

## App impact and acceptance

| Surface | Impact |
|---|---|
| Dashboard | Affected: order detail selection follows the existing read-only Van-origin list context |
| Backend | Affected: authenticated Dashboard GET query only |
| Customer | Not affected: public API, Flutter source, order JSON and checkout unchanged |
| Driver | Not affected: B2C driver-only API and Flutter unchanged |
| Van | Not affected: public Van API, Flutter and dispatch source unchanged |

High-risk read-scope review is required for the three changed files, existing tenant permission chain, filter validation and negative cases. Required exact-final-HEAD CI: Repository Policy, V2 Isolation, Laravel/PHP/Pint/PHPStan/security/OpenAPI, AR/EN and MySQL/Redis supported installation/upgrade/recovery. Do not call CI successful before GitHub has a terminal result on the tested head. Any manual/staging/browser acceptance is deferred to #2503.

The complete P275 field direct-sales, Van custody/stock/settlement/offline and signed Android device acceptance remain **OPEN** under #2605, #2739 and #2503. No main integration, tags, releases, cPanel or production deployment.
