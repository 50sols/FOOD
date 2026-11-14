# V2 #3618 — Platform Wholesale guest product literal search

Parent #2503, related Customer MOB-C03 #2732. **Foodex_V2 integration ONLY**; never main, production, release, tags, APK publishing or cPanel.

## Verified source discrepancy

The existing public `GET /api/v1/platform/storefront?q=...` uses `PlatformMarketplaceController::products` to search active platform-principal B2B products by name, SKU and barcode. Its original `LIKE '%q%'` uses bound Eloquent parameters, but does not escape input wildcards `%` and `_`, returning unrelated products inside the **already authorized B2B platform scope**. This was a result-accuracy defect, **not** a demonstrated SQL injection or cross-tenant bypass.

## Smallest compatible implementation

In the existing `products()` read filter only, encode `!` -> `!!`, `%` -> `!%` and `_` -> `!_`, then add intentional surrounding percent wildcards. The three **fixed server-chosen columns** use parameter-bound `LIKE ? ESCAPE '!'`. This works on SQLite test and MySQL/MariaDB deployments, including Arabic/English Unicode text. No route, schema, query-selected column, DTO, auth, permission, field, price, pagination limit, screen or mutation changes.

Preserved: `principalStore()` configured active B2B platform principal, `productQuery()` catalog/store ownership, active flags, quarantine exclusion, price visibility, category filter, name sorting and 100-result cap. All B2C Retail records remain excluded.

## HTTP acceptance on real Laravel fixtures

Existing `PlatformCustomerMarketplaceTest` reuses `marketplaceFixture()` and seeds five additional synthetic visible Wholesale products plus same-store wildcard lookalikes and a separate Wholesale category.

- Guest HTTP `/api/v1/platform/storefront` confirms literal `100%`, `SKU_`, `TAG_%` and `!` exact substring matches across name, SKU and barcode; negative wildcard lookalikes excluded
- Ordinary Arabic `قهوة`, English `Price` and original `Wholesale Product` continue matching; intentionally unknown text gives `products.data=[]`
- A B2C Retail product cannot appear in the Wholesale principal response; category filter maintains its B2B product membership
- Each read leaves product, store-product and audit row counts unchanged; existing response store remains `b2b`
- 101 products with a literal `Bulk!_` prefix still return exactly 100 records
- Backend full PHPUnit/HTTP tests, PHP/Pint/PHPStan/security/OpenAPI, AR/EN and MySQL/Redis deployment acceptance required by exact-final-head CI

## Five-surface impact and discoverability

| Surface | Scope |
| --- | --- |
| Backend/API | Affected: existing public B2B platform storefront `q` search semantics only |
| Dashboard | Not affected: no Admin route/view/navigation/read/write change |
| Customer | Affected behavior: existing Wholesale search results become literal. Current app calls the **same JSON endpoint** with unchanged params/DTO; no Flutter code change needed. Link existing V2 Customer work #2732 |
| Driver | Not affected: no B2C delivery, wallet, dispatch or app API changes |
| Van | Not affected: no B2B routing, vans, inventory, dispatch or app API changes |

Product placement: Wholesale Storefront → Customer platform homepage existing search, guest/B2B Customer actor, search term is legitimate free input, existing product-list data surface is reused. The current Customer layout, no-result/loaded states, AR/EN translations and RTL/LTR do not change. **B2B = VAN ONLY / B2C = DRIVER ONLY** remains unchanged.

## Completion gate

One original issue-scoped branch and PR to `Foodex_V2`. Record SHA-pinned Repository Policy, V2 Isolation, terminal Required CI, complete source/test review, integration SHA and issue closing comment. Close only the coding leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` after verified source acceptance and merged green CI; parent #2503 and Customer #2732 remain OPEN for remaining source/global UAT and separate mobile acceptance.