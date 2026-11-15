# FOODEX V2 — #2739 MOB-V03 Van order response-scope safety

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx T115R03/T115R04; parent #2503. **Integration:** `Foodex_V2` only, with no `main`, production deployment, release, tag or APK publication.

## Ownership, authority and route

- **Actor:** authenticated actively assigned Van operator; B2B wholesale order history, order detail and B2B direct-sale creation.
- **Runtime entry:** existing Van Orders workspace; existing `HttpVanOrderRepository.orders`, `order`, `createOrder`. No new screen, route or navigation destination.
- **Server authority:** existing authenticated `/api/v1/van/orders`, `/api/v1/van/orders/{id}` and `/api/v1/van/customers/{type}/{id}/orders` remain responsible for real role, assignment, tenant, channel, idempotency, finance and object authorization. The app-side check is a **second fail-closed response boundary**, not a replacement for server checks.
- **Pre-network Van customer scope:** the existing B2B-only rejection now also guards catalog, order-options and order-quote endpoints before dispatch. A Retail customer cannot probe any of these Van workflow routes; positive B2B catalog/options/quote remains available. The server still enforces actual actor assignment and scope.
- **Change:** reject a Van order response whose `customer_type` is not B2B. Reject all records (do not partially display) if a filtered history request returns a different customer/store. Reject forged create responses for another customer/store and reject B2C create/history requests before HTTP. For order detail and execution read/transition/proof/failure/retry, the returned B2B `id`/`order_id` must match the requested URL order ID: a mismatched response is never accepted as the selected order's details or action result. Never silently coerce invalid scope to a B2B record.
- **Preservation:** genuine B2B list, detail and create continue to work; create retains its original Idempotency-Key. No mutation, migrations, financial ledger or upload changes.
- **Tests:** `apps/van_app/test/van_order_b2b_response_scope_test.dart` includes pre-HTTP Retail rejection across catalog/options/quote (zero HTTP requests), matching Wholesale success plus hermetic MockClient with valid B2B, mixed B2C, foreign B2B customer/store, forged create response, Retail detail, prohibited Retail input, incorrect detail response `id`, and mismatched execution `order_id` across read/transition/retry. The same check is applied by the production helper to proof/failure/retry mutation responses. The server must still enforce authoritative Van assignment and object scope.

## Mobile impact matrix

| Surface | Status | Evidence |
| --- | --- | --- |
| Backend/API | No source change | The authoritative Van HTTP authorization rules and endpoints are unchanged by this slice. |
| Dashboard | No source change | No admin pages, links, permissions or reporting change. |
| Customer | No change | Customer app endpoints, checkout and identity untouched. |
| Driver | No change | B2C DRIVER ONLY, no driver API or Flutter changes. |
| Van | **Affected** | Existing Van order parser and HTTP response handling reject foreign B2C and customer/store scope; AR/EN labels and layout unchanged, existing error handling retained. B2B VAN ONLY. |

## Source-completeness limitation

This **bounded security increment does not close #2739 / MOB-V03**. Full Van physical stock/consignment, trip settlement, return/custody, and offline replay parity depend on authorized trip/stock/finance provenance and separate related workers (#2604, #2602, #2607, #2588 and #2605), none of whose files are touched. Keep this single issue-scoped PR **DRAFT and unmerged** until the remaining source acceptance, end-to-end app behavior, all exact-head Required CI checks, and safe file ownership coordination are evidenced. Do not represent this as a complete Van settlement or offline capability.

Manual browser/device/staging UAT is deferred to final V2 gate #2750 only; automated source, security, Flutter and CI requirements are **not** deferred.

## 2026-11-15 — Van financial response truthfulness, original MOB-V03 branch

- **Observed source defect:** `HttpVanOrderRepository::_double` previously converted absent, nonnumeric, non-finite and otherwise malformed quote, order, catalog price, invoice, payment and collection monetary values to a fabricated numeric zero. A trusted-looking `0` sale/collection is materially different from unusable server data.
- **Scoped correction:** new `_requiredMoney` validates finite, nonnegative numeric values (including valid numeric strings), rejects null, nonnumeric, NaN, Infinity or negative amounts with `VanApiException`, and replaces the unsafe zero-fallback only for monetary fields. Genuine zero-valued discounts, delivery/tax, quote and legitimate zero totals are preserved. Numeric quantity helpers and their domain defaults are deliberately unchanged. Server still controls prices, credit, discount, stock and payment authorization; this is client display/response fail-closed protection only.
- **Automated tests:** hermetic MockClient negative matrix on the existing B2B Van quote, scoped history and create endpoints (five invalid amount forms, including null and non-finite strings), plus invalid catalog unit price and invoice paid amount. Retains existing positive B2B zero quote and scope/identity/idempotency tests. No actual server writes or user credentials.
- **Impact:** Van Flutter repository/test only. No Backend/API/Dashboard, Customer or Driver source; B2B=VAN_ONLY and B2C=DRIVER_ONLY are unchanged. This is **not** canonical Van physical stock/consignment/load-return/trip/remittance/offline acceptance; #2739 and PR #3351 must remain OPEN/DRAFT until that broader source is genuinely implemented and exact-head CI certified.

