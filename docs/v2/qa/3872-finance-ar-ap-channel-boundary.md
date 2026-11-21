# V2 #3872 — Enabled AR/AP Roles: Channel and Finance ERP Entitlement Boundary

**Integration base:** `Foodex_V2` only. **Parents:** #2714 (T353R5) / #2503; role authorization/approval gates #2696 and #2697 remain OPEN. **Predecessor:** completed #3863 covers the *mandatory module disabled* case; this is a distinct *module enabled and certified but unapproved B2B channel / absent Finance ERP entitlement* case.

## Current authoritative contract

- `backend/config/job_roles.php` registers `AR_OFFICER` and `AP_OFFICER` as global Finance operators with mandatory `finance` module. Once finance becomes enabled, their effective role grants are `finance.view`, `finance.manage`, and `reports.view`. They are not Finance posters, approvers, security administrators or Super Admins.
- `backend/config/admin.php` **does not currently admit these role codes** in `admin.channels.b2b.global_roles` or in the Retail channel. That source/owner approval is tracked separately; do not add the roles to channel lists automatically.
- `AdminNavigation::canAccessPlatformErp` is a presentation scope check for global roles, **not an independent HTTP or module-entitlement grant**. The actual enterprise Finance Workbench additionally needs operational module readiness and eligible legal-entity/store entitlements.

## Automated regression

`backend/tests/Feature/V2FinanceArApUnapprovedChannelTest.php`:

1. Use `RefreshDatabase`, canonical `CoreReferenceSeeder`, seeded Wholesale principal B2B store and a separate real B2C Retail store, with organization scope established.
2. Transition Finance module through ENABLING → ENABLED and set exact certified manifest version, assert module is enabled, but no Finance store entitlements. This is NOT a false green caused by disabled Finance.
3. Attach only the real `AR_OFFICER` or `AP_OFFICER` global role to a fresh active user. In both AR and EN locales, assert existing Finance grants are effective but `finance.post`, `finance.approve` and `security.approve` remain denied.
4. Assert both management channels remain unavailable for these *unapproved channel roles* without rewriting `config/admin.php` or the role catalog. Finance ERP Sidebar items `erp_finance`, `erp_receivables`, `erp_payables` must be absent without real store/legal-entity entitlement.
5. Direct authenticated HTTP Finance accounts, receivables, and payables tabs with the Wholesale store and forged Retail-store Finance accounts request all fail closed (403/404). Preserve original role assignment and no new Finance journals or GL entries.
6. Existing Reports and Treasury Master permissions/routes are not asserted denied; those remain distinct policies. This leaf does not claim fully operational approved AR/AP roles, a complete five-persona Finance matrix or final staging/a11y/SoD acceptance.

## Per-app impact

- **Backend / Dashboard:** Adds a regression test of current channel vs Finance ERP entitlement separation. No production code or route changes.
- **Customer:** No app files, public checkout/customer finance APIs, identity or contract changed.
- **Driver:** No dispatch/collection wallet, B2C Driver routing or mobile build impact.
- **Van:** No Van trip/credit route, B2B VAN ONLY fulfillment or Flutter changes.
- Mobile CI selector skips unchanged Flutter surfaces; skipped checks are not represented as executed.

## Quality gate and review

Require exact final head: Repository Policy, V2 Isolation, paired AR/EN localization, PHP/Pint/PHPStan/security/OpenAPI fast-fail, full Laravel tests, real MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate` SUCCESS. Document HIGH source review and exact integration SHA; one PR only, guarded merge to Foodex_V2 only, close the scoped child only after integration. No main merge, release, ZIP/APK, cPanel, publication or production operation.
