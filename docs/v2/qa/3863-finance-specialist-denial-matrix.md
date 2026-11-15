# FOODEX V2 #3863 — Specialist Finance/Treasury Disabled-Module Denial

- **Source:** #2714 / T353R5, master #2503. Integration target **Foodex_V2 only**.
- **Prior source:** #3053 is already merged and covers FINANCE and RETAIL_FINANCE. This atomic leaf adds only the missing AR_OFFICER, AP_OFFICER and TREASURY_OFFICER denied-when-unavailable matrix. It neither reimplements #3053 nor claims completion of the parent five-persona operational Sidebar/SoD acceptance.

## Authoritative role baseline and exact policy

| Built-in role | Required module | Persisted permissions | Runtime disabled state |
|---|---|---|---|
| AR_OFFICER | finance | finance.view, finance.manage, reports.view | None effective |
| AP_OFFICER | finance | finance.view, finance.manage, reports.view | None effective |
| TREASURY_OFFICER | treasury | finance.view, finance.manage, reports.view | None effective |

These are different built-in role codes/module prerequisites, **not** distinct persisted permission bundles. All three exclude finance.post, finance.approve and security.approve; no new permissions are granted. ModuleRegistry lifecycle and BuiltInJobRoleCatalog availability remain the authoritative server-side decisions, not sidebar presentation or actor-supplied store identifiers.

## Automated negative / no-write matrix

`backend/tests/Feature/V2FinanceSpecialistModuleBoundaryTest.php` uses `RefreshDatabase`, real `CoreReferenceSeeder`, persisted assignments and active synthetic B2B/B2C stores. Both AR and EN:

1. Verify exact seeded role code, required module, stored grant set, effective permission denial and preservation of each original role assignment while its mandatory module is disabled.
2. Check canonical `AdminNavigation::groupsFor` has no Finance ERP, Treasury ERP, Receivables, Payables, Treasury Master or Reports destination solely from an unavailable assignment. Existing empty-group suppression is not replaced.
3. Exercise authenticated direct HTTP for Finance Workbench accounts, receivables and payables; Treasury ERP; Treasury Master; Reports. Expected 403 (or concealment-safe 404), no 2xx/3xx redirect loophole. Verify named routes exist before asserting.
4. Verify zero **new** finance journal, ledger-entry or treasury-receipt rows after all denied requests. Does not perform live user/role/entitlement/data changes.

No signed installer, live browser/device/staging, finance operations or maker-checker acceptance is claimed by this test-only leaf. Full P2714 and master #2503 remain open for their respective acceptance matrices, including real enabled+entitled finance specialist workflows.

## Surface impact

- **Backend and Dashboard:** regression for existing server authorization and Sidebar projection; no runtime code, routes, locale copy, financial schema or UI markup changes.
- **Customer:** no public API/Flutter/auth/checkout/invoice contract delta.
- **Driver:** no mobile/collection/dispatch/finance mutation change; B2C DRIVER ONLY remains unchanged.
- **Van:** no mobile/visit/finance/route change; B2B VAN ONLY remains unchanged.

## CI and integration evidence

Require exact final PR HEAD: Repository Policy, FOODEX V2 Isolation, FOODEX Required CI with PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN localization, Laravel Backend suite, MySQL/Redis deployment acceptance and terminal `required-ci-gate` all successful. Flutter jobs may legitimately be skipped by unchanged-source selectors and **must not** be counted as executed. Risk-classify technical review and preserve the same original branch/PR. No merge to main, production, release, tag, APK/ZIP publication or cPanel action.
