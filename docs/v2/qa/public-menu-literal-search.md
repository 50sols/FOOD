# V2 #3637 — Public Smart Ordering menu literal search

## Authority and boundaries

Source P082 search/read action contract, parent #2818 and V2 master #2503. The existing public Restaurant SaaS `GET /api/v1/smart-ordering/{slug}/items` accepts optional `q`, locale, branch, category, featured and pagination. It resolves the site by runtime policy, verifies public_menu readiness and branch, and reads the **published revision**. No new route, request field, response JSON shape, entitlement, authentication behavior or UI is introduced.

## Exact source change

`SmartOrderingMenuReadService::searchItems` now constructs one parameter-bound LIKE pattern from trimmed user input, escaping `!` to `!!`, `%` to `!%`, `_` to `!_`, with fixed SQL `LIKE ? ESCAPE '!'` for `name_ar`, `name_en`, `description_ar`, `description_en`. Literal backslash remains a backslash on SQLite and MySQL/MariaDB regardless of their default backslash LIKE semantics. This corrects wildcard expansion, not SQL injection; the original query already used bound values.

The site ID, published revision ID, branch-applicable menu/category scope, featured/category selectors, sort order, 50-per-page cap, price/availability and response metadata code are unchanged. No data mutations were made.

## Automated regression

`backend/tests/Feature/SmartOrderingPublicMenuRevisionContractTest.php` now exercises the public **HTTP route** against seeded `RefreshDatabase` restaurant sites, branches, products, a published 7-item menu and a separately published foreign site. It tests literal `%_!` symbols in bilingual names and descriptions, SKU-like names, literal backslash, ordinary Arabic and English substrings, a same-site wildcard lookalike, foreign published item exclusion, no-match behavior, correct `meta.total` and `meta.revision.id`, and unchanged item/order/revision counts from reads. Existing revision-stability, draft isolation, pagination (max 50), query growth and runtime/branch readiness suites remain mandatory.

For merge record actual latest-exact-head Repository Policy, V2 Isolation, Required CI PHP/Pint/PHPStan/security/OpenAPI, AR/EN, real Laravel and MySQL/Redis deployment acceptance, nested required-ci-gate. Pending or cancelled jobs are **not** green.

## Surface impact

| Surface | Impact |
| --- | --- |
| Backend / Public menu API | Read-only literal q search semantics for four existing menu item fields; response contract unchanged |
| Dashboard | None; Menu Builder editor and picker covered by distinct merged #3623, not modified |
| Customer Flutter | No client, DTO, route, authentication, pricing or checkout code change; public restaurant menu search yields correct results to any caller |
| Driver Flutter | None; no delivery, order-state or B2C dispatch contract delta |
| Van Flutter | None; no B2B fulfillment, consignment, route or inventory contract delta |

B2B VAN ONLY/B2C DRIVER ONLY and authoritative store/branch/published revision boundaries preserved. AR/EN text and RTL/LTR screens unchanged. **Placement:** REUSE existing public restaurant menu's search field and route, no new screen, tab, Sidebar or navigation.

## Delivery gate

Original issue/branch `#3637` / `fix/3637-v2-public-menu-literal-search` → one PR targeting **`Foodex_V2` only**. Apply independent qualified review where required, exact final-head green CI, no base drift/merge conflicts, and authenticated issue-scoped SHA-guarded merge; close only verified code-complete leaf with source SHA, integration SHA, CI URLs and `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` (#2503). No main, tag, production, release, APK or cPanel.
