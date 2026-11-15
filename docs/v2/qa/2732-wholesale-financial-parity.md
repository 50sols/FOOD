# V2 MOB-C03 / #2732 — Wholesale financial snapshot integrity

**Scope:** Customer app signed B2B credit/available-line/purchasing-power presentation; original Issue #2732, source T115R02/P129, V2 isolated integration target `Foodex_V2` only.

## Canonical contract and defect

`backend/app/Services/B2bAccountLedgerService.php::summary` defines a positive balance as customer credit, a negative balance as customer debt and zero as settled. It computes available credit line as max(credit limit minus receivables, zero), and purchasing power as available line plus positive customer credit. The `/api/v1/b2b/account-summary` endpoint is the server authority; client calculations are DISPLAY VALIDATION ONLY and never authorize checkout, payment, discounts, collections, borrowing, write-offs or financial postings.

The existing account-hub UI trusted `balance_direction` as a label even if it contradicted the signed balance. It also silently defaulted missing or invalid money fields to zero, which could mislead a Wholesale customer about debt and spending power. The change introduces `WholesaleFinancialSummary.tryParse` to reject inconsistent sign/direction, nonfinite/missing monetary fields, negative credit limits, missing currency or broken server-derived credit/purchasing-power relationships. Invalid snapshots now show an AR/EN localized retry state rather than fictional settled/credit/zero figures. The client also rejects non-three-letter uppercase currency tokens and bidirectional display controls, preserving normalized valid ISO-style currency labels. The active More finance card also preserves the Dashboard shortcut and uses natural-height error content at 320px / 1.3 text scaling; the historical narrow test fixture has been made consistent with the signed B2B ledger. The More card also requires the server-owned `total_purchases` (or `total_debits`) and `outstanding_receivable` (or `open_amount`) to be valid finite nonnegative amounts: malformed, NaN or missing financial totals must not silently render as **0**. Its existing bilingual retry state is reused; nothing is posted or recalculated client-side. No amount is modified, and a new request on Retry uses the existing authenticated API and scope.

## Reuse, authorization and application impacts

- **Customer:** Reuse existing authenticated `/b2b/profile` account hub, same scoped `/api/v1/b2b/account-summary` request. No new screen, route, amount, pricing formula or financial client authority. Active `_B2bMorePage` uses `_FinanceCard`, and the latent account-hub fallback uses the same validated data contract. Existing shared widgets and locale keys preserved, RTL/LTR covered.
- **Backend/Dashboard:** Unchanged. The authoritative B2B ledger service, credit/discount/partial collection/approval and their RBAC/SoD enforcement remain server-side. No new privileged endpoint or client posting.
- **Driver:** No Driver API, collection UI, assignment or runtime contract change. B2C=DRIVER only preserved.
- **Van:** No Van API, custody, route/assignment/offline/settlement contract change. B2B=VAN only preserved.

## Invoice/partial-collection display integrity

The existing B2B invoice-list `_InvoicesRemoteState._money` previously converted absent or non-numeric `paid_amount`, `outstanding_amount` and summary totals into a fabricated zero, including partial-collection financial status. It now shows an explicit unknown-value dash for absent, non-numeric and nonfinite amounts, while preserving genuine numeric zero and existing currency formatting. The route remains the authenticated `/b2b/invoices?store_id=<scoped-store>` workflow, with no client authority to settle, post, adjust or collect financial balances. AR/EN widget regressions verify malformed per-invoice paid values and grouped paid/debt/overdue are not rendered as `0.000 KWD`, that directionality follows the selected locale, and the scoped API request remains unchanged. Backend, Dashboard, Driver and Van code/contracts are unchanged; source-authoritative settlement remains server-side. This bounded display correction does **not** complete partial-collection write-path, invoice reconciliation, store isolation or broader MOB-C03 acceptance.

## Verification and remaining source coverage

`test/v2_wholesale_financial_summary_test.dart` verifies signed debt/credit/settled, canonical purchasing power, missing monetary fields, NaN, contradictory direction and cross-field drift. Both pure-model tests and four AR/EN More-screen widget regressions now additionally reject invalid purchase or receivable totals and verify no zero-balance substitution. `test/b2b_journey_test.dart` adds AR/EN/RTL-LTR UI regression that invalid data does not become a misleading zero-balance screen, and corrects one synthetic fixture to the real negative-debt convention. Existing B2B shopping/checkout/credit/price, invoice/partial payment and statement tests remain applicable. Required exact-head Customer Flutter and repository/isolated backend gates must pass.

**Not yet full MOB-C03 acceptance:** The larger source still requires an integrated, tested customer checkout/pricing/discount/partial-collection matrix with authorized B2B store/session/finance state, idempotent payment and account statement readback, AR/EN and failure modes. Do not close #2732 or merge this bounded change as if that entire source-wide acceptance were complete until those tests and any missing runtime fixes are provided. Manual staging/device UAT is deferred to master #2503 only; code and CI cannot be waived. No `main`, production deployment, releases, tags or APK publication.

