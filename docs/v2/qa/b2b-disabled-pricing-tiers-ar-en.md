# V2 #3861 — B2B disabled pricing tiers: Arabic/English HTTP409

## Exact operational source and placement
Parent #2503. Existing authenticated admin Wholesale pricing rules `GET /api/v1/admin/b2b/prices` and `PUT /api/v1/admin/b2b/prices`; no new endpoints, view, Dashboard menu, tabs, route, payloads or permissions.
The original guards still enforce Gate b2b.pricing.view/manage, operational tenant/store scope and authoritative `B2bPricingMode::tiersEnabled(store)` before pricing rules are listed or created. When tiers are OFF both return the same HTTP409 JSON `message`, now selected from paired `b2b_pricing_errors.tiers_disabled`. Original English byte-for-byte maintained; logged-in admin saved supported ar/en overrides contrary supported Accept-Language, otherwise regional ar-KW/en-US negotiation and safe Arabic fallback.

## Negative / positive security tests
- Existing `B2bPricingTest` seeds real Wholesale store, catalog product, tier, B2B_ADMIN and legacy price rule while pricing tiers default OFF.
- Existing successful active B2B account base-price feed and existing unauthorized actor/regression remain unchanged.
- Both GET (read) and PUT (would-be write) return 409 exact AR/EN message for saved `ar` with contrary English, saved `en` with contrary Arabic, unsupported saved `fr` with regional ar-KW and en-US. Eight negative HTTP calls in total.
- Snapshots prove no new/changed price-rule, settings, audits, orders, payments, stock movement counts or reserved inventory. Original persisted legacy price is untouched. Restore the actual persisted admin locale, not a potentially unhydrated model default, to avoid violating NOT NULL user locale.
- Authorization and tenant/store guards are executed before the disabled-tier message; the localization adds no bypass or leak. Does not alter financial formulas, tiers-enabled behavior, quote, customer eligibility, order/van delivery or data schema.

## Impact matrix
| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend / admin pricing API | Existing HTTP409 message only | Same permission, tenant/store, status and endpoint |
| Dashboard | No UI/navigation code | Admin pricing error body can now be localized by existing client |
| Customer | Not affected | B2B/retail product pricing quote/Customer app API unchanged |
| Driver | Not affected | B2C DRIVER ONLY unchanged |
| Van | Not affected | B2B VAN ONLY unchanged |

## HIGH technical review and rollback
HIGH due to B2B price financial configuration and tenant boundary adjacency, although change only replaces fixed human messages. File-by-file fence:
1. Existing `backend/app/Http/Controllers/Api/V1/B2bPricingController.php`: 2 guards' message arguments + one private localized message helper; no mutation and tenant changes.
2. New `backend/lang/ar/b2b_pricing_errors.php`: one Arabic key.
3. New `backend/lang/en/b2b_pricing_errors.php`: paired original English.
4. Existing `backend/tests/Feature/B2bPricingTest.php`: seeded 8-case negative HTTP/read-write no-write matrix inside existing positive baseline; persisted locale restored.
5. This QA doc: source trace, threat, mobile impact, rollback.

Rollback safely removes message helper/translation and corresponding test/doc; no DB migration, production state changes or data restoration. Same-account COMMENTED file-by-file technical audit is not independent GitHub APPROVED. Exact-final-head Repository Policy, V2 Isolation, AR/EN, Laravel tests, PHP/Pint/PHPStan/security/OpenAPI, real MySQL/Redis fresh install, supported upgrade and recovery, terminal Required CI must all SUCCESS before guarded squash merge ONLY Foodex_V2. Close leaf CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED with tested SHA and integration SHA; master #2503 stays OPEN for full staging/device/manual final acceptance. No main/release/tag/ZIP/APK or production.
