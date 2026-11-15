# V2 #2602 — P272 Van trip settlement: source and authority audit

Parent: #2503. Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P272, Fleet & Van.
Target: **Foodex_V2 only**. No `main`, release, tags, cPanel, deployment or production data operations.

## Placement and actual user action

- Domain: Fleet & Van; entity: Van trip financial settlement and custody.
- Actor: authorized B2B Van finance supervisor / maker-checker, with store- and channel-effective `finance.view` for reads and separate approved finance action permissions for any settlement.
- Goal: identify **one real trip**, reconcile its receipts, collections, remittances and consignment/returns, approve or reject closeout, and retain an immutable audit.
- Workflow: high-risk, transactional settlement after an evidenced trip lifecycle; data density: per-trip collections, currencies, remittances, adjustments, stock and exceptions.
- Placement order: **Reuse → Extend** the existing administration hub Van Finance Support and the canonical Field Operations Van route/visit experience. **Do not** introduce a parallel finance Sidebar, a fake trip ID, or a receipt balance displayed as an approved trip settlement.
- Existing parent entry points: `/admin/administration` → Van Finance Support (`/admin/van-finance-support`); Field Operations → Visits & Routes for actual B2B Van visits. The authorized route, future tab/popup and handoff must use their current AR/EN, RTL/LTR, responsive pattern.
- Navigation currently has custody wallets/collections/remittances/reconciliation, **not an implemented trip-settlement transition**. P272 remains source-incomplete. A standalone/direct-URL-only page is not an acceptable replacement.

## Verified schema and source boundaries

`backend/database/migrations/2026_10_05_193000_create_van_visit_foundation.php` defines `van_visits` using `actor_user_id`, `customer_type`, `customer_id`, nullable `store_id`, optional `order_id` and visit lifecycle; **it does not own a canonical trip-session ID or Van vehicle ID**.

`backend/database/migrations/2026_10_05_192300_create_collection_custody_remittance_domain.php` defines `collection_accounts` by actor/store/currency, `collection_transactions`, `collection_allocations` by invoice, `custody_ledger_entries`, `remittances` and `remittance_allocations` by collection transaction. Those ledgers **have no immutable visit/trip allocation**. Inferring trip cash from an account balance, Van actor, arbitrary time window or order ID can double count across repeated visits and assign one receipt/remittance to the wrong trip. Nothing in the existing schema proves one-to-one order↔visit↔collection↔trip closeout.

Existing `FieldOperationsFinanceService` groups *actor account* custody and remittances. This is not a trip-specific settlement engine. Existing `DeliverySettlementService` summarizes *order/invoice* payment state, not route/trip custody. No settlement can be certified without a trusted source relationship and financial SoD.

## Implemented bounded regression, NOT end-to-end P272

`backend/tests/Feature/V2VanTripSettlementProvenanceTest.php` exercises the **real existing admin HTTP finance support** and source schema:

1. An authorized Super Admin may inspect a B2B Van collection account in English/LTR and Arabic/RTL, and a same-Van Retail/B2C collection account is absent from the B2B-only result.
2. A roleless operator is forbidden from the finance route.
3. Read-only GET creates no custody or remittance writes, and the current source schema does not claim trip attribution that it cannot prove.

No new finance posting, settlement approval, module entitlement, migration, public API, Flutter screen or navigation route is added. The test validates an existing boundary and protects against falsely interpreting that actor-account view as a trip settlement. It **does not satisfy full issue acceptance**.

## Bounded functional security fix: invoice allocation authority

The P272 follow-up audit found that `CollectionCustodyService::collect()` previously
locked a supplied `invoice_id` and checked currency, but did **not** establish
the invoice belongs to the `collection_accounts.store_id` and matching
B2B/B2C channel. A same-currency invoice from an unrelated store could
therefore be posted as a paid receipt through a direct service caller.

The issue branch adds an authoritative fail-closed guard **inside the transaction
before creating any Payment / CollectionTransaction / CustodyLedgerEntry**:
- Allocated invoice must have the account's non-null, valid store ID.
- Store type is sourced from `stores → store_types`; invoice channel must
  equal this store's actual `b2b`/`b2c` channel.
- Van / Van representative actors cannot allocate a Retail/B2C invoice,
  including the case of a Van collection account incorrectly tied to a B2C store.
