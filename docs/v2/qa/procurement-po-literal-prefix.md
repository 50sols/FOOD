# FOODEX V2 #3655 — Portable literal Purchase Order prefix search

**Source:** Procurement Purchase Orders, P082 authorized View, master #2503. **Target:** Foodex_V2 only; never main, release, APK, tags, cPanel or production.

## Defect and narrow resolution

PurchaseOrderManager::paginate previously stripped literal % and _ from validated q before applying a SQL LIKE prefix. This altered valid PO-number queries, returned unrelated rows on wildcard-only input and had no explicit portable treatment for ! or backslash. This is search correctness, not a demonstrated SQL injection or tenant bypass.

The existing authorized read query now uses the fixed column and bound order_number LIKE ? ESCAPE '!'. User input is trimmed but never stripped; !/%/_ escaped literally, backslash retained; one intentional trailing % preserves prefix semantics. Input remains string/max 120, existing supplier eager load, status, page-size clamp (10–100), id-desc order, withQueryString and visibleQuery organization/company/store boundary remain unchanged. No supplier editing, approval, purchasing writes, notifications, schema or ledger changes.

## Placement and surface impact

- Dashboard: reuse canonical Procurement Workbench Purchase Orders tab, with its existing route, role, status/search, AR/EN, RTL/LTR and responsive layout. No new UI, navigation or translation key.
- Backend: PurchaseOrderManager read-list q filter affected; existing Procurement permission and store scope enforced server-side.
- Customer: no public API, DTO or Flutter change.
- Driver: no public API, role, routing or Flutter change.
- Van: no public API, stock/route/custody or Flutter change. B2B VAN ONLY, B2C DRIVER ONLY unaffected.

## Regression and audit

RefreshDatabase tests run real PO creation/approval fixtures for two different store scopes, then isolate distinct globally unique PO-number symbols (%, _, !, backslash), ordinary prefix false positives, foreign-store exclusion, status filter and a user lacking procurement.purchase_orders.view (403), while verifying no order or audit mutation from search.

REVIEW_RISK: HIGH (business purchasing data and store privacy). Review all three issue-owned files for bound SQL parameters, no grouped OR scope bypass, unchanged authorizedScope and visibleQuery, read-only guarantees and failure handling. Before merging original issue PR to Foodex_V2, verify exact-head Repository Policy, V2 Isolation, full applicable Laravel/Pint/PHPStan/security/OpenAPI, localization AR/EN, MySQL/Redis and terminal Required CI SUCCESS. Only then close atomic leaf CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED; final manual/staging acceptance remains on #2503.
