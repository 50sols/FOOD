# #4238 — P031 canonical Retail Orders route isolation

## Scope and authoritative route

Parent #2782 / P031; V2 isolated integration `Foodex_V2`. In Laravel, `admin.b2c.orders.canonical` and the otherwise overlapping `admin.b2c.module(module=orders)` resolve to the same canonical `/admin/b2c/orders` URL. The request is handled by `OrderOperationsController::b2cIndex`, not `B2cWorkspaceController`. The canonical method forces `channel=b2c` before `index` computes effective order-view channel/store scopes. A supplied store ID outside the actor's effective scope is rejected with 404; an actor lacking a Retail orders-view channel scope is rejected with 403.

## Bounded executable acceptance

`backend/tests/Feature/V2RetailCanonicalOrdersRouteIsolationTest.php` seeds canonical reference roles and creates anonymous synthetic Retail Store A, Retail Store B and Wholesale Store W with a Store A manager, Wholesale-only global admin and roleless principal. In AR and EN, it makes real authenticated GET requests to the canonical orders list and checks:

- Retail Store A GET is 200 and uses the selected RTL/LTR layout.
- Client-supplied `channel=b2b` cannot override the canonical Retail route.
- Explicit Store B or Wholesale Store W is 404 to Store A's Retail manager.
- Wholesale-only and roleless principals are 403 on the Retail route.
- Route registration/precedence remains canonical and request-only activity leaves order, assignment, payment and invoice row counts unchanged.

This is HTTP contract coverage, not a claim about any real user's role data or the entirety of #2782. No business-source, middleware, permission, route, schema, dashboard markup, API or mobile application code changes were introduced.

## Review and impact

**REVIEW_RISK: HIGH** because the test pins tenant/channel isolation. File-by-file audit: the only PHP file is an additive isolated test with synthetic identities and no production data. No new privileges, mutating HTTP endpoints or credentials are included. Cross-channel and cross-store denial are tested at the actual controller entry point, not inferred from Sidebar visibility. If Laravel CI finds an existing source defect, correct it on the original issue branch only after verifying ownership, and do not weaken the negative assertions.

**MOBILE IMPACT:** Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT. This test only invokes an authenticated Laravel Admin HTML GET route, with no Flutter/API/dispatch changes. Existing B2B VAN ONLY / B2C DRIVER ONLY policy is preserved.

## Integration contract

Original issue #4238, branch `test/4238-v2-retail-orders-route-isolation`, one PR targeting **Foodex_V2 only**. Required exact-head Repository Policy, V2 Isolation, FOODEX Required CI including Laravel and MySQL/Redis/terminal required-ci-gate must pass. Record actual run URLs and merge SHA only after verification. Final real-browser/staging UAT remains at master #2503. Parent P031 #2782 stays open for the complete route matrix. No main, release, tag, production or app artifacts.