- **Symmetric Driver boundary:** a Driver collection account linked to a B2B Wholesale store cannot allocate its otherwise matching B2B invoice. Reject before Payment, CollectionTransaction, allocations, or custody ledger writes; this prevents a direct service caller bypassing the B2B=VAN ONLY rule. Valid Driver Retail/B2C allocations remain supported.
- Missing/unknown store provenance blocks allocations; unallocated legacy
  custody operations remain unchanged because no invoice association is asserted.
- Existing Driver B2C collection is preserved; this guard does **not** add a
  B2B Driver fallback. APIs stay on their existing authenticated paths.

`V2VanTripSettlementProvenanceTest` now covers same-currency cross-Wholesale-store,
wrong-channel same-store, Retail-via-Van, Van-without-store rejection,
**B2B-via-Driver rejection with zero financial writes**, positive
same-store B2B Van collection, and positive legitimate B2C Driver collection,
checking that rejected cases cause **zero** payment/collection/custody writes.

This is real backend authorization hardening, not a trip-level identity,
ledger/posting, maker-checker or financial closeout implementation. P272
remains open for its full trip-session/provenance and UI/Van-app requirements.

## Financial allocation integrity (follow-up security regression)

`CollectionCustodyService::collect()` now rejects a zero, negative, infinite,
or NaN invoice-allocation amount before inserting a paid Payment, collection,
allocation or custody-ledger entry. Previously the service checked only the
overall allocation sum against the receipt amount: a negative line could offset
a positive line and evade that aggregate cap. Numeric-string amounts already
normalized by callers remain accepted where numeric and finite; successful
same-store B2B Van and B2C Driver paths are unchanged.

`V2VanTripSettlementProvenanceTest::test_invoice_allocations_reject_zero_negative_and_non_finite_amounts_without_financial_writes`
covers the four rejection cases, verifies no payment, collection or custody
mutation and proves that a following valid B2B Van collection still succeeds.
This is a bounded financial integrity fix only: it is NOT an approval of
per-invoice outstanding balances, trip attribution, immutable trip identity,
maker/checker posting, mobile offline replay or the whole P272 lifecycle.

## Financial total integrity (bounded direct-service guard)

The canonical CollectionCustodyService now requires `is_finite($amount)` and a strictly positive total for **both** collection posting and pending remittance submission. The preexisting `$amount <= 0` condition rejected zero/negative amounts but could accept PHP NaN (and positive infinity) at this service boundary, risking invalid Payment, CollectionTransaction, CustodyLedgerEntry or Remittance amounts before an underlying database layer rejects the write. This change fails closed **before** any transaction or financial mutation, independent of controller request validation.

`test_non_finite_collection_and_remittance_totals_cannot_create_financial_entries` checks INF, -INF and NAN for both APIs, field-scoped validation and zero payment/collection/ledger/remittance rows, then verifies a valid B2B Van collection and a pending treasury remittance remain functional. No source, store, entitlement, maker/checker, offline replay or actual-trip attribution is widened; Customer and Driver runtime APIs are unchanged. This is not an implemented P272 trip settlement workflow.

## Financial idempotency replay identity — bounded safety guard

The canonical custody service already guarded against reusing a collection/remittance
`Idempotency-Key` under a **different collection account**, but within the same account
it returned the earlier receipt for **any** changed amount, source, invoice allocation,
remittance method, reference or note. That falsely acknowledges a different financial
request. New regression coverage enforces canonical immutable replay terms inside the
locked transaction:

- A collection replay checks the existing account, amount, currency, source and the
  persisted **per-invoice allocation identities/amounts**, independent of input ordering.
- A pending, approved or reconciled remittance replay checks the account, amount,
  currency, method, reference and note; changed terms produce a field-specific
  `idempotency_key` validation error rather than a misleading prior result.
- Identical legitimate retries return the original transaction/remittance, and
  negative cases create **no extra Payment, CollectionTransaction,
  CollectionAllocation, Remittance or CustodyLedgerEntry**.
- Comparisons allow only the existing three-decimal DB financial rounding tolerance
  (0.0005); non-finite totals remain prohibited at source.

No new posting, trip attribution, stock, UI or scope permission is created.
This still does **not** satisfy P272 trip settlement/maker-checker, WMS and offline
Van parity; source-gate and Issue stay OPEN until all are proven.

## Required authoritative design and completion steps

