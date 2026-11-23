# P031 — Wholesale Dashboard canonical HTTP isolation (#4250)

- **Owner issue:** #4250. Parent security scope #2782; V2 master #2503.
- **Route under test:** GET `/admin/b2b/dashboard` (`admin.b2b.dashboard`). This is distinct from Orders, Reports and Finance PDF HTTP regressions.
- **Trusted principal:** seeded B2B_ADMIN retains authorized Wholesale dashboard access in both AR/RTL and EN/LTR. Forged `channel=b2c&store_id=<Retail ID>` must not switch the server's canonical Wholesale scope.
- **Negative actors:** a B2C_STORE_ADMIN scoped through `user_store_roles` to an actual Retail store and a roleless authenticated user must receive HTTP 403 even when the Retail actor requests `channel=b2b`.
- **Side effects:** GET cannot modify orders, payments, invoices or driver_assignments. Assert real table counts before and after the complete matrix.
- **Implementation:** additive Laravel HTTP security test only; no production controller, API, migrations, seed, RBAC configuration, navigation or apps changed.
- **Locale:** AR/EN HTML direction checked explicitly; all requests are HTTP through Laravel authorization middleware.
- **MOBILE IMPACT — Customer / Driver / Van:** Explicit three-app impact review: Customer—no direct change to authentication, navigation, API or notifications; Driver—no direct change to dispatch, orders, tracking, API or notifications; Van—no direct change to B2B fulfillment, visits, routing, API or notifications. Only the existing Admin Wholesale dashboard HTTP GET is exercised. There are no affected app implementation files or new mobile issues. Existing B2B VAN ONLY / B2C DRIVER ONLY rules are unaffected.
- **CI acceptance:** Repository Policy, FOODEX V2 Isolation, full Required CI including Laravel feature tests and MySQL/Redis install/upgrade/recovery on the final exact PR HEAD, followed by reviewed protected merge to `Foodex_V2`. Before successful checks, do not mark the task completed.
- **Nonclaims:** this bounded leaf alone does not close P031 #2782 or master #2503; full cross-route and live deployment security acceptance remains separate.
