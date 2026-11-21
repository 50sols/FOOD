# FOODEX V2 #2616 — P286 Customer Accounts Receivable / حسابات العملاء AR

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P286 (Finance & Treasury).
**Parent:** #2503. **Target:** Foodex_V2 only, never main, tag, release, cPanel or production.

## Canonical placement, discoverability and user goal

- Domain/entity: Finance & Treasury → Accounts Receivable → customer AR documents and account ledger.
- Actor: active authenticated Finance reader on an authorized Retail store with finance.view, certified/ready Finance module and finance.ar.read legal-entity capability; mutation and posting have independent authorization.
- User goal: inspect AR document number, customer, status, amount/currency, due date and linked posted ledger; filter real customer documents without reading a different store.
- **Reuse** the existing Finance business group → erp_finance → route admin.erp.finance.index, query tab=receivables. Horizontal PAGE_TAB within the same authorized Finance Workbench. No separate Sidebar item, controller, independent visibility registry, mock screen or placeholder.
- Authority: existing server effective finance.view, retail store scope, legal entity, module readiness, finance.ar.read; Sidebar is only a projection. The existing store selector supplies the authorized business context, not a raw ID entry. Fallback is Finance Workbench.
- Desktop/tablet/mobile: existing responsive Finance grid/tabs, AR RTL and EN LTR, existing localized status and titles. No new UI markup. Manual 320–1280px, keyboard, assistive-technology and staging UAT deferred to #2503/#2750; not claimed performed.
- Data classification: customer/store existing authoritative master data, document status localized enum, amount/due date business data. No new user input or independent customer master.

## P286 executable HTTP + real business-record acceptance

New dedicated test backend/tests/Feature/V2AccountsReceivablePageAuthorityTest.php proves actual customer AR list and status filters, **not** P288 aging calculations:

1. Two Retail stores and customer AR drafts created with existing AccountsReceivableService and legal-entity Finance accounts are visible only to their own authorized user in Arabic RTL and English LTR.
2. Existing status filter excludes draft AR rows on posted-only list; forged foreign Retail store selection is HTTP 404, while store B may read only B.
3. Disabled Finance -> HTTP 403. Enabled without finance.ar.read -> HTTP 403. Enabled with scoped AR entitlement -> HTTP 200. Enabled but uncertified/unready -> HTTP 403.
4. Denial of finance.manage leaves existing AR reads available but blocks direct document creation before DB mutation; explicit finance.view DENY then rejects HTTP. Records are preserved.
5. Roleless and inactive users cannot request the AR tab directly.
6. A multi-role, two-Retail-store actor receives no customer AR records before selecting a store, and after selecting A or B sees only that store's documents despite both sharing the same legal entity.

Existing EnterpriseAccountsReceivableFoundationTest independently covers canonical draft/post, allocation maker/checker separation, idempotency, audit and financial journals. V2ReceivablesAgingSidebarContractTest (#2618 / merged PR #3085) owns limited aging authority, which is **not duplicated**. #3217 / PR #3218 has now MERGED into Foodex_V2; its distinct full-ledger aging and same-legal-entity Retail store isolation fix is retained and exercised by this P286 HTTP regression. This issue does not duplicate its service/controller files. #3269 / PR #3276 owns broader Finance Navigation/LEGAL_ENTITY vs STORE entitlement equivalence. None of their shared source paths are edited here.

## Security / cross-surface impact

| Surface | Evidence-backed classification |
| --- | --- |
| Backend | Issue-scoped Feature test only; no API/controller, route, permission, migration, module health, identity or financial production logic changed. |
| Dashboard | Existing real Finance AR tab reused and authenticated bilingual record/status response exercised; no changes to markup, routes or navigation. |
| Customer | Not affected: no Flutter/customer API, pricing, invoice or identity contract change. |
| Driver | Not affected: B2C Driver-only fulfillment, wallet, collections and APIs remain unchanged. |
| Van | Not affected: B2B Van-only assignment/stock/visit/dispatch and APIs remain unchanged. |

Only synthetic test accounts/documents are created in isolated PHPUnit databases. No production account, customer, upload or cash ledger is touched.

## GCC isolated execution and original PR recovery (2026-11-15)

- The original #3278 PR had been administratively closed, its head branch deleted and its earlier Required CI failed at the cross-Retail AR denial. The same original branch and PR were restored rather than forked. Source #3217 / PR #3218 is independently merged into Foodex_V2.
- An initial GCC test borrowed another clone's Composer vendor/autoload and loaded old application classes, reproducing the historical false-negative. Retesting with a local, untracked PSR-4 bootstrap explicitly mapping App/Tests/Database to the new isolated checkout passed 5 HTTP tests / 47 assertions under PHP 8.3.33, SQLite in memory; no changes to shared vendor or production. This is local evidence only, not GitHub exact-head CI or a substitute for real MySQL/Redis tests.
- Restored source covers the existing Finance AR tab only; no new finance mutation/API/Flutter code. Final-HEAD Required CI and HIGH-risk audit remain mandatory before merge and CODE_COMPLETE closure.

## Required gates and scope boundaries

One Issue, branch test/2616-v2-ar-customer-accounts-authority, one PR targeting Foodex_V2 ONLY. Before merging verify latest exact-head Repository Policy, FOODEX V2 Isolation, terminal Required CI, Laravel feature/full suite, PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN and real MySQL/Redis fresh-install/upgrade/recovery. No historical check inherits validity after changing the head.

P286 canonical customer AR browsing/document permission is the bounded acceptance of this PR. Do not conflate it with unresolved P288 full-ledger aging or P280 finance-wide entitlement parity. If an actual P286 source requirement remains unsatisfied, keep this Issue OPEN and document it rather than closing prematurely. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL per #2503 only for final browser/device/staging owner UAT, not automated source acceptance.