## 2026-11-15 — B2B statement financial truthfulness continuation

- **Problem:** The existing authenticated Customer B2B account-statement screen silently converted malformed/missing or non-finite `closing_balance`/`balance` to 0, displaying “Settled” with no valid financial evidence. Row-level debit, credit and running balance also displayed fabricated zero for malformed values.
- **Implementation:** The existing account-statement renderer now validates finite numeric closing/current balances and uppercase three-letter currency before showing any financial direction. Invalid authoritative snapshots render the existing localized error/retry card, not a settled or financially valid table. A legacy response with only a valid `balance` remains supported; explicitly malformed `closing_balance` does not fall back silently. Row-level malformed/missing/nonfinite amounts display an em dash, while legitimate numeric zero and sign colors remain supported.
- **Automated regression:** two existing-route Customer widget scenarios in *each* AR/EN locale (four widget tests) cover broken balance fail-closed and broken transaction amounts without invented zero, retaining `store_id=7` scoping. Reuse `FoodexCustomerApp`, `_FakeB2bApi` and the canonical Customer route; no new screen or endpoint.
- **Cross-app impact:** Customer financial read display only. Backend/Dashboard server ledger, discount, pricing, partial-collection mutation/SoD, Driver and Van APIs and Flutter remain unchanged. This DOES NOT complete MOB-C03 checkout/pricing/discount/partial-collection/ledger and cross-store/role end-to-end acceptance. Original #2732 PR must remain DRAFT and Issue OPEN until remaining source criteria and exact-final-head CI are proven.

## 2026-11-15 — dashboard non-finite financial KPI fail-closed regression

- **Verified code gap:** the authenticated B2B dashboard's own `_money` formatter used `double.tryParse` without `isFinite`. Dart accepts `NaN`/`Infinity`; a malformed dashboard API response could print non-money text as if it were an amount in the credit/purchase hero and financial/monthly KPI cards.
- **Change:** reject non-finite dashboard KPI values to an explicit em dash, preserving valid payment amounts, the original B2B dashboard API, Arabic/English directionality, and navigation. Only the existing Customer read-only presentation is affected; no checkout/credit/ledger computation is done client-side.
- **Regression:** two AR/EN widget cases use a fake server payload with NaN/Infinity across credit, purchase, open-invoice, overdue and purchases-month fields; check unknown values and a legitimate `12.500 KWD` payment, B2B route scope and RTL/LTR.
- **Impact:** Customer view-only. Backend/Dashboard authorization/money posting and Driver/Van API/binaries unchanged. This is a bounded source correction, not complete MOB-C03 acceptance; keep original #3337 DRAFT and #2732 OPEN while checkout, discounts, credit, partial collections, ledger and store/RBAC/SoD E2E obligations remain outstanding.

## 2026-11-15 — signed dashboard credit guard

The Customer B2B Dashboard hero previously trusted `customer_credit_balance` without reconciling it to the signed ledger `balance` and `balance_direction`; when missing, it silently computed a derived credit. The authoritative Laravel `B2bAccountLedgerService::summary` specifies positive signed balance as customer credit, negative as customer debt, and `customer_credit_balance=max(balance,0)`.

The new existing-model `dashboardCustomerCredit` verifies finite balance/credit, nonnegative credit, direction agreement and rounded credit consistency; missing or contradictory snapshots show an em dash instead of fabricated credit. Pure tests cover debt, positive credit, settled, forged credit, missing, wrong direction, nonfinite and malformed values. AR/EN RTL/LTR widgets verify that forged credit stays unknown while legitimate open invoices/payments and B2B dashboard API are unchanged. An old contradictory positive-credit fixture was aligned with the server convention.

**Impact:** Customer read-only dashboard only. Backend and Admin Dashboard monetary posting, Customer checkout/discount/partial collection, Driver and Van APIs/UI are unchanged. Full MOB-C03 source-required checkout, credit, pricing, partial collection, ledger reconciliation, RBAC/SoD and end-to-end acceptance remain pending; green incremental CI does not permit premature PR merge/Issue closure.

## 2026-11-15 — original Dashboard test fixtures aligned to signed source

The exact-head Customer Flutter fast-fail for `0b5daf7` reported **565 tests passed, one failed**: the pre-existing authoritative Dashboard refresh test expected `35.000 KWD` even though its fake server payload had `balance=10`, `balance_direction=customer_owes_company` and `customer_credit_balance=35`, a contradiction now correctly rejected by the source guard. The test-only fake is corrected to a positive signed credit of 35 with matching direction and credit line; the visible expected `35.000 KWD` remains unchanged.

The separate existing `dashboard_single_screen_test.dart` visual/refresh fixture also had inverted signed balance direction in both original/second response. It now uses coherent positive credit balances and available-line values without changing the expected credit amounts, translations, app runtime or route. This extra test-only file was coordinated via the #2732 source-fence comment before modification, with no active peer PR owning that path.

