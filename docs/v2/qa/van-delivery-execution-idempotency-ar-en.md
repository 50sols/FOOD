# V2 #3818 — Van Delivery Execution invalid Idempotency-Key AR/EN

## Source authority and unchanged contract
- Parent Van journey #2739, V2 isolation master #2503; issue-scoped branch and Foodex_V2-only PR.
- Authenticated Van execution endpoints transition/proof/fail/retry share `VanDeliveryExecutionController::idempotencyKey`.
- Validation still requires an 8–128 character ASCII key matching `[A-Za-z0-9._:-]+`; HTTP 422 and `errors.idempotency_key.0` preserved. The original English text is byte-for-byte unchanged in the English translation catalogue.
- Explicitly resolve language from authenticated operator's saved `locale` when ar/en, otherwise supported Accept-Language; a contrary header never overrides saved locale.
- No status/finance/stock/assignment/routing/idempotent replay/persistence behavior changes. Failed input exits before `VanDeliveryExecutionService` transitions and proof writes.

## File-by-file review / risk
REVIEW_RISK: HIGH (authenticated Van delivery and finance-adjacent API). This note is implementation review preparation, **not** the required separate technical audit or proof of CI.
1. `VanDeliveryExecutionController.php`: only explicit locale selection and translated human 422. Rejection predicate, API field name and existing middleware unchanged.
2. `backend/lang/ar/van_delivery_execution_errors.php`: Arabic UI string, preserve technical header name, no secrets.
3. `backend/lang/en/van_delivery_execution_errors.php`: original English message exact, matching key.
4. `VanDeliveryExecutionTest.php`: seeded Sanctum `app:van` and B2B assigned-order real HTTP requests for both persisted locales, contrary Accept-Language, missing/invalid/overlong headers across all 4 mutating endpoints. Assert 422 field copy, unchanged assigned state/order and zero events/payments. Existing positive delivery/replay, cross-van and collection regressions remain needed in full CI.
5. This QA document is source-to-test traceability only and does not certify runtime/staging.

## Per-surface impact
- **Van:** existing human validation message localized in already returned HTTP422 field. No request shape, DTO, Flutter, route or role change. No APK build needed for this string-only backend change.
- **Customer:** no route, response, API, Flutter or fulfillment behavior change.
- **Driver:** no route, response, API, Flutter or B2C fulfillment behavior change.
- **Dashboard:** no page, nav, permissions or UI behavior change.
- Immutable fulfillment rule: B2B → VAN ONLY; B2C → DRIVER ONLY. No new cross-app issue warranted because no consumer contract changes.

## Merge and acceptance gate
Require separate same-account **COMMENTED** file-by-file HIGH-risk engineering audit (not independent GitHub approval), full Laravel negative/positive suite, PHP/Pint/PHPStan/security, AR/EN parity, MySQL/Redis install/upgrade/recovery, Repository Policy, V2 Isolation and terminal Required CI all **SUCCESS** on latest exact PR HEAD. Never merge on pending/red/superseded checks; only merge into `Foodex_V2` with expected SHA. Closure only after merged source and documented CI evidence, as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`; staging/device UAT remains #2503, with no main, production or release authorization.
