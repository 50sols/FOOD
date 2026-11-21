# V2 #3802 — Smart Ordering branch capacity HTTP 409 Arabic / English

**Source:** `BranchCapacityService::admit` / existing capacity ceiling rejection. **Target:** `Foodex_V2` only. Parent #2503 remains open; no main, release, APK or production deployment.

## Source boundary and change
- `backend/app/Domain/SmartOrdering/BranchCapacityService.php`: change only the hard-coded English capacity 409 message to `__('smart_ordering_capacity.branch_full')`. Preserve the original English text verbatim, capacity predicate, branch lock, UTC window, active lifecycle statuses, idempotency, transaction and promise calculation.
- `backend/lang/en/smart_ordering_capacity.php` and `backend/lang/ar/smart_ordering_capacity.php`: exact paired text. Separate catalogues intentionally avoid actively owned `smart_ordering.php` under #3799.
- `backend/tests/Feature/V2SmartOrderingBranchCapacityLocalizationTest.php`: isolated real B2C guest-cart API fixture with CoreReferenceSeeder, one live first checkout and one rejected checkout for each Accept-Language ar/en. Assertions: exact 409 message, no second orders/contexts/payments/audit/stock movement/cart-item writes, inventory quantity/reservation and second cart status unchanged. The completed first order frees capacity for the second locale scenario.
- No modifications to #3799's `SmartOrderingCoreTest.php` or BranchPolicyService.

## Mobile and Dashboard contract impact

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend / public QR checkout | Affected | Existing HTTP 409 human message translated based on effective Laravel request locale; response shape/status unchanged |
| Dashboard | Not affected | No admin route, UI, permission, sidebar or DB model changed |
| Customer Flutter | Not affected | No Customer app path, endpoint, schema, DTO or auth change; QR restaurant guest error remains a plain 409 message |
| Driver Flutter | Not affected | No Driver API/assignment/dispatch change; B2C delivery = DRIVER ONLY |
| Van Flutter | Not affected | No Van API/assignment/dispatch change; B2B wholesale = VAN ONLY |

## High-risk negative/source review
- **REVIEW_RISK: HIGH** — public checkout capacity and stock/payment boundary even though code diff only changes message lookup.
- Lock, branch scope, active-state exclusions, 60-minute window, max count, order transaction and inventory are untouched. Translation lookup must not become a bypass or a second capacity policy.
- Both valid first and rejected second checkout paths run against a seeded database using actual HTTP entry point, no fake messages, no direct mutation of a rejected cart.
- Test runs must prove Repository Policy, V2 Isolation, full Laravel, AR/EN, PHP/Pint/PHPStan/security, MySQL/Redis install/upgrade/recovery and terminal Required CI on exact final SHA. Historical or pending results are insufficient.
- Any remaining manual/staging/device UAT tracked by #2503; issue closure only after merged Foodex_V2 PR and complete exact-head acceptance.
