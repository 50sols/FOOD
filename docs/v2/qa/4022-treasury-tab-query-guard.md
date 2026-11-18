# FOODEX V2 G009 — #4022 Treasury GET tab query fail-closed guard

## Scope, current source, and exact ownership
- Parent #2750 (ERP web Dashboard UX), #2503; original defect #4022; base `Foodex_V2` only; initial branch source `1eacd4a925802e22abd36a67d4d8712b60496449`.
- Domain/entity: Treasury Receipts, Payments, Cheques, Reconciliation, Daily Cash Closing; banking Cashboxes/Bank Accounts remain the separate existing canonical `/admin/treasury/master-data` with #3969/#3970 already merged.
- Role: an actor whose existing Finance Treasury module, Store, legal-entity context and `finance.view` rights have **already** been validated.
- Canonical route: `admin.erp.treasury.index` on `/admin/erp/treasury`; `tab` is a presentation-only GET parameter and never a POST operation authority.
- Placement: **Reuse** the already merged 10-tab `foodex-tabs foodex-tabs--horizontal` rail (G009 #4007/#4009), not another Sidebar, route, workspace, framework, permission or ready-state toggle.
- Surface: AR/RTL + EN/LTR and responsive mobile-web already use standard tokens, horizontal scrolling, 44px native links, `aria-current=page`. No DOM or CSS changes.
- Only source change: `EnterpriseTreasuryController::index()` extracts `tab` without casting an array, then checks both scalar type and its original `self::TABS` allowlist **before Treasury data reads**. Unknown, array and nested values are HTTP 404; absent `tab` defaults to `overview`.
- No controller mutation action/service, ledger, cash position, invoice, GL journal, maker/checker, money precision, Treasury entitlement, module readiness, migration, payment or receipt producer changed.
- Owner/fence: new test `V2TreasuryTabQueryFailClosedTest.php`; do not edit `EnterpriseTreasuryWorkbenchTest.php` or other tests on #2858/PR #3065; closed not-merged P302 #2632/PR #3942 excluded. Closed #3969/#3970 and #4007/#4009 must not be reopened for duplicate implementation.

## Test contract (verified after CI, not source inspection alone)
- Authorized test-only Retail Finance operator + test-only version certification, existing STORE Treasury entitlements: AR/RTL and EN/LTR show the selected one of Receipts/Payments/Cheques/Reconciliation/Cash Closing; exactly one active canonical GET tab anchor; selected tab and selected `store_id` in links, no POST form from another hidden tab.
- `?tab[]=payments`, multi-element or nested arrays, and unknown scalar `tab` reject with 404; no receipt/payment writes.
- Foreign-store selector rejects HTTP 403 with actor assigned only to Store A.
- A genuine invalid receipt form submission redirects to the original active `tab=receipts&store_id=A` Referrer with validation errors and no financial write. A valid decimal receipt creates **draft** only and redirects to `tab=receipts&store_id=A` (existing controller behavior; service handles real auth/idempotency). No false assertion that GET executes a payment.
- No change to authorized filters/date/status/pagination/POST back targets; selected tab and store preserved when switching, while different tabs intentionally have different filter semantics.
- Source/route negative assurance is not independent live bank/ledger or viewport/screenreader UAT. Manual device UAT remains parent #2750.

## Security and gates
- **REVIEW_RISK: HIGH** as a security-sensitive Treasury URL; source impact is small but finance privacy/mutations matter.
- Positive GET + negative HTTP + no-write regression; full exact-final-head Repository Policy, V2 Isolation, backend Laravel/Pint/AR-EN/MySQL acceptance and Required CI. Do **not** claim passes without actual terminal CI success. High-risk file-by-file technical review prior to merge; code complete only when the original PR merges to Foodex_V2 on a tested HEAD.
- Backend Treasury/Admin only; Customer/Driver/Van Flutter and public API untouched. B2B Van-only / B2C Driver-only preserved. No main, release, tag, APK or production.
