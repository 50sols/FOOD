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

## Finance Workbench inbox — ownership reconciled, source implemented

The former #2633/PR #3309 Finance Workbench lane was **closed unmerged**, releasing its original file fence. The scoped #4397 issue-lease reconciliation explicitly assigned the previously unowned Finance Workbench controller, Finance blade and its AR/EN translation files to **this original PR #4398**; #2858/PR #3065 retains ownership of shared AdminNavigation and FinanceWorkbenchTest, neither of which is modified. No second branch or overlapping PR was created.

- Canonical existing route: `admin.erp.finance.index?tab=discount_approvals&store_id=…`. Placement: **Finance → Customer Service concession request → real Finance reviewer → approve/reject → Orders protected review POST**. **Compose a sibling workbench tab**; no new Sidebar entry or route.
- Domain Finance/Customer Service concessions; entity order and immutable discount request; role FINANCE B2B or RETAIL_FINANCE B2C; goal discover pending decisions; high-frequency, multi-row operational workbench; existing parent Finance Workbench; mobile/tablet/desktop use the same responsive existing tab and table primitives; locale Arabic RTL / English LTR.
- View authority: the module must be enabled and legally entitled for read; actor must be active, carry the authentic channel-matching Accounting role, and possess both effective `finance.view` and `finance.manage` for the **selected operational store**. Query joins pending requests to orders and filters **exact `store_id` plus exact `b2b`/`b2c` channel**, before pagination. Store/role denial returns 403/404 per canonical Finance context handling.
- Actions reuse `admin.operations.orders.customer-service-discount.review`, never create a new mutation path. Its existing locked controller/service revalidate independent role, permission, exact order/request identity, maker/checker, pending state, replay and real cost/write-off constraints. Self-review controls are hidden but direct POST is separately denied.
- Forms have CSRF, accessible translated labels, required rejection justification and responsive 44px actions. The Finance page surfaces the original review POST's `status` flash on redirect, not just the unrelated Finance `success` key.
- New focused `V2AccountingDiscountFinanceInboxTest` covers B2B pending-queue isolation, Arabic/English direction, real Accounting approval/replay and audit, Customer Service rejection, maker self-denial, Retail store isolation and direct foreign-order denial. Existing #4397 request, cost and channel protection tests remain.

### Explicit unresolved acceptance — do not merge or close prematurely

- Verify **exact-final-head** Policy, V2 Isolation, Required CI including Laravel shards/MySQL after all changes. Prior green head is not final evidence.
- Independent **HIGH-risk** GitHub technical approval, not a self-review or owner's assertion, for Finance role, tenant/store/channel isolation, non-mutation on read, P129 write-off and maker/checker boundaries.
- Real browser RTL/LTR and mobile-width (320/360/390/412/430px), tablet/desktop accessibility/UX review of tab, filtered list, approve/reject, confirmation and empty/error states. No false assertion that this manual UX step has been performed.
- Guarded merge **only** into Foodex_V2 when source acceptance and review gates pass, then confirm postmerge CI before closing #4397 and parent #2824. No main/release/production/mobile promotion authorized.


### Exact-key request replay integrity regression

Request creation now treats an exact `order_id + idempotency_key` retry as a duplicate only when the requester, normalized rationale and percentage match the original locked request. A changed discount percentage/rationale or different actor cannot reuse that key to conceal conflicting financial intent; it fails validation before any new audit or pricing action. Added authenticated HTTP regression `test_request_idempotency_key_cannot_mask_changed_discount_or_requester` in the original issue-owned feature test. This is a narrowly scoped source correction, not independent financial UAT or full #2824 acceptance. Current required exact-head CI must complete before integration.
