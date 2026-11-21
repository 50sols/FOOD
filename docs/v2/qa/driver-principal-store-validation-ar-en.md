# V2 #3709 — Principal Wholesale store 422 validation in AR/EN

**Integration:** `Foodex_V2` only, parent #2503. No `main`, deployment, release, APK or production. Manual acceptance remains deferred to V2 final.

## Canonical operation

The existing `POST /admin/business/drivers` and `PATCH /admin/business/drivers/{driver}` use `BusinessManagementController` and `OperationalTenantScope`. For a submitted `driver_type=b2b`, the server enforces `store_id === WholesalePrincipal::storeId()`. This guard remains at the same position before any new Driver account write or update; it still returns HTTP **422**. The change only replaces its previously English-only text with existing `msg(ar,en)` for two call sites. The Dashboard middleware already selects authenticated actor locale. Neither existing role/tenant scoping nor history/assignment protection changes.

## Automated regression

`AdminBusinessManagementTest::test_wrong_wholesale_principal_store_is_bilingual_and_rejected_before_driver_writes` creates a valid Retail B2C store and Driver through the actual authorized dashboard POST route, then tests both supported actor locales on:
- New B2B driver proposed for a Retail store: exact localized JSON `message` and 422, no User or Driver creation.
- Existing Retail Driver proposed to switch to B2B under that Retail store: 422, unchanged driver/store/type, no new role links or audit.
- Direct POST by a Driver account without Dashboard management role: HTTP 403, no newly created Driver.
Existing Retail positive flow, channel/store authorization and protected-route tests remain in place.

## Impact and safety

- **Customer Flutter/API** unchanged.
- **Driver Flutter/API** unchanged; only administrator-facing validation error text changes, not Driver permissions, notifications or fulfillment.
- **Van Flutter/API** unchanged; **B2B VAN ONLY**, **B2C DRIVER ONLY** remain enforced. The presence of a legacy B2B_DRIVER management path is not authorization for B2B Driver dispatch.
- No route, JSON schema, status code, frontend markup, Sidebar, schema, queue or scheduler modifications. Arabic and English responses inherit existing Dashboard RTL/LTR presentation.

Technical review risk **MEDIUM-HIGH** due to store/channel guards; file-by-file COMMENTED review is not independent GitHub APPROVED. Require exact-HEAD Repository Policy, V2 Isolation and terminal Required CI green (Laravel/PHP/Pint/PHPStan/security, AR/EN, MySQL/Redis install/upgrade/recovery) before guarded squash merge into `Foodex_V2` and leaf closure.
