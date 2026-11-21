# FOODEX V2 — #3639 New Order Wizard literal lookups

## Source and route

P082 search/view permission source, parent #2818, master #2503. Existing canonical Operations → Orders → New Order Wizard / Edit Order selection route `GET /admin/operations/orders/lookups/{type}`, types `customer` and `product` (unchanged `address`), implemented in `OrderOperationsController::newOrderLookup` / private customer/product helpers. Reuse current popup and responsive AR/EN RTL/LTR controls; no new route, menu, translation key or UI.

Prior merged #3599/#3601 corrected the **order-number filter** only, not these B2B/B2C lookup endpoints.

## Defect and correction

Previous bound `LIKE` search used backslash escaping for `%` and `_` without declaring a SQL `ESCAPE` clause. SQLite treats those backslashes as literal input, not escaping the wildcard; MySQL/MariaDB SQL modes vary. This is a false-positive search accuracy defect, not a demonstrated injection or privilege escalation.

Only two needle patterns in `newOrderCustomerLookup` and `newOrderProductLookup` were replaced with `'%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query).'%'`. Their 11 fixed-column predicates now use bound `LIKE ? ESCAPE '!'` for B2B names/phone/email/company/tax, B2C name/phone/email, and product name/SKU/barcode. Backslash remains literal, irrespective of database mode. The `orders.view/create/manage` and `OperationalTenantScope` authorization, store/channel filters, active B2B account and tier eligibility, B2C store ownership, active/quarantine product controls, positive-stock join, price, selected_id, ordering, 20-item pagination and existing JSON shape remain unchanged.

## Test evidence and no-write expectations

`backend/tests/Feature/DashboardOrderManagementTest.php` adds authenticated, seeded `RefreshDatabase` HTTP cases to exercise:

- B2C store-owned customer lookup with Arabic, `%_!`, literal backslash, quote-injection-lookalike negative query, same-store wildcard false positive, cross-store foreign customer deny and customer-store tampering 404
- B2C product lookup by SKU and barcode containing `%_!`, regular SKU, literal backslash and missing input, with lookalikes, foreign-store product exclusion, B2B route/channel denial
- B2B approved priced accounts with literal names/company names, normal substrings, product SKU with `%_!`, unrelated lookalike and stock; actual B2B Admin permission route
- For B2C reads, unchanged orders/audits/inventory count and reserved stock; for B2B, unchanged order count

Required exact final-head CI to be **terminal SUCCESS**: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI including PHP syntax/Pint/static/security/OpenAPI, full Laravel, AR/EN and real MySQL/Redis install/upgrade/recovery. Do not treat skipped or pending as passing.

## Cross-surface classification

| Surface | Impact |
| --- | --- |
| Backend / Dashboard | Existing authenticated B2B/B2C Orders New Wizard and Edit Order lookup search results only; 20/page and payload unchanged |
| Customer Flutter | No route, DTO, auth, order/price calculation, feature or mobile code change |
| Driver Flutter | None; no B2C dispatch/assignment/status modification |
| Van Flutter | None; B2B VAN ONLY fulfillment, custody and payment unaffected |

No financial data writes or changes to maker/checker and no hidden permissions. Customer/Driver/Van code is not changed, so path-based Flutter CI may skip and cannot be claimed as tested. Security/tenant-sensitive customer metadata warrants qualified independent review.

## Release isolation

One issue-scoped `fix/3639-v2-orders-wizard-literal-lookups` branch and one PR targeting exclusively `Foodex_V2`. Zero overlap with 30 V2 open PR file ownership at preflight; all 3 paths owned by this issue. Review, terminal final-head checks, exact base/head and mergeability are required before any guarded squash/merge; close leaf only after verified integration with exact source + integration SHA and UAT deferred to #2503. No main, production, release, installer tag, cPanel or APK publication.
