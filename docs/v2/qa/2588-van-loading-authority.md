# V2 P258 — Van loading boundary / non-acceptance evidence

**Issue:** #2588 (P258, Procurement & Inventory) · Parent #2503 · Target: `Foodex_V2` **only**.

## Verified source-versus-runtime boundary

- Canonical WMS entry point: `admin.erp.wms.index?tab=transfers`, backed by `EnterpriseWmsController`, `WarehouseTransferService` and `WarehouseScope`. Its existing transfer lifecycle is **warehouse → warehouse** (draft, dispatched, received), not **warehouse → van**.
- `EnterpriseWarehouseTransfer` stores `source_warehouse_id` / `target_warehouse_id`; its ledger records `warehouse_transfer_out` / `warehouse_transfer_in` on `inventories`. There is no van-target identity in the transfer's audited fingerprint, warehouse scope, or destination stock record.
- `van_inventory_history_documents` / `van_inventory_history_lines` hold **legacy imported records** (migration `2026_10_20_123000_create_van_inventory_history.php`). They do not establish a live, writable, reconciled van stock ledger or authorized dispatch/return workflow.
- Therefore a WMS transfer must not silently accept a supplied `van_id` as though it assigned physical stock to a B2B van.

## This PR's bounded, safe improvement

- Fail closed on `van_id` in all three warehouse-transfer HTTP mutations (create, dispatch, receive), using Laravel's `missing` validator.
- Add `V2VanLoadingAuthorizationContractTest`: an authenticated ERP actor supplying forged van-target metadata must receive a validation error; no WMS transfer, transfer lines, warehouse movement, or corresponding audit event may be created.
- Preserve existing WMS warehouse-to-warehouse transfer semantics and P251 authority. Do **not** add a misleading live `تحميل للفان` link or count a warehouse-to-warehouse movement as a van load.
- No new localized UI wording; framework validation messages already use the selected locale. No new route/permission/API schema.

## Critical double-debit stock-authority gate (verified 2026-11-15)

**Source-level blocker, not a UI acceptance deferral:** `CommercialVanSalesBridge::contract()` currently publishes `stock_source => inventories` and `inventory_authority => OrderInventoryReservationService::class`. `VanOrderController::store` goes through `CommercialVanSalesBridge::create` → `AdminOrderManagementService::create`; `ProductAvailabilityService::forStoreProduct` reads the wholesale principal's **warehouse inventories**. In contrast, `WarehouseTransferService::dispatch` immediately subtracts source `inventories.quantity` and posts `stock_movements`. Its destination is another **warehouse**, never a Van custody account. The imported `van_inventory_history_documents` do not back operational stock.

**Failure if a naive Van-load debit is shipped now:** physical loading would reduce the source warehouse's available balance, but a later Van-originated order could still reserve/debit the same warehouse inventory through the existing order service. Neither a successfully returned HTTP 200 nor a GREEN generic CI check demonstrates conservation of stock between warehouse, Van, reserved order, delivered order and return. This can cause double-debits or sale denial for stock actually aboard the Van; a shadow `van_stock_balance` without a single order-consumption authority is not acceptable.

**Mandatory integration before source completion:** (a) choose one authoritative, immutable warehouse/Van custody ledger with B2B-only store, active warehouse-bound Van assignment/trip and positive lot/valuation provenance; (b) change the Van order quote/reserve/dispatch/deliver/replay stock authority to use that same Van custody ledger exactly once (never both balances); (c) implement independent maker/checker, transactional dispatch/partial returns, sales deduction, returns, negative-stock rejection, concurrent locks and idempotency; (d) exercise creation/approval/load/Van sale/offline replay/delivery/return/reconciliation via seeded real HTTP against both ledgers under AR/EN, forged cross-store and Retail actor negatives; (e) expose a permission-projected responsive WMS/Van Operations contextual action, plus scoped Van runtime stock read/update contract and three-app impact. No posting endpoint may be enabled before this integrated contract is verified.

The current three-file interim guard is **safe but incomplete**: do not remove the original DRAFT status, merge it, or close #2588 merely for successful Policy, Isolation and Required CI.

## Unmet P258 acceptance (issue stays OPEN, PR remains DRAFT)

A future fully implemented Van Loading workflow requires an approved **canonical stock ownership model**, not an ad hoc rebranding of WMS transfers:

1. Resolve the Van loading source and destination: B2B Wholesale store, warehouse inventory/lot/reservations → a specific authorized `van_id` + route/trip/assignment + auditable van balance and reconciliation. Never B2C Driver.
2. Define exclusive maker/checker permissions and explicit inventory/van-scope entitlements, cross-store/store-role/tenant denial, independent approvals for loading/returns, and forced idempotency.
3. Ensure transactional quantity and monetary valuation movements, reversals/returns, negative-stock protection, immutable history, and safe handling of retries, offline Van replay and partial loads.
4. Place a localized, accessible loading workbench under the canonical WMS / Van operations journey (Reuse → Extend → Compose before New Screen); provide real authorized links and route checks, not a dead/sidebar-only link.
5. Build AR/EN, RTL/LTR, responsive and negative/allow/multi-role/stale-state tests; inspect actual staging workflows end-to-end and obtain exact-head CI before declaring P258 accepted.

## Navigation / app impact matrix

