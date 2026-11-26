# V2 #4094 — BI Scheduled Report PATCH ownership-before-validation

- **Issue:** #4094, parent #2818 / #2503; **base:** Foodex_V2 only.
- **Canonical existing route:** `admin.erp.bi.schedules.update` (PATCH); no route, Sidebar, entitlement or capability additions.
- **Root cause:** controller's request validation previously ran before looking up the schedule or enforcing requester/super-admin, effective store/legal-entity and report-export entitlement checks. Invalid payloads could return 422/302 where the same actor received 403 with a valid request.
- **Production correction:** reuse the existing requester/role, tenant and report access checks unchanged, move request validation after all those checks, with 404 on missing record before body validation.
- **Tests:** `V2BiSchedulePatchOwnershipValidationTest` uses CoreReferenceSeeder and test-certified Enterprise BI plus two separate B2C Store A/B effective assignments and entitlements. Covers non-owner malformed PATCH (AR/EN), owner malformed 422 and valid positive update, missing record 404, and historical foreign-store denial. Asserts unchanged schedule contents and record count for denials.
- **Review risk:** HIGH (RBAC, record ownership, tenant isolation). Source review must verify deny precedence, report and entitlement checks, no new privileges, no mutation before scope check, future time validation and unchanged authorized update.
- **Mobile impact:** Customer **NO_DIRECT_IMPACT**, Driver **NO_DIRECT_IMPACT**, Van **NO_DIRECT_IMPACT**. Only existing admin web PATCH controller and its Laravel test change. No Flutter, mobile HTTP API, dispatch, tracking, notification, or fulfillment behavior changed. B2B VAN ONLY / B2C DRIVER ONLY remain intact.
- **AR/EN, RTL/LTR:** original interface and localizations untouched; authenticated locale-parametrized denied requests are exercised. No new text or UI behavior.
- **Releases:** no ZIP, migration, schema, CI configuration, production, main or release changes.

## Verification record

Pending final exact-head Laravel, Repository Policy, FOODEX V2 Isolation and Required CI checks. **Do not merge/close until terminal green and file-level technical review PASS.** The previously merged #3958 is distinct: it guarded BI actor eligibility and scope, but did not move PATCH payload validation after schedule ownership.
