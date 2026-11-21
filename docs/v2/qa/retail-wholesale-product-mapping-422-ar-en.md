# V2 #3885 — Retail↔Wholesale product mapping HTTP422 AR/EN, no writes

**Integration:** `Foodex_V2` ONLY. Parent #2503 remains OPEN for program-wide staging/user acceptance.  
**REVIEW_RISK: HIGH** — user/tenant-scope product mapping affects downstream stock provenance; this patch *only* localizes two existing rejection texts and adds real HTTP guard tests.

## Authorized route and tenant contract

`PUT /api/v1/b2b/product-mappings/{sourceProduct}` requires an authenticated actor, a real active B2C Retail store and permission `inventory.replenishment_mapping.manage` for that store. The source must already belong to an active B2B Wholesale store/catalog; the destination product must belong to the requested exact Retail store's B2C catalog. Numeric conversion factor >0 remains validated and rounded to three decimals. A rejected command must never upsert a mapping or generate financial, stock or audit writes. A valid authorized command must remain repeatable/idempotent by source/store key.

## File-by-file HIGH technical review

| File | Scoped change | Security / rollback |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/RetailWholesaleProductMappingController.php` | Existing source/target `ValidationException::withMessages` errors now resolve paired language keys based on authenticated actor saved supported AR/EN, otherwise supported `Accept-Language`. | The same source/target `exists()` queries, auth and permission boundary, response `errors` fields, HTTP422 status, mapping upsert and quantity conversion are untouched. No untrusted locale used in authorization. Revert only these message lookups/helper for rollback. |
| `backend/lang/ar/retail_wholesale_mapping_errors.php` | Genuine Arabic translation of both errors. | Exact key parity, no English fallback. |
| `backend/lang/en/retail_wholesale_mapping_errors.php` | Same keys with **byte-for-byte prior English errors**. | Existing EN clients' human errors unchanged. |
| `backend/tests/Feature/V2RetailWholesaleProductMappingLocaleTest.php` | Seeded authorized SUPER_ADMIN real HTTP fixture: B2B product, exact B2C store product and foreign B2C product. AR/EN vs contradicting headers; unsafe source and target 422 exact `errors.*.0`, no mapping/financial/stock writes; anonymous 401, FINANCE 403 and valid idempotent successful upsert. | No mocked tenant authority; tests fail if foreign-store mapping begins writing. Existing RetailWholesaleReplenishmentTest remains unchanged for fulfillment lineage. |
| `docs/v2/qa/retail-wholesale-product-mapping-422-ar-en.md` | Risk/failure/rollback, route and per-app matrix, explicit proof checklist. | No production/release permission. |

## Negative and compatibility obligations

- Invalid source: retail product used as `sourceProduct` must remain HTTP422 with `errors.source_product.0` and zero state changes.
- Foreign target: genuine Wholesale source with another Retail store's `retail_product_id` must remain HTTP422 with `errors.retail_product_id.0` and zero state changes.
- `ar` saved user with contradictory EN header must get Arabic; `en` with contradictory AR header must get English; unsupported saved user locale follows accepted language preference.
- Anonymous requests must be HTTP401; an authenticated user lacking mapping permission must be HTTP403.
- Valid source/target with permission must still upsert the exact record once, preserving positive conversion factor and mapping identity across retries.
- No DDL/migrations or existing records modified by this change; recover by reverting the affected code and paired catalog additions through a reviewed V2 PR if required.

## Affected surfaces

- **Backend/Admin API:** The **human** text in two existing `422` validation errors is bilingual; numeric status, keys and all permission/tenant/product checks unchanged.
- **Dashboard:** Authorized Retail/Wholesale management may show the localized API error. No added screen/menu, routes, view or core admin permission. No direct product UI change.
- **Customer:** NOT AFFECTED. No customer-visible public API, checkout, Flutter DTO, pricing or cart contract changed; the route is an authenticated mapping management operation, not consumed by Customer.
- **Driver:** NOT AFFECTED. No Driver API, app, status, wallet or B2C dispatch changes.
- **Van:** NOT AFFECTED. No Van API, assignment, route or B2B dispatch changes.
- **Safety:** B2B = VAN ONLY; B2C = DRIVER ONLY remain unchanged.

## Exact-HEAD acceptance register (to be completed with actual GitHub results)

- [ ] Seeded new mapping API HTTP negative/positive tests PASS.
- [ ] Backend full Laravel, PHP syntax/Pint/PHPStan/Composer audit/OpenAPI, security and AR/EN PASS.
- [ ] MySQL/Redis real fresh install, upgrade and recovery acceptance PASS where required.
- [ ] Repository Policy SUCCESS on exact final HEAD.
- [ ] FOODEX V2 Isolation Gate SUCCESS on exact final HEAD.
- [ ] FOODEX Required CI Gate and terminal `required-ci-gate` SUCCESS on exact final HEAD.
- [ ] `TECHNICAL_REVIEW: PASS` / `REVIEW_RISK: HIGH` file-by-file GitHub engineering audit (a same-account comment is **not independent approval**).
- [ ] One original Issue, issue-scoped PR, SHA-guarded merge into **Foodex_V2 only**; independently verify source merged, then close leaf `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` while keeping #2503 open.

**Do not** merge into `main`, publish a release/tag, generate or deploy APK/ZIP or change production/cPanel without separate owner instruction.
