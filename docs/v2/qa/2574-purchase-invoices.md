# V2 P244 — Purchase invoices / فواتير الشراء

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P244. Issue #2574 under V2 master #2503. Integration exclusively into `Foodex_V2`; no `main`, production deployment, tags, releases, or APK publication.

## Canonical placement and route authority

- **Domain:** Procurement & Inventory / supplier purchase invoices. **Entity:** `enterprise_purchase_invoices` and its posted-GRN lines. **Role:** authorized procurement actor bound to the operative company/store context.
- **User goal:** prepare supplier invoices from posted goods receipts, inspect the exact invoice, hand off to Finance after three-way matching, and cancel drafts with audit.
- **Placement:** REUSE the existing Procurement Workbench at `admin.erp.procurement.index?tab=invoices`; navigation owner is existing ERP Procurement business entry, not a new Sidebar group or route. The invoice detail uses an exact-record `invoice` query value on the same authorized tab. Navigation type: `PAGE_TAB` via `ADMIN` domain workspace. No new UI or master data fields.
- **Server authority:** `EnterpriseProcurementController` and `PurchaseInvoiceHandoffManager` with `procurement.purchase_invoices.view`, `.manage`, `.handoff` and `EnterpriseScopeAuthorizer`; module lifecycle/readiness must authorize before a supplier invoice can be queried or changed. Supplier/receipt and invoice lookups are store/company scoped. A hidden tab never authorizes direct URL access.
- **Accounting controls:** only posted goods receipts source drafts; repeated supplier references are rejected; issued and returned quantities bound invoiceable units; three-way match review gates Finance handoff; successful handoff is idempotent via outbox, and cannot be silently cancelled.
- **Failure handling:** ten existing invoice-specific `ValidationException` messages are now sourced from `enterprise_procurement.errors.invoice_*` for AR/EN. Dynamic receipt, line, invoice, localized action/status and remaining quantity stay present without exposing implementation details. No exception bypass or permission change.

## Evidence and functional scope

The isolated regression `EnterprisePurchaseInvoiceHandoffFoundationTest::test_p244_invoice_rejections_remain_localized_and_do_not_change_financial_state` exercises a real posted GRN/invoice/handoff across Arabic and English. It checks duplicate supplier reference, net invoiceable quantity cap, and prohibition on cancelling a handed-off invoice; rejects must differ by locale, keep one invoice/outbox event, and never write a cancel audit row. Existing foundation tests continue covering cross-store reads, disabled module, maker/checker 3-way matching, returned quantities, immutable handoff, and retry safety.

**Customer app:** Not affected — no storefront/Customer API or Flutter changes. **Driver app:** Not affected — no B2C dispatch/wallet contract changes. **Van app:** Not affected — no B2B route/visit/consignment API change. B2B→Van and B2C→Driver isolation remains unchanged.

## Verification / acceptance remaining

Required exact-head verification is Repository Policy + FOODEX V2 Isolation + FOODEX Required CI terminal gate (PHP syntax/Pint/PHPStan/security, complete Laravel suite, MySQL/Redis fresh install/upgrade/recovery and AR/EN). AR/EN translations must be exercised through the regression. Test assertions do not replace independent real-runtime browser responsive, screen-reader, RTL/LTR and end-to-end role/store/disabled-module acceptance. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2750; keep #2574 OPEN if source-to-page acceptance remains incomplete. No claims of real-device UI tests or actual production deployment.

## Owner-directed restoration and current-source reconciliation (2026-11-15)

Original owner-administratively-closed PR #3257 was reopened on its **original** `fix/2574-v2-purchase-invoice-localized-failures` branch, restored from `48277dca56ae327b5501139eda488959e8a4afb8`. Reconciliation with `Foodex_V2@a0e7979b74f99ce46c102df09ce8a6d79106e1eb` preserved the original history as second merge parent. This is not a new PR or source implementation.

The current `PurchaseInvoiceHandoffManager` contains a newer **literal search** contract: escape `!`, `%`, `_` and use `LIKE ... ESCAPE '!'` with an appended prefix wildcard. The reconciled version retains that exact newer search and all previously present invoice flow, while restoring only the ten bilingual fail-closed validation strings. `EnterprisePurchaseInvoiceHandoffFoundationTest` retains both later literal invoice/three-way match search regressions and the original P244 locale/financial-integrity test. Paired AR/EN invoice failure keys remain one-to-one.

A new focused real HTTP acceptance test `EnterpriseProcurementWorkbenchTest::test_p244_purchase_invoice_tab_is_bilingual_authorized_and_foreign_store_denied` verifies the existing canonical `admin.erp.procurement.index?tab=invoices` endpoint returns 200 to the authorized Retail Store A actor in both AR/RTL and EN/LTR, denies the same actor Store B (HTTP 403), denies a roleless user (403), and does not insert invoices. It reuses the existing certified Procurement module fixture and official `EnterpriseProcurementWorkbenchTest::operator` role/entitlement helpers, not mock routes. The existing disabled-module regression and Maker/Checker, three-way match, idempotent handoff and store-scope foundation tests remain in place.

**High-risk finance security audit required:** The code delta is message localization and tests only; nevertheless, financial records are involved, so record `REVIEW_RISK: HIGH`. Check exact store/company data scope, no extra writes from denial, no change to posted GRN/returned quantities, no self-approval, immutable handed-off invoices and audit/outbox once semantics. Current frontend is the existing responsive AR/EN Procurement tab. No API or Flutter changed: Customer NOT AFFECTED, Driver NOT AFFECTED, Van NOT AFFECTED; B2B VAN ONLY / B2C DRIVER ONLY remain unchanged. Staging/device screen-reader acceptance remains deferred under #2750/#2503; this is not permission to waive automated HTTP or full required CI.

Do NOT merge #3257 until the exact-final-head Repository Policy, V2 Isolation, AR/EN, Pint, PHPStan, security, full Laravel, real MySQL/Redis fresh-install/upgrade/recovery, enterprise performance and terminal `required-ci-gate` checks are all successful. Never promote to `main`, production, tags or releases.
