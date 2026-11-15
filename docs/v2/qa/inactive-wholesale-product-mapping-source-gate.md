# V2 #3891 — Inactive Wholesale source catalog must fail closed before Retail mapping upsert

**Program:** #2503. **Related completed localization work:** #3885 / PR #3886. **Target:** `Foodex_V2` only.  
**REVIEW_RISK: HIGH** — mapping source catalog lifecycle influences downstream cross-store inventory lineage.

## Source gap

In `RetailWholesaleProductMappingController::upsert`, the existing Wholesale source `products → catalogs → stores → store_types` query checked `catalogs.channel=b2b`, B2B store type, and active store. The source catalog's own `catalogs.is_active` was **not checked**. A product attached to an explicitly inactive B2B catalog could therefore pass the source check and reach `retail_wholesale_product_mappings` upsert when mapped to an otherwise valid Retail store. This contradicted the existing HTTP422 message requiring an **active wholesale catalog**.

## File-by-file source, abuse and rollback review

| File | Atomic change | Abuse and failure safety |
| --- | --- | --- |
| `backend/app/Http/Controllers/Api/V1/RetailWholesaleProductMappingController.php` | Add one SQL eligibility clause `->where('catalogs.is_active', true)` inside `$sourceIsWholesale` before `exists()`. | An inactive Wholesale catalog now fails the existing localized `source_product` HTTP422 before the target check and mapping write. The same authorized `inventory.replenishment_mapping.manage` permission and B2B store/type checks remain. Retail target must still belong to the exact B2C store. No user-supplied override or fallback. |
| `backend/tests/Feature/V2RetailWholesaleProductMappingLocaleTest.php` | Extend previously merged reusable fixture/helper with genuine deactivated source B2B catalog, existing valid source SKU and real authenticated actor. Assert AR/EN `errors.source_product.0`, HTTP422, unchanged catalog active flag, mapping/finance/stock/audit counts stable. Keep existing active catalog positive idempotent and unauthorized/cross-store negative tests. | Without the new SQL clause the inactive-catalog test would incorrectly return HTTP200 and insert a mapping. No mock authorization or test-only bypass. |
| `docs/v2/qa/inactive-wholesale-product-mapping-source-gate.md` | New focused risk/rollback, mobile impact, source and CI register. | No API schema, migration or live-deployment change. |

## Regression and compatibility

- Active B2B source catalog → original mapping source check and idempotent authorized upsert remain unchanged (existing positive test).
- Inactive B2B source catalog → exact source-error HTTP422 in AR/EN, no mapping/audit/order/stock/finance writes; no deactivation/reactivation side effects.
- B2C source or foreign Retail product → still rejected by original source/target checks and tests from #3885; source check cannot be bypassed with client target or factor.
- Existing mapping records are not modified/deleted by this read-time guard. Revocation of a source catalog prevents a **new mapping upsert**, not historical lookups or recorded receipts.
- Rollback of this *guard code* requires a separate reviewed V2 PR. No database migration to roll back; never assume a rollback must reactivate catalogs.

## Per-app / UI impact

- **Backend/Admin API:** Existing PUT mapping operation now denies disabled source B2B catalog at the server boundary; response shape `errors.source_product.0` and HTTP422 unchanged.
- **Dashboard:** Normal business mapping workflow reuses existing error rendering; no new route, field, tab, Sidebar node, form or explicit UI change.
- **Customer:** NOT AFFECTED: no Customer public API/Flutter DTO or store catalog read endpoint change; Wholesale purchase eligibility and prices untouched.
- **Driver:** NOT AFFECTED: B2C Driver routes/assignments/mobile app untouched.
- **Van:** NOT AFFECTED: Van routes/consignment/assignments/mobile app untouched.
- **Global rule:** B2B VAN ONLY, B2C DRIVER ONLY remain unchanged.

## CI evidence register — confirm on exact final source HEAD

- [ ] New inactive-source Laravel real-HTTP AR/EN, no-write tests and existing positive/negative mapping tests PASS.
- [ ] Full Laravel, PHP syntax/Pint/PHPStan/Composer audit/OpenAPI/security, AR/EN localization PASS.
- [ ] MySQL/Redis real Setup / Upgrade / Recovery job PASS.
- [ ] Repository Policy SUCCESS (exact final HEAD).
- [ ] FOODEX V2 Isolation SUCCESS (exact final HEAD).
- [ ] FOODEX Required CI terminal `required-ci-gate` SUCCESS (exact final HEAD).
- [ ] `TECHNICAL_REVIEW: PASS` / `REVIEW_RISK: HIGH` source audit recorded separately as GitHub COMMENTED review, not as an independent account APPROVED.
- [ ] Original Issue/PR merged into Foodex_V2 with expected source SHA; verify exact integration SHA and code; close only #3891 as CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED. Parent #2503 remains OPEN for final staging/business acceptance.

No `main`, release, tag, signed ZIP, APK, production deployment, or cPanel action is part of this change.
