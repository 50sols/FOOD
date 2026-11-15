# V2 P277 — Van closeout financial review (bounded delivery)

Issue #2607, parent #2503. Integration target: `Foodex_V2` only.

## Placement and authority
- Domain: Fleet/Van Operations with Finance settlement review.
- Entity: canonical `collection_accounts` for `actor_type=van` scoped by active Wholesale store and currency; NOT a trip session.
- Role: authenticated Super Admin or `finance.view`, additionally constrained by authorized B2B store IDs. Unauthorized = HTTP 403; unauthorized Retail/other store records never appear.
- Goal/workflow: inspect financial custody and unreconciled remittances before considering operational closeout. Read-only, high-frequency finance audit, tabular bounded pagination.
- Existing parent: Administration Hub > Van Finance Support, canonical `admin.van-finance-support.index`, `VanFinanceSupportController`.
- Placement: **Extend** existing canonical page with `ops_tab=closeout` PAGE_TAB. No new Sidebar group, route, controller or entitlement; no duplicate ledger.
- Navigation: Administration Hub card > Van Finance Support > Closeout Review. Desktop/tablet/mobile use existing route and responsive page; RTL and LTR use paired `van_finance_support` translations; no new mutating UI states.
- Data: `custody_ledger_entries` net balance, pending `remittances`, approved-awaiting-reconciliation count. Computed status is **ledger review**, never **trip closed**. Stale/empty ledger = no financial blockers observed, not proof that a trip exists or may be closed.
- States: empty, financial-review-required, financial-ledger-clear. The tab performs no POST or side effect.
- Mobile impact: Customer **not affected** (no API/price/order change); Driver **not affected** (excluded from the tab, B2C remains DRIVER ONLY); Van **not affected on app contract** (no Van endpoint or screens changed, existing B2B VAN ONLY). A future authoritative trip-close API requires its own cross-app contract verification.

## Source gaps blocking Issue closure
P277 asks for **route/trip closeout and settlement**, which cannot be proven using the current collection account ledger. Existing `van_visits` lifecycle closes individual visits, and legacy route metadata is not a durable, unique, finance-attributable trip session. Van custody is per collection account, not per trip. Neither a closed visit nor zero account balance authorizes declaring a trip financially settled. Before full P277 acceptance, establish source-backed unique trip identity, ownership, assignment/time boundaries, stock load/returns, visit/order inclusion, receipt attribution, maker/checker settlement, immutable audit and idempotent close/reopen rules. Do not fabricate `trip_id` or post closeout journal entries.

## Verification plan
- `php artisan test --filter=VanFinanceSupportTest`
- `vendor/bin/pint --test app/Services/FieldOperationsFinanceService.php app/Http/Controllers/Admin/VanFinanceSupportController.php tests/Feature/VanFinanceSupportTest.php`
- Full Laravel, localization, security and exact-head Required CI, Repository Policy and FOODEX V2 Isolation Gate.
- Pending interactive runtime checks: AR/EN, desktop/tablet/mobile 320–1280px and screen reader/keyboard. No release, main or production claim.

**Acceptance status:** bounded financial read-side implemented; full operational trip closeout **NOT YET COMPLETE**. Keep #2607 open.

## 2026-11-15 — literal search regression on original P277 draft (unmerged)

The existing finance support search input `ops_q` was passed as a raw SQL `LIKE` pattern in four authorized read tabs: wallets, collections, remittances and the pending closeout review. A typed percent (`%`) or underscore (`_`) therefore broadened matches instead of searching for those characters. This is a **query correctness and scope-bound results problem**, not evidence of SQL injection or of a new authorization bypass.

The original issue-owned `FieldOperationsFinanceService` now reuses `App\\Enterprise\\Search\\LiteralLikePattern::contains` and bound `LIKE ? ESCAPE '!'` for all 20 fixed-column predicates. The escape marker encodes `!`, `%`, `_`, preserving a typed backslash. The query remains grouped beneath the existing `whereIn(collection_accounts.store_id, authorizedStoreIds)`; numeric account/transaction ID search, status predicates, pagination and result order are unchanged. No money, remittance, visit or ledger mutation has been added.