This is a test-fixture correction **not** a server financial policy change. Required exact-final-head Flutter and repo CI must be rerun after the new commit; prior red remains red.

## 2026-11-15 — B2B checkout malformed finance snapshot fail-closed

The existing Customer Wholesale checkout converted untrusted quote totals and
purchasing-power data using a permissive monetary helper. NaN could evade
the insufficient-credit comparison; invalid totals could appear as zero.
The existing checkout now requires finite, nonnegative authoritative money:
order grand total for every method, purchasing power for account credit.
Missing/malformed values disable confirmation and show bilingual retry, with
no fabricated local money. Valid zero, cash without credit and normal credit
remain supported. AR/EN widget tests cover invalid total, missing/nonfinite
credit, and valid-credit positive control on the original store-scoped route.
No finance server-side writer, new endpoint, settlement, Driver or Van changes.
Full source MOB-C03 requires server-side store/RBAC/SoD checkout and ledger,
partial-collection and discount E2E; PR #3337 remains DRAFT until complete.

GCC regression initially reproduced an additional pre-existing disabled Checkout button lifecycle exception: the late AnimationController initialized in dispose() after the widget was deactivated, triggering unsafe ancestor lookup. The same original Checkout state now eagerly constructs its ticker in initState and safely disposes regardless of enabled state. All eight new Arabic/English negative/positive widget cases then PASS without teardown exceptions. No new route/feature, pricing authority, payment mutation, or non-Customer application change.

## 2026-11-15 — fail closed on missing or malformed EGP order minimum

The authenticated Customer Wholesale checkout was silently substituting a hard-coded `1500` when the server-authoritative `minimum_order_egp` was missing or malformed, or treating negative input as no threshold. Such a fabricated policy could enable a checkout without a trustworthy minimum-order decision. The existing checkout UI and guarded submission now require the EGP minimum to be finite and nonnegative, explicitly accepting a genuine zero while rejecting missing, negative, NaN and infinity. Invalid policy displays the existing bilingual financial-unavailable retry state and disables submission; no new route, code path for calculating prices, write authority or default minimum was added. Non-EGP minimum logic retains existing currency behavior.

GCC isolated exact-source Customer widget run: `flutter test test/b2b_journey_test.dart --name 'MOB-C03 checkout verified money' -r expanded` — 16/16 PASS on AR/EN including eight new negative policy cases. The hermetic tests verify the confirm button has no handler and `checkoutCalls == 0` for rejected payloads; positive zero-minimum/valid-credit controls remain enabled. Broader Windows suite still has inherited header lifecycle failures, and final hosted exact-head CI must certify the new commit.

**Known unrelated peer-owned test blocker:** The base PR branch still contains the pre-#2741 animated storefront header's lazily constructed AnimationController, which breaks broad Windows B2B widget tests on widget unmount. Original issue #2741 / PR #3445 owns `storefront_design_system.dart` and its lifecycle fix. Do not edit or claim this peer file here; reconcile only after the peer source integrates. Source-wide MOB-C03 checkout/discount/partial collection ledger, cross-store RBAC/SoD and idempotent reconciliation remain incomplete, so #3337 stays DRAFT and #2732 OPEN. No main, release, production or tag.

## 2026-11-15 — require authoritative final quote, never substitute subtotal

On the same existing Wholesale checkout, `effectiveQuote['grand_total'] ?? cart['grand_total'] ?? cart['subtotal']` silently treated a missing final server quote as a valid subtotal. The subtotal is not the final amount: delivery, tax and discount may change it. An AR/EN red regression reproduced the issue: with `quote.grand_total = null`, cart subtotal `25.0` and a valid 0 EGP minimum, the existing checkout incorrectly omitted the financial-unavailable error. **Both new tests failed before correction**.

Checkout rendering now requires a finite, nonnegative `effectiveQuote['grand_total']`; no substitute amount is invented. The guarded submit path separately revalidates the final quote and, for account credit, finite purchasing power/insufficient-credit state before any request. The server still owns prices, authorization, idempotency and ledger posting. The established bilingual retry/error and normal zero/positive quotes remain intact. The existing `_FakeWholesaleCommerceApi` success fixture now provides `quote.grand_total`, matching real `GuestCartController::cartPayload()`, rather than relying on a fake top-level fallback.

GCC isolated tests on original branch with uncommitted source: `flutter test test/b2b_journey_test.dart --name 'MOB-C03 checkout verified money' -r expanded` **18/18 PASS**, including red→green missing-quote AR/EN negative and legitimate quote controls; `--name 'B2B checkout'` **4/4 PASS**, confirming real positive order success/tracking and denial on malformed order reference or below-minimum. Production APIs unchanged, no backend/database/Driver/Van modifications. The full #2732 financial write-path integration and signed release/UAT requirements remain open; this is an incremental checkout guard, not full source acceptance. Latest exact-head GitHub CI must re-run after a new commit before any green claim.