1. Define an immutable trip/session identity (Van, authorized store/tenant/channel, route, start/end and actor), link Van visits/orders to it, and handle legacy unknown provenance without inventing attribution. Lock and audit trip lifecycle/SoD; B2B uses VAN ONLY, B2C uses DRIVER ONLY.
2. Record explicit per-trip collections, reversals, currency, invoice allocation, cash custody and remittance allocation under concurrency/idempotency rules. One receipt must never settle multiple trips without an explicitly tracked split allocation.
3. Reconcile Van stock load/unload, consignment, sales/returns, shortages, adjustments and damaged/lost stock with authoritative WMS and financial posting; prohibit closeout for pending/ambiguous evidence. Decide legal ownership/valuation explicitly, do not reclassify legacy stock.
4. Implement separate maker/checker actions with server-side permission, store, channel, module entitlement/readiness, audit trail, protected approval and safe retry. A fail-closed human recovery workflow must exist for ambiguous legacy records.
5. Extend the canonical Van Finance Support/Field Operations UI after current owners release shared file fences; use authorized business lookups, AR/EN localization, responsive 320–1280+ layout, focus/keyboard states, no direct inaccessible URL.
6. Add real Laravel HTTP negative/positive/cross-store and actual-trip/finance/stock reconciliation tests, exception/retry tests and mobile parity. Run exact-final-head Repository Policy, V2 Isolation and Required CI (Laravel/Pint/PHPStan/AR-EN/MySQL/Redis) before a merge.

## Ownership fences and app impact

At initial preflight:
- #2607 / PR #3313 owns `VanFinanceSupportController.php`, `FieldOperationsFinanceService.php`, Van Finance Support Blade and AR/EN locales; P277 closeout **is not P272 trip settlement**.
- #2606 / PR #3311 owns Field Operations Van visit controller/Blade and related locales; P276 route activity is **not a finance settlement**.
- #2604 / PR #3330 owns `VanInventoryReadService.php`; its Van inventory read is **not a reconciled custody movement**.
- #2588 / PR #3317 owns WMS forged-Van-target guard. The current PR changes **only the new P272 test and this note**.

| Surface | Current increment | Remaining actual P272 effect |
| --- | --- | --- |
| Backend/API | Existing admin HTML read contract verified; no API schema changes | Trip identity, settlement ledger, authorization, approval/reversal endpoints and audit needed |
| Dashboard | Existing Finance Support accessed by real HTTP; no UI edits | Real approved trip settlement entry/approval/exception flow required |
| Customer | No public API or Customer app change in this test-only increment | Review invoice/collection balances upon actual future posting; link separate V2 Customer issue if changed |
| Driver | No B2C Driver API or app change; no B2B Driver fallback | None planned under B2B=VAN ONLY; link separate Driver issue if later affected |
| Van | No Van app code change in this test-only increment | **Affected in full P272**: live per-trip assignment, receipt/stock proof, offline replay, settlement exception. Coordinate with #2739; app and API tests required before closing P272 |

## Release and acceptance state

**FULL P272 SOURCE INCOMPLETE / NO SAFE FULL TRIP-SETTLEMENT MERGE OR CODE-COMPLETE CLAIM.** The independently reviewed, issue-scoped invoice allocation isolation regression and fail-closed service guard are a bounded security slice that may be integrated only into `Foodex_V2` after every required exact-final-head check is GREEN. This *partial integration* does not authorize trip closeout, per-trip cash posting or stock reconciliation, and Issue #2602 must remain OPEN until all remaining P272 source requirements are implemented and verified. No claim of fresh-install/upgrade success, full Laravel pass or live-account proof is inferred from source inspection; attach exact head CI evidence when available. Once full source and CI are green, manual owner/browser/device UAT may be recorded as `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503, not before.

No `main` integration, tags, releases or production deployment.

## Duplicate invoice allocation IDs — consistent collection idempotency

The collection write path previously permitted two positive lines with the
same invoice ID even though its replay validator insists on unique invoice IDs.
This could create an acknowledged paid receipt that cannot be replayed with
identical terms, as well as duplicate allocation rows. The bounded source guard
rejects non-positive or duplicate IDs in the same existing transaction **before
financial writes**. The real RefreshDatabase regression verifies rejection
without Payment, CollectionTransaction, allocation or custody entry; it then
proves a corrected first submission with the same key and its replay both
return the exact single transaction. Existing store/channel checks and
B2B=VAN ONLY / B2C=DRIVER ONLY remain intact.

