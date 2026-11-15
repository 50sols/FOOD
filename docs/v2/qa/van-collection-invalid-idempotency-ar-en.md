# V2 #3817 — Van collection/remittance invalid Idempotency-Key AR/EN

- Base and integration: `Foodex_V2` only; parent #2739/#2503 stays open for broader Van and staging/device UAT
- REVIEW_RISK: HIGH — authenticated B2B Van financial-collection boundary
- Change: one existing human HTTP422 rejection message in `VanCollectionController::idempotencyKey`; retains the exact 8–72 character ASCII policy and response `errors.idempotency_key.0`
- Authenticated Van actor's saved `ar` or `en` locale takes precedence over contrary Accept-Language; absent or unsupported saved locale negotiates only `ar`/`en` with Arabic default
- English message remains byte-identical; Arabic translation is explicit and enumerates the same accepted characters
- Changed paths: controller, seeded `VanCollectionControllerTest`, paired new `backend/lang/ar|en/van_collection_errors.php`, this QA document
- Negative real authenticated HTTP matrix: `collect` and `remittances` × saved `ar`/`en` × short, illegal-space-symbol, 73-character long header; exact 422 key/message, contradictory Accept-Language, baseline snapshots of all available financial, order, stock and audit tables; separate unset-preference fallback assertion
- Positive paths: existing seeded collect → retry → remittance → retry → approval → reconciliation and existing cross-actor 404 tests preserved
- No change to token abilities, authorization, Van assignment/visit scope, B2B channel, amount/credit/stock calculation, remittance persistence, idempotency keys, schema, routes, DTO shape or frontend fields
- Mobile impact: **Van affected only in existing server-localized negative message**; no Flutter code/route/DTO changes. **Customer not affected**, Customer API unchanged; **Driver not affected**, driver API and Flutter unchanged; **Dashboard not affected**, no admin routes/screens/navigation changes. No new mobile issue because the Van-facing negative text is covered directly in this linked Van domain leaf with real API contract regressions
- Threat review: invalid or malformed headers fail before collection account creation, ledger/payment, remittance, invoice, order, stock or audit writes; no locale value used in authorization decisions, query filters, amounts or storage
- Recovery: revert one text-only controller expression and paired strings/test/doc if required; no migration/rollback or data transformation
- Acceptance requires **actual** same-commit PHP syntax/Pint/PHPStan/security, Laravel, AR/EN, role and cross-scope, MySQL/Redis/recovery and Required CI SUCCESS. Do not merge or close based on this written matrix alone.
