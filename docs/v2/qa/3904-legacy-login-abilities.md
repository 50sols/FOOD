# FOODEX V2 #3904 — legacy app-omitted login tokens cannot grant cross-app runtime authority

**Atomic parent:** #2740 (MOB-ALL01) under master #2503. **Target:** `Foodex_V2` only. Source: `backend/app/Http/Controllers/Api/V1/AuthController.php::login`.

## Observed defect

Before this change, `POST /api/v1/auth/login` accepted optional `app`. If omitted, a successfully authenticated account received a new Laravel Sanctum token with `abilities=['*']` instead of an application-bound credential. Sanctum `tokenCan('app:driver')` and `tokenCan('app:van')` treat `*` as authorizing both abilities, regardless of the client's intended application. Runtime middleware independently checks role/assignment, but a legitimately dual-role Driver/Van user could reuse that unspecified legacy token across runtimes without explicit app selection. This is an issuance least-privilege defect, not a claim that ordinary non-driver users could bypass role checks.

## Minimal source correction and compatibility decision

- For **new logins** with `app` omitted, issue precisely `['app:customer']` rather than `['*']`. Keep the legacy accepted email/password request, response JSON keys (`token`, `token_type`, `user`), token name, successful profile/logout, login throttling and invalid-credential behavior unchanged.
- Explicit `app=customer`, `app=driver`, and `app=van` keep their existing app-only token abilities and eligibility checks. The current first-party Customer, Driver and Van credential clients all send their app explicitly; they do not require a new Flutter code change or API schema field.
- **Existing persisted tokens remain unchanged**. This leaf does not retroactively revoke or rewrite historical `*` tokens, which could break active legacy clients; historical token inventory/revocation belongs to a separate security review and rollout. This limitation must not be hidden.
- No new role/permission grant, migration, module exposure, tenant/store scope, source-of-truth change, ledger edit, notification/dispatch or user-account modification.

## Executable acceptance

Existing `backend/tests/Feature/AuthFoundationTest.php` exercises the real authenticated HTTP endpoint with `RefreshDatabase`:

1. An ordinary legacy client omits `app`, receives the existing identity response, a single persisted `app:customer` ability (no wildcard) and can still call profile/logout.
2. A genuinely **dual-app eligible** account with real B2C Driver role+assignment and separate legitimate Van representative role+assignment issues a legacy Customer-only token, which is not capable of `app:driver` or `app:van`, and receives **403** from real Driver wallet and Van no-order-reasons endpoints with that token.
3. The same synthetic account may still request explicit `app=driver` and `app=van` tokens, whose cross-app access continues to fail as before.
4. Existing AuthFoundation suite covers inactive/invalid user, throttling, B2B vs B2C driver eligibility and Van assignment. Existing AR/EN and API response shape remain stable.

**Local evidence:** record exact PHP lint, Laravel Pint and targeted PHPUnit counts only after actual tool execution. Required GitHub exact-final-head checks are Repository Policy, V2 Isolation, Required CI (backend syntax/Pint/PHPStan/security/AR-EN/full Laravel/MySQL-Redis acceptance) and any other mandated jobs. No partial green CI may certify merge.

## Impact and security review

| Surface | Impact |
| --- | --- |
| Backend/API | Security-narrowing newly issued token ability in existing `auth/login`; no response shape or route change |
| Dashboard | No session-login, route, permission or UI change |
| Customer | Legacy app-omitted client defaults to Customer-only; first-party Flutter explicitly requests `app=customer`, no app code change |
| Driver | Explicit `app=driver` and B2C-only runtime unchanged; reusing a new ambiguous legacy token must fail |
| Van | Explicit `app=van` and B2B Van-only runtime unchanged; reusing a new ambiguous legacy token must fail |

**REVIEW_RISK: HIGH** — application-bound credential issuance and cross-runtime privilege separation. Review the full controller change and test evidence, possible legacy-client compatibility and historical wildcard-token cleanup as **separate** obligations. This source-complete leaf can close only after a single PR merges into `Foodex_V2` and exact-final-head CI succeeds. Parent #2740 and master #2503 stay open for the broader app identity matrix, historical token risk review and real staging/device UAT. **No `main` or production merge/deployment, no tag, release, signed-APK or cPanel action.**
