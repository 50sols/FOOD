# FOODEX V2 #2525 — P195 Customer contracts and pricing

Parent #2503. Integration: Foodex_V2 ONLY. Prior #3329 provided the authorized quotation/pricing navigation and is already merged; this new completion increment does not recreate it.

## Source / placement
The 2026-11-09 owner source lists P195 العقود والأسعار separately from P193 عروض الأسعار. Placement is Extend → Compose, within the existing authorized Sales quotations operational workbench. No second global Sidebar, pricing engine, or unapproved Customer app contract.

## Real source authority
- Existing GET /admin/sales/quotations: active user, orders.view permission, effective active store and matching channel.
- POST /admin/sales/contracts: orders.manage AND orders.view, issued and unexpired quotation, one contract per quote, PDF evidence on a server-generated private local disk path; amounts/currency/customer/price snapshot are copied from the issued server-authoritative quotation, not submitted by the browser.
- POST /admin/sales/contracts/{contract}/decision: separate approver with orders.approve AND orders.view; same-store/channel scope; creator cannot approve; pending-only row-lock; check issued quotation validity and private evidence SHA-256 before approval. Rejection requires a reason.
- POST /admin/sales/contracts/{contract}/terminate: independent orders.approve scope; active-only row-lock; audit.
- GET /admin/sales/contracts/{contract}/evidence: orders.view exact store/channel; private file and integrity digest verified; no public file paths or unguarded links.
- Pricing-control and tier destinations reuse prior #3329 with their own effective permission and feature-mode checks.

## Security, lifecycle, rollback
- A customer-signed PDF is provided by an operator, privately stored as a random path, checked for PDF content and 5MB maximum, and hashed. Its upload is discarded if DB insertion fails; no automatic state mutation from a read.
- Contract states: pending → active (separate checker), pending → rejected, active → terminated. Every transition is transactional, audited, and concurrency-guarded; deny inactive/roleless/foreign-store/foreign-channel access.
- Contracts record issued quotation terms only. They do not reprice Customer/Driver/Van checkout, bypass credit/discount/stock controls, generate orders/invoices/payments, or alter B2B VAN ONLY and B2C DRIVER ONLY.
- Uploaded PDF is *not* a cryptographically verified electronic signature. Independent authenticity/legal attestation or an e-signature provider requires an explicit separate business contract. Internal approved status does not certify a legal signature.
- Additive database migration; no destructive migration, .env, uploads, production settings or tenant data change.

## UI & localization
The canonical Quotations page gains a responsive Contracts/Prices section, action form, role-aware approval controls, private evidence link, and empty/error/status states. It reuses the existing three-level/sidebar, common admin shell, responsive table, RTL/LTR layout and keyboard-visible focus. Paired AR/EN catalogs include actions, status, errors and contract terminology. Domain=Customer & Sales; entity=issued-quotation customer contract; role=sales maker + separate approver; navigation_type=WORKFLOW_STEP / PAGE_TAB; pattern=Operational Workbench; fallback=Quotations list.

## Tests / cross-surface
V2CustomerSalesContractRegisterTest: real authenticated create/approve/terminate flow, maker-checker denial, cross-store/roleless/inactive denial, malformed/expired/duplicate quote rejection, PDF corruption rejection, audit/no-order/no-finance/no-inventory changes and AR/EN workbench. Required: existing Sales Quotation tests, exact-head Repository Policy, V2 Isolation, Laravel full suite, Pint/PHPStan/security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery, terminal Required CI and final technical review before merging.
Dashboard affected (new forms/read/register). Backend affected (private admin endpoints, additive migration). Customer Flutter not affected (no API/DTO/pricing/checkout changes). Driver not affected (B2C-only dispatch untouched). Van not affected (B2B-only route/settlement untouched).

## Release / approval
The owner explicitly asked to execute #2525, authorizing engineering work; the existing gate:approval still identifies unverified business/legal contract semantics and may not be silently waived. Do not call this legally signed or ready until the evidence supports it. Keep #2503 open for final V2 UAT. Merge ONLY into Foodex_V2 after terminal green exact SHA and complete source acceptance. Never main, tags, releases or production.
