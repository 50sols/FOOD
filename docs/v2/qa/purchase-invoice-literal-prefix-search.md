# V2 #3671 — P244 Procurement purchase invoice literal scoped search

Parent #2574 (P244), #2707 Procurement Stage 3, and #2503 master. Integration target **Foodex_V2 only**; no \`main\`, production, tag, release or APK. This is an atomic read-filter correctness issue distinct from the owner-closed historical #2574 PR #3257.

## Proven defect and source behavior

\`PurchaseInvoiceHandoffManager::paginate\` used to strip \`%\` and \`_\` from \`q\`, turning an intentional symbol query into an unfiltered read and widening matches for numbers containing literal wildcard characters. Search must preserve the operator's exact characters while still using a trailing prefix wildcard. An authorized page remains correctly scoped by \`visibleQuery\` (organization, company, store); no SQL injection or actual cross-store disclosure is claimed.

**Reuse** the existing Procurement Workbench → Purchase Invoices list. Inside the original parenthesized OR filter, both fixed columns (\`invoice_number\`, \`supplier_invoice_number\`) use parameter-bound \`LIKE ? ESCAPE '!'\`; escape \`!\`, \`%\`, \`_\` to \`!!\`, \`!%\`, \`!_\`; retain literal backslash and append exactly one intentional suffix \`%\`. Empty search still returns its authorized, filtered page. The original Procurement enabled check, \`procurement.purchase_invoices.view\` and store/company/org scope, status/supplier/GRN filters, ID-desc order and 10–100 paginator remain unchanged. No route/API/screen, invoice creation, 3-way matching, handoff, stock, journal, migrations or schema changed.

## Acceptance tests and impact

One real \`RefreshDatabase\` case in \`EnterprisePurchaseInvoiceHandoffFoundationTest\` creates posted GRNs through the existing Procurement lifecycle and valid draft purchase invoices. It then creates distinct fixture numbers with leading and internal \`%\`, \`_\`, \`!\`, backslash, plus ordinary decoy, supplier-reference-only match and a separate foreign B2C store. It verifies both invoice columns, symbol-only nonbroadening, scoped authorized/foreign store, roleless 403, status, supplier, GRN and page size, and no changes to invoice/lines/stock/audit/outbox during searches.

- Backend: authoritative read-only Procurement query change; RBAC and cross-store negative path covered
- Dashboard: existing canonical Purchase Invoices list, AR/EN RTL/LTR labels and responsive UI retained
- Customer: NOT affected — no public API, auth, products, checkout or Flutter delta
- Driver: NOT affected — no API/dispatch/wallet/Flutter; B2C DRIVER ONLY
- Van: NOT affected — no API/routes/custody/Flutter; B2B VAN ONLY

## Security, rollback and release gate

REVIEW_RISK: HIGH (supplier invoices, buyer and store scope). Full engineering audit of source, test and this QA note must verify no loose OR, raw user-controlled identifier, role escalation, cross-store leak, status bypass or write side effect. Same-account \`COMMENTED\` audit is not independent GitHub \`APPROVED\`. Code-only rollback reverts the read filter; there are no data migrations or irreversible effects. Exact-final-head Repository Policy, V2 Isolation, Required CI terminal \`required-ci-gate\`, Laravel/Pint/static/security, AR/EN and real MySQL/Redis SUCCESS are mandatory; applicable Flutter build is not triggered because the three mobile apps and public API contracts are unchanged. Merge only issue-scoped PR with SHA guard into \`Foodex_V2\` after source complete; then close #3671 with evidence, preserving #2574 and #2503 for broad final acceptance. No main/deployment approval.