| Surface | Current change | Real P258 acceptance path |
| --- | --- | --- |
| Backend & Dashboard | WMS transfer HTTP metadata validation only; no new form or route | Canonical scoped Van Loading command, inventory ledger + contextual tab/action and audit |
| Customer | **Not affected**: no Customer-facing API, pricing, orders or auth contract changed | Reassess only if availability/fulfillment contracts change |
| Driver | **Not affected**: B2C Driver cannot be used for B2B Van fulfillment | No Driver fallback; prohibit cross-channel assignment |
| Van | **No runtime change yet**; Van-app consignment/reconciliation acceptance is separately tracked in #2739 and B2B route authority in #2738 | Linked actual affected Van issue(s) require authoritative load/return/offline parity and same exact-head acceptance |

**Release policy:** no main merge, release tag, APK publish, cPanel deployment or production claim. This increment is **not a completed P258 loading feature** and cannot be merged or used to close #2588 solely because generic CI passes.

## 2026-11-15 — reject alternate Van target metadata at the WMS HTTP boundary

**Bounded P258 correction, not physical Van loading completion.** Previously the create/dispatch/receive warehouse-transfer form actions rejected `van_id` alone. Other Van-specific target/source IDs and nested `metadata[van_id]` silently passed Laravel field validation and could be misrepresented as loading a physical Van, even though WMS transfers move warehouse-to-warehouse only.

The controller now applies one shared `missing` rule map to all three actions. Forbidden request keys: `van_id`, `source_van_id`, `target_van_id`, `destination_van_id`, `van_assignment_id`, `van_trip_id`, `van_load_id`, `source_type`, `target_type`, `destination_type` and unrecognized top-level `metadata` (including nested Van target IDs and explicit null). Supported WMS form fields, authorization, idempotency and stock services are unchanged. No Van stock ledger or false live loading route was introduced.

**Isolated GCC proof:** original source branch `ce8e218c772b930808314d40abaecb4bba474783`, PHP 8.3.33 / PHPUnit 11.5.57 / disposable SQLite `:memory:` / test-only APP_KEY. Authenticated HTTP negative matrix runs 15 adversarial request variants in each of AR and EN through create, dispatch and receive: **1 feature test, 274 assertions PASS**, including zero stock movements, WMS transfer headers/lines and warehouse-transfer audit records. To prevent unrelated HTTP 429 rate-limit exhaustion during 90 negative requests, only `ThrottleRequests` middleware is excluded **inside this test class**; authentication, enterprise WMS permissions and tenant/store authorization remain enforced. Both PHP files pass `php -l`; Pint PASS after local LF-only Windows normalization; targeted Controller PHPStan PASS with zero errors; `git diff --check` PASS.

**Impact and acceptance:** Backend/Dashboard existing transfer submission guard only. Customer, Driver, Van runtime/API and translations not modified. Existing Laravel localized validation responses apply to AR/EN; B2B VAN ONLY/B2C DRIVER ONLY unchanged. The original PR stays DRAFT and Issue OPEN pending #2844 verified stock title, #2606 immutable trip provenance, real transactional warehouse-to-Van load/return custody, exclusive Van order stock consumption (#2739), maker/checker, concurrency/idempotent offline replay, role/RBAC/tenant/a11y/AR-EN UAT and source-complete exact-final-head CI. No merge to main, release, tag or production.

## 2026-11-16 — reject case, separator and camelCase Van-loading aliases

**This is a bounded fail-closed security increment, not a physical loading implementation.** Laravel's `missing` validation rules match exact request keys. A caller could instead send `vanId`, `Van-ID`, `VAN_ID`, `vanTripId`, `targetVanId`, `metaData` or similar casing/separator variants: those fields were ignored as unknown while an otherwise valid ordinary warehouse transfer could proceed, misleading clients about accepted Van cargo. The three existing warehouse-transfer mutations now normalize inbound top-level key spellings (case and non-alphanumeric separators only) before applying the **same** localized `missing` validation. No other transfer logic, permissions, stock postings, reservations, money, migrations, Dashboard route or Van API changed.

**Verified on isolated GCC PHP 8.3.33/PHPUnit 11.5.57:** one authenticated AR/EN feature test, **544 assertions PASS** across 30 forged targets × 3 create/dispatch/receive requests × 2 locales, asserting rejection and zero transfer, transfer-line, movement and audit writes. Test-only SQLite `:memory:`, never the production database. Remaining scope still requires #2844 *verified* immutable owner/title proof, #2606 authoritative integrated trip identity, one accepted load/return/consumption authority with #2604 and #2739, independent maker/checker, partial damage/shortage/returns, MySQL concurrency, actual Van Flutter offline replay, full RTL/a11y and final exact-HEAD CI. **Do not merge/close #2588 just because this regression passes.** Foodex_V2 only; no main/release/deployment.

## 2026-11-16 — Strict warehouse-only field allowlist hardening

The three existing transfer POST handlers now reject every unexpected top-level
request field (including arbitrary nested wrappers), instead of only recognizing
a finite list of normalized Van aliases. The allowed set exactly matches the
existing WMS transfer forms (create: store/source warehouse/target warehouse/
product/quantity/request key; dispatch/receive: store), with framework _token
and _method allowed. Unknown fields generate Laravel's localized validation
error **before any stock or audit side effect**. Original base transfer service,
permissions, channel, pricing and movement authority remain unchanged.

The authenticated P258 AR/EN create/dispatch/receive negative matrix was
extended with nested/unknown wrapper payloads; terminal exact-head GitHub CI
is required for the new source commit. This safety patch **does not implement**
physical Van loading, approval, owner/title, accepted custody, returns, Van
offline replay or single order-stock debit and cannot close P258. No new
Sidebar, route, stock table, public Van API or posting is enabled.