The original `VanFinanceSupportTest` includes a real `RefreshDatabase` seeded fixture with distinct percent/underscore/exclamation/backslash tokens, authorized B2B stores, wildcard decoys and a separate foreign B2B store. All four read tabs must return only the exact in-scope match and preserve collection, remittance and custody-ledger counts; the authenticated Super Admin closeout GET must filter the decoy. This does not claim that a Super Admin is forbidden from seeing a legitimate foreign store; the store-bound test uses explicit authorized store IDs.

**Affected surfaces:** Backend service + existing Dashboard GET only. **Customer:** no API/Flutter change. **Driver:** no API/Flutter change. **Van:** no API/Flutter change; no new trip settlement endpoint. Existing AR/EN markup, authorization and B2B=VAN/B2C=DRIVER contracts are unchanged.

**Required next gates:** final-head Pint/PHPStan/security/AR-EN, real Laravel regression, MySQL/Redis package/recovery, Repository Policy, V2 Isolation and terminal Required CI; risk HIGH (financial/scoped reads) requires a line-by-line COMMENTED technical audit. All results must be collected against the latest exact original PR head. No merge or issue closure is permitted while the full P277 trip provenance, stock, remittance maker/checker and Van parity remain unimplemented.


## 2026-11-15 — Do not mark absent custody ledger as financially clear

- **Defect:** The read-only Van pre-closeout table used `COALESCE(SUM(custody_ledger_entries.amount), 0)`, which conflated a legitimate zero-sum ledger with **no ledger entries at all**. An untouched Van collection account could therefore misleadingly display `Financial ledger clear`.
- **Source fix:** The Van closeout aggregate now also selects `COUNT(*) AS custody_entries` from the *same scoped collection_account_id group*. A zero-entry account reports the existing localized `Financial review required` state even when the aggregate balance and pending-remittance amounts are zero; a legitimately nonempty zero-sum ledger retains the existing financial arithmetic decision. No new role, route, Sidebar node, settlement mutation, trip ID or authority. Other wallets/remittances/reconciliation query calculations are unchanged.
- **Test:** Existing seeded `VanFinanceSupportTest::test_closeout_review_is_read_only_and_restricts_accounts_to_wholesale_vans` asserts empty-ledger fail-closed before any ledger activity, while retaining later positive custody/remittance review and B2C/Driver exclusions, AR/EN security and ledger no-write checks. Full operational trip closure remains source-incomplete even when a ledger has records and its balance is zero.
- **Scope impact:** Admin Backend/Dashboard GET only; Customer/Driver/Van apps and APIs unchanged. Do not merge original #2607 PR as full P277 or close the issue before canonical trip membership, signed custody/provenance, maker/checker, offline replay and independent exact-head tests are implemented.

## 2026-11-15 — Inactive financial actors cannot show ledger-clear pre-closeout

- A balanced, nonempty account ledger alone cannot make an inactive/suspended Van or collection wallet appear ready for a pre-closeout review. The previous read model ignored both persisted statuses and displayed `ledger_clear` when net custody and outstanding remittances were zero.
- The existing P277 read query now includes `vans.status AS van_status` and the computed `review_state` fails closed for any Van/account status other than exact `active`. The localized AR/EN review-state labels, existing read-only endpoint, B2B authorized-store scoping and transactional data remain unchanged.
- A seeded regression checks the positive active/nonempty/balanced case, suspended wallet and suspended Van negatives, authorized HTTP localized read, and no ledger/remittance writes. This does **not** establish immutable trip identity, real custody-to-trip attribution, maker/checker, GL, mobile offline replay or full P277 acceptance; keep original PR draft and Issue open until all source gates are met.
