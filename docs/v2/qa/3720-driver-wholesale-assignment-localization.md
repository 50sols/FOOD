# V2 #3720 — authorized B2B Driver dispatch HTTP 409 localized (AR/EN)

**Parent:** #2735 (Driver lifecycle), #2859 (canonical fulfillment), #2503 (final V2 acceptance). **Integration:** `Foodex_V2` only; no production, `main`, tag, release or APK deployment.

## Source, placement and reuse

The existing `POST /api/v1/admin/deliveries/assign` endpoint in `DriverAssignmentController::assign` calls `OperationalTenantScope::assertStore(actor, order.store_id, 'orders.dispatch', channel)` before rejecting every non-B2C channel with HTTP 409. Its refusal was hardcoded English despite authorized Arabic operators. The strictly canonical mapping is B2B → VAN ONLY; B2C → DRIVER ONLY.

Keep the existing authorization order, `abort_unless($channel === 'b2c', 409, ...)` boolean, HTTP status and top-level JSON `message` response. Reuse the existing and already paired `fulfillment_actor_guard.b2b_van_only` translations (no duplicate locales), resolving only the authenticated user's `ar` or `en` language and falling back to Arabic. The exact English explanatory copy is now the canonical catalog text instead of the old hardcoded literal. There is no new dispatch behavior, branch, route, permission or payload key.

## Security and regression

- **MEDIUM-HIGH risk** because this is a protected assignment boundary. The tenant/role check precedes channel rejection, so unauthorized actors still receive 404 (concealed scope) rather than a leaked business 409. No scope widening, exceptions, B2B Driver fallback or impersonation path.
- The new `V2DriverWholesaleAssignmentLocaleTest` uses real `RefreshDatabase`, `CoreReferenceSeeder`, an authoritative Wholesale B2B order, valid Retail Driver and seeded `SUPER_ADMIN`. It issues actual Sanctum-backed HTTP requests in Arabic and English, asserting HTTP 409 and exact catalog message for both, including when `customer_service_override` and `replace_existing` are forged. Confirms no assignment, audit or notification writes and the B2B order remains unchanged.
- A real authenticated roleless actor receives 404 from store/permission scope before the message, with no writes. Existing `DriverAssignmentLifecycleTest` positive B2C assignment and full delivery lifecycle must remain green in Required CI.
- **Customer:** not affected. **Driver:** app runtime/mobile DTO not changed; B2C execution remains existing, B2B dispatch stays denied. **Van:** B2B-only path untouched. All Flutter source, UI RTL/LTR, routes, DTOs and permissions unchanged; only management API human-readable copy is localized.

## Acceptance

Exactly three issue-specific files: one protected controller line group, one dedicated HTTP regression, this QA note. Confirm file fence with all in-flight V2 PRs, risk-audit every patch as same-account COMMENTED (not independent GitHub APPROVED), check exact-final-head Repository Policy, V2 Isolation and Required CI (Laravel, PHP/Pint/PHPStan/security/OpenAPI, AR/EN, real MySQL/Redis fresh/install/upgrade/recovery and terminal required-ci-gate), then guarded squash merge to `Foodex_V2` only. Close this atomic code leaf only after verifying merge commit and branch provenance; broader Driver workflow/manual app UAT stays OPEN in parent #2735/#2503. No release readiness claim.
