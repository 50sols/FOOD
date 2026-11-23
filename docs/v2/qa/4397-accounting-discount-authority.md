# V2 #4397 / parent #2824 — Accounting owns Customer Service discounts

**Date:** 2026-11-23 · **Target:** `Foodex_V2` only · **Owner directive:** [#2824 comment](https://github.com/walidatiyaai2025-gif/FOOD/issues/2824#issuecomment-7098379576)

## Authorized business rule

- No arbitrary numeric threshold is assigned to Customer Service discount percentages. The input is a bounded percentage between 0 and 100 by its mathematical meaning; no invented 5/10/20% business cap is applied.
- Customer Service Agent, Supervisor, Manager and other operational users submit a **pending** request; none of their generic `orders.approve`, `orders.manage` or even Super Admin status silently grants the right to apply it directly.
- Only a distinct, **active** Accounting actor with the authentic **FINANCE (B2B)** or **RETAIL_FINANCE (B2C)** role **and store-scoped `finance.view` + `finance.manage`** may approve/reject a pending request for that channel and effective store. A second role or global permission by itself cannot change the accounting role requirement.
- The controller and locked approval service both check finance authority before mutating any record. A requester cannot approve their own request, even if they also hold an Accounting role.
- Pending requests never change pricing or notify customers. Approval invokes the existing protected transactional discount service exactly once and audits the real Accounting reviewer; rejection is audited without financial mutation. Idempotency and stale order/payment/dispatch/tax checks remain.
- **No implied full write-off, loss-making order, credit exception or P129 margin bypass.** Full positive-value order-to-zero concession remains separately denied until an approved financial write-off flow exists. `#2853` owns additional verified cost/profit/credit exception controls.
- AR/EN, RTL/LTR web copy describes Accounting decision instead of promising a managerial direct application. Mobile impact: Customer, Driver, Van are **NO DIRECT FLUTTER CHANGE**, existing orders API and B2B=VAN ONLY/B2C=DRIVER ONLY are preserved.

## Bounded code/test changes

- Controller: request-only flow for all authorized Customer Service users; Finance-only HTTP review authorization.
- Approval service: fail-closed second auth/store/channel boundary under row lock; reject unreviewable paid/dispatched requests at submission; no permission widening.
- Existing acceptance tests: scoped Finance (B2B) approves once, manager and generic Super Admin denied; independent request and no-write, large 80% concession, 100% write-off guard, same-store Retail Finance vs Wholesale distinction, stale/replay tests; older direct-manager tests rewritten for request → Accounting review.
- Operations Orders UI: Customer Service form now says *Request* and no longer renders actionable approval buttons to non-finance users. Pending requests remain read-only to scoped CS managers.

## Remaining release blocker — DO NOT MERGE THIS BACKEND-ONLY PR YET

Current Finance Workbench source `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`, `backend/resources/views/admin/finance-workbench.blade.php` and shared `backend/routes/web.php` are already on original **#2633 / PR #3309** file fence. Shared `backend/app/Support/AdminNavigation.php` is on **#2858 / PR #3065**. Do not edit those files from this PR or create a second conflicting finance UI.

**The Accounting reviewer currently lacks a discoverable authorized list of pending discounts.** The existing Operations Orders list depends on `orders.view`, which FINANCE does not have; merely providing a privileged POST endpoint is **not** functional completion. Coordinate with original #3309/#3065 owners after their merges to extend the *existing* Finance Workbench with a scoped pending-discounts tab/inbox, bilingual decision controls and actual HTTP authorization/multi-store/a11y/RTL acceptance. Do not introduce a second Finance Sidebar module or direct-URL-only completion.

Acceptance remains BLOCKED until that Finance UI is implemented on an authorized owner branch; exact-head required tests, security/role/store negative paths, real CI, reviewed/guarded PR merge and postmerge acceptance are green. Parent #2824 remains OPEN; a successful unit build or partial tests cannot certify the whole feature.
