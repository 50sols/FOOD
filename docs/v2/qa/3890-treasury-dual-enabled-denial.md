# FOODEX V2 #3890 — Treasury Officer dual-enabled module / absent entitlement security regression

**Source:** T353R5 Finance persona #2714; related built-in role gate #2699, Finance/Treasury segregation-of-duties gate #2851; master #2503. **Integration exclusively:** `Foodex_V2`. This is a narrow automated security evidence leaf, not approval of a role policy, finance workflow or production promotion.

## Rationale and nonduplicate boundary

- Merged #3863/#3864 protects disabled mandatory Treasury module.
- Merged #3872/#3873 protects AR_OFFICER/AP_OFFICER with Finance module enabled but channel and Finance entitlements absent.
- Existing #2699/#3179 proposed adding TREASURY_OFFICER to the B2B management role allowlist, but its PR was explicitly **closed unmerged** because finance.manage could become GL maker/account authority. Do not reapply that code without a domain-specific SoD decision and real source acceptance.
- This leaf verifies a separate **TREASURY_OFFICER** state: both Treasury and Finance enabled and configured with exact-version certification, no granted STORE entitlement and no approved management-channel admission.

## Test matrix and authority

`backend/tests/Feature/V2TreasuryOfficerDualEnabledAuthorizationTest.php` uses Laravel `RefreshDatabase`, canonical `CoreReferenceSeeder`, persisted built-in role + user, seeded B2B principal, synthetic B2C store, module lifecycle transitions, real `AdminNavigation`, role grants, authenticated HTTP requests and no-write ledger assertions.

For **Arabic RTL** and **English LTR** locale selections:

1. Both modules are actually enabled, Treasury Officer's required module is available, and Finance is explicitly optional; deny the false inference that the role is harmless only because its modules are disabled.
2. Seeded Treasury Officer has finance.view/manage/reports.view; it has no finance.post, finance.approve or security.approve. The code does **not** add a new permission.
3. With no approved B2B/B2C channel assignment and no store-scoped Treasury or Finance enterprise entitlements, `AdminNavigation::canAccess` denies both business channels and no ERP Treasury/Finance/AR/AP operational link appears.
4. Direct authenticated ERP Treasury and Finance HTTP requests with Wholesale and Retail store IDs return deny (403 or concealment-safe 404), rather than permitting module-enabled GL or treasury workspace access.
5. Role assignment remains intact, no user overrides are added, and the finance journal, ledger entries and treasury receipts counts are unchanged.

**Security limit:** Absent entitlement/channel cannot be bypassed by merely enabling both modules. This proof does not establish how TREASURY_OFFICER should be admitted when an approved role-specific Finance-vs-Treasury SoD policy exists; #2699 and #2851 remain open. Any failing negative assertion requires a scoped security remediation, never deletion or weakening of the assertion simply to get green CI.

## Surface and app impact

- **Backend:** new issue-scoped test only. No runtime service, endpoint, validation, middleware, schema, role catalog or entitlement change.
- **Dashboard:** existing real routes and Sidebar are exercised; there is no new page, navigation entry or UI copy.
- **Customer:** no public API, checkout, credit or Flutter changes.
- **Driver:** no B2C dispatch/collection/Flutter changes.
- **Van:** no B2B trip, collection, stock custody or Flutter changes. B2B remains VAN ONLY; B2C remains DRIVER ONLY.

## Review and delivery gates

**REVIEW_RISK: HIGH** — test-only but Finance/Treasury authorization and SoD scope. Review file-by-file for fixture legitimacy, no false denial due to disabled modules, real direct HTTP, cross-store denial and absence of changes to financial data. A same-account COMMENTED technical audit is engineering evidence, **not independent GitHub approval**.

Exact final PR HEAD must pass Repository Policy, V2 Isolation and complete Required CI (PHP lint, Pint, PHPStan/security/OpenAPI, AR/EN, Laravel Backend, MySQL/Redis supported deployment acceptance, Enterprise Performance as applicable, terminal required-ci-gate). Check actual runs and final SHA before any protected merge. Staging/business/account attestation remains with #2503. Parent #2714, role gate #2699 and SoD gate #2851 stay OPEN.

**No `main` PR/merge, production deployment, tag, release or APK/ZIP publication.**
