# V2 #3913 — T353R5 Accountant five-menu operating Sidebar acceptance

Parent: #2714 | Source: FOODEX Unified RBAC/ERP/Sidebar decision T353R5 | Integration: `Foodex_V2` only.

## Canonical route and ownership map

The current `AdminNavigation::groupsFor` is the source of truth for all five authorized finance persona groups. It projects existing controllers rather than defining a parallel security policy:

| Group | Existing Sidebar key | Existing HTTP route | Server authority |
|---|---|---|---|
| Overview | `enterprise_action_center` | `admin.action-center.index` | Global management actor and My Work guard |
| Finance | `erp_finance` | `admin.erp.finance.index` | Finance lifecycle/readiness, FinanceWorkbenchAccess and authorized legal-entity/store |
| Receivables | `erp_receivables` | `admin.erp.finance.index?tab=receivables` | Finance AR read capability at legal entity with store deny precedence |
| Treasury | `treasury_master` | `admin.treasury.master.index` | finance.view and tenant/store context; Treasury ERP separately requires module entitlement |
| Reports | `reports_center` | `admin.reports.index` | reports.view with existing server scope |

## Automated test

`backend/tests/Feature/V2AccountantFiveMenuSidebarAuthorityTest.php` seeds the canonical roles, a real B2B wholesale principal and a separate B2C store. Both Finance and Treasury are **ENABLED** and certified at their actual manifest versions. Only scoped legal-entity test entitlements for Finance read and Treasury receipts read are granted; the test never adds unrestricted channel roles, platform grants or approval permissions.

For **Arabic RTL and English LTR**, it verifies:
- The B2B-only ACCOUNTANT gets real effective finance/reports grants; **finance.approve**, report export, B2C finance access and administration links remain forbidden.
- All five navigation groups project a valid, labelled, existing route and the authenticated HTTP response is not a 403/dead link.
- Finance opens an actual accessible workbench with the expected `dir` attribute; the actual Receivables tab is also exercised.
- A forged foreign B2C store parameter is denied without exposing its records; ledger journals, ledger entries and Treasury receipts are unchanged.

This is a real Laravel Feature test, not a manual screenshot or a release claim. Any exact-head CI failure must be resolved in this original child branch before merge.

## Dependencies, SoD and mobile matrix

- #2695 / PR #3191 already proves real ACCOUNTANT Finance maker -> ACCOUNTING_MANAGER checker -> distinct poster segregation, approval-limit denial, audit and B2B-only admission. This child complements it with the full visible five-menu journey. No need to duplicate the mutation flow.
- #3863, #3872 and #3890 prove separate fail-closed specialist conditions. **#2696 AR_OFFICER, #2697 AP_OFFICER and #2699 TREASURY_OFFICER remain gated**. No unapproved channel expansion or finance.manage-to-GL privilege escalation is authorized by this test.
- **Customer:** no API, mobile source or checkout/credit change. **Driver:** no B2C-only dispatch or wallet change. **Van:** no B2B-only routing, stock or collection change. Flutter builds not applicable for this test-only diff.
- No production code, schema, module policy, navigation, grant, releases, tags or deployment changed.

## Acceptance and audit

**REVIEW_RISK: HIGH** (test-only changes against Finance/tenant/SoD). Audit fixtures, lifecycle, entitlements, page authorization, store boundary and ledger no-write invariants before merge. Run exact-final-HEAD Repository Policy, V2 Isolation, Required CI (Laravel, Pint/PHPStan/security, AR/EN, real MySQL/Redis install/upgrade/recovery). Manual responsive/keyboard/screen-reader/staging UAT remains tracked by #2714/#2750/#2503 and is not represented as completed.

**No `main`, tags, releases, production or cPanel deployments.**