Dashboard: no UI or route changes. Customer: no public API/Flutter changes.
Driver: no public API/Flutter changes. Van: no public API or Flutter payload
change; invalid duplicate invoice lines now fail closed in the backend.
No production, main or release artifacts. Full P272 trip-session, stock and
maker/checker settlement acceptance remains open.

## Serialized custody reservations and inactive-account denial (follow-up, NOT P272 closure)

On the original #2602 issue branch, `CollectionCustodyService::submitRemittance`
now obtains a database `FOR UPDATE` lock on the authoritative collection
account **inside** the remittance transaction, before searching for an
idempotent submission and calculating available custody. Separate pending
remittances for the same account are thus serialized on MySQL/MariaDB rather
than relying solely on an unlocked aggregate, which could accept competing
requests that each observed the same available balance. The locked account's
current currency is rechecked, and a non-active account cannot create a new
pending remittance. An exact existing-key replay remains a read-only
acknowledgement even if the account was subsequently deactivated.

The focused `V2VanTripSettlementProvenanceTest` regression covers the
inactive-account rejection with zero new remittance/ledger writes, valid
idempotent replay, later reactivation, the entire remaining balance
reservation, and rejection of additional over-reservation. This is a
transactional source guard; the test does **not** simulate two independent
MySQL concurrent connections and cannot alone certify concurrency under all
production isolation settings. Exact-head full MySQL/Redis and Laravel CI
must still be checked.

Customer/Driver/Van UI and public payload contracts, Dashboard pages, AR/EN
copy and dispatch remain unchanged; this is a service-level fail-closed
financial invariant. Real P272 trip ID, stock provenance, independent
maker/checker, trip settlement posting, reconciled returns and Van offline
flows are still missing. Keep #3332 DRAFT and #2602 OPEN until the complete
source acceptance and final-head checks succeed. No main, tags, releases or
production changes.

## Remittance replay lock-order safety (same #2602 incremental scope)

Maker/checker approval and reconciliation currently lock an existing
`remittances` row **before** locking its collection account. New remittance
submissions need the inverse, account-first lock to serialize distinct-key
reservations against aggregate pending custody. A replay of an existing
Idempotency-Key must **not** acquire the remittance row lock after the account
lock or the two paths may deadlock if approval/replay interleave. The canonical
service therefore performs a **read-only** terms and account ownership lookup
under the held account row lock, without `FOR UPDATE` on remittances.
Financial terms remain immutable in the existing service transitions, so a
concurrent reviewer updating status/review metadata cannot change replay
identity or authorize new writes. Database UNIQUE(idempotency_key) still
prevents a racing key from creating duplicate financial rows across
different accounts. This bounded guard does not claim to normalize all
cross-account simultaneous-key constraint errors into HTTP 422, or to prove
deadlock freedom across all third-party writers. Dedicated real concurrent
MySQL testing remains required at full P272 acceptance.

The new inactive-account/replay and reserved-balance test, along with
existing collection/remittance lifecycle tests, exercises the post-change
read-only path sequentially. No new routes, API payloads, migrations,
Customer/Driver/Van mobile code, or legal stock ownership changes.

## P272 2026-11-15 — custody maker/checker security increment

The existing `CollectionCustodyService::submitRemittance` saves the submitting user in `remittances.submitted_by`, but `approveRemittance` previously accepted that same user as the approver, posting a negative custody ledger movement without independent review. On the original P272 branch the locked pending-remittance approval now **fails closed** if maker identity is absent or equals the reviewer ID, before any custody balance or journal write. It preserves the existing approved-status replay path, avoids duplicate ledger deduction, and does not add a new settlement, route or permission.

The new isolated Laravel regression verifies: the maker's approval gets a `reviewed_by` validation error; pending status, null reviewer and original custody balance remain unchanged; a different checker can approve exactly once; and identical approved retries add no second ledger entry.

**Not full P272 acceptance:** the account's pending remittance is still not attributable to a single immutable Van trip or its physical stock movement, and separate authoritative trip-settlement maker/checker, ledger reconciliation, AR/EN Dashboard and Van app offline parity remain unimplemented. Preserve PR #3332 as DRAFT and Issue #2602 OPEN, even if tests succeed. Actual source completion requires the source-proven trip/session and per-trip collection/remittance/return identities; never infer trip closure from an account-level custody balance. No `main`, production, tags or release.