## 2026-11-15 — Van catalog/order quantity response integrity

- Existing-order response parsing previously converted malformed or non-finite order-line quantities to **zero**, coerced invalid catalog minimum/increment quantities to **1**, and interpreted malformed `available_quantity` as **null/unspecified**. These defaults can misrepresent real inventory or order evidence without any server acceptance.
- Within the original #2739 Van order repository only, explicit quantity responses now require finite nonnegative numbers; minimum and ordering increment, when supplied, must be strictly positive. Absent optional catalog minimum/increment still default to 1, and absent optional stock remains null. Legitimate fractional increments and zero actual available stock remain accepted; no backend business validation was changed.
- MockClient Flutter regressions reject malformed, negative, NaN, Infinity and nonnumeric stock/step values and order-line quantities. A positive default/optional-stock case preserves historical compatibility. Money parsing, B2B customer/store authority, Idempotency-Key and all existing endpoint paths remain unchanged.
- **Source boundary:** this strengthens client-side truthfulness only; it does not implement immutable physical Van stock custody, loading/returns, trip/finance settlement or offline replay. #2739 and original #3351 remain OPEN/DRAFT until all MOB-V03 obligations and exact-head CI are proven. No other app, Laravel, Dashboard, migration or production change.

## Draft replay after review navigation and store switches

The order review uses the draft's random submission key throughout the same in-memory revision, including after a review-page remount following an ambiguous network response. A change of customer store clears lines, selections, quote and operation key even if customer type and ID match. An options response arriving after a revision change is discarded. Process termination and physical consignment settlement require separate source acceptance.

GCC verified the focused draft and HTTP suites (22 tests), Flutter analysis and Dart formatting for the three changed Dart files.

## V2 MOB-V03 — reject invalid Van mutation idempotency headers before HTTP (2026-11-15)

**Existing risk:** the Van order client was forwarding any caller-provided `Idempotency-Key` for B2B create, execution transition, proof upload, failed-delivery and retry. Empty/whitespace/overlong or HTTP control-containing values could allow unsafe/non-idempotent mutation retries or header injection in transport. The draft-generated key uses a secure random `van-order-...` token, but the repository API accepts explicit keys from callers and must validate at its own HTTP boundary.

**Guard:** a shared `_assertMutationIdempotencyKey` check rejects empty, length greater than 120, any space, nonprintable ASCII, CR/LF/TAB or non-ASCII header bytes **before networking**. It does not mutate or truncate caller keys, generate replacement keys, change server idempotency storage, disclose user data or add untrusted client-side stock/finance authority. Legitimate printable B2B headers and existing HTTP request paths remain unchanged. The existing server is still authoritative for exactly-once effects, tenant/store scope and replay.

**GCC proof:** isolated original branch `ab637207690fac9d6c28e5dc330e045a2f32620b`, Flutter stable on GCC. The hermetic MockClient negative matrix exercises seven invalid keys across **five HTTP mutations** with a zero-request assertion, while existing legitimate B2B create/list/detail cases still pass. Focused Van HTTP suite **16/16 PASS**; full Van Flutter test suite **167/167 PASS** including Arabic/English screen contracts; `flutter analyze --no-pub` on changed Dart paths **No issues found**; `dart format` and `git diff --check` PASS. Screenshot and local Flutter/Android cache files generated in GCC were not included in the commit.

**Impacts:** Van mobile HTTP repository and tests only; no Laravel API, Dashboard, Customer or Driver change. This is an incremental anti-duplicate-request safety bound, **not durable process-death offline replay** or a verified stock/settlement transfer.

**Still NOT accepted:** real #2844 legal stock title, #2606 trip identity, physical Van warehouse load/return (#2588/#2604), single B2B order inventory consumption, #2602/#2607 settlement, offline persistence/recovery and integrated authorization+AR/EN tests. Preserve #2739 OPEN, original PR DRAFT until source-complete and exact-head CI; only Foodex_V2, no main/release/tag/production.
