# V2 #3829 — Driver collection/remittance invalid Idempotency-Key AR/EN

- Branch and integration: `Foodex_V2` ONLY. Parent #2736/#2503 remains open for larger Driver/UAT acceptance; no main/release/deployment
- REVIEW_RISK: HIGH — B2C authorized Driver financial collections and remittances
- Exact HTTP routes verified against `backend/routes/api.php`: `POST /api/v1/driver/assignments/{assignment}/collections` and `POST /api/v1/driver/wallet/remittances`
- Existing `DriverCollectionController::idempotencyKey` remains the same trim/8–72/allowed ASCII regex, HTTP422, and JSON `errors.idempotency_key.0`; only the human message is localized by saved authenticated actor `ar`/`en` with supported Accept-Language fallback
- Exact prior English literal retained; new `backend/lang/{ar,en}/driver_collection_errors.php` translation key parity. No translation or locale input used as an authorization, tenant, or monetary decision
- Actual seeded Driver lifecycle test already creates authoritative B2C scoped driver, order, assignment and transitions to `out_for_delivery`; adds Driver collection and remittance invalid header matrix (AR/EN × short/illegal-character/73 length) with opposed Accept-Language and exact field rejection. After persisted locale setup, snapshots unchanged available account/collection/remittance/ledger/payment/order/assignment/invoice/stock/audit tables
- Unsupported persisted locale `zz` uses English Accept-Language negotiation; no forbidden `users.locale = NULL` fixture
- Existing positive Driver assignment, delivery proof, notifications, history, cross-store and status lifecycle tests retained
- Security: invalid key rejects after existing Retail Driver runtime and assignment gate, but before settlement, account lookup/create, transaction, remittance or stock writes; B2C DRIVER ONLY and B2B VAN ONLY remain unchanged
- Mobile impact: **Driver** affected only by existing HTTP422 error's human copy; Driver Flutter DTO, routes and behavior unchanged. **Customer** unaffected; **Van** unaffected (separate #3817), **Dashboard** unaffected; no new app issue needed because affected Driver negative API contract is directly tested in this leaf
- Rollback: revert local message expression and paired new translations/test/doc; no migration, data or release artifacts
- Required before merge: separate HIGH-risk same-account COMMENTED engineering audit (not independent APPROVED) and verified exact-final-head Repository Policy/V2 Isolation/Required CI with PHP/Pint/PHPStan/security/Laravel/AR-EN/MySQL/Redis/recovery success. No green or production claims from this document alone.
