# V2 #2731 / MOB-C02 — authenticated Customer identity and store-scoped navigation

Source: FOODEX unified RBAC/ERP/Sidebar discussion P031/P110/P119; parent #2503. Delivery `Foodex_V2` ONLY. Original issue PR [#3216](https://github.com/walidatiyaai2025-gif/FOOD/pull/3216) reopened under the owner's explicit administrative "resume by REOPENING the same PR" instruction. Original history and original three issue files preserved, safely reconciled against current integration. **No main, tags, releases, staging or production.**

## End-to-end source trace and authority

### Authoritative Backend identity and scopes

- One `CustomerSession.platformCustomer` and unchanged bearer token across the B2B/B2C commerce-context switch. The token grants identity, *not* an arbitrary store choice.
- `StorefrontController::selector` (`GET /api/v1/store-selector`) is the **sole authoritative authenticated selector**: eligible active Retail storefronts are discoverable, while its sole Wholesale option is the **configured active platform principal** only when the actor has an active direct B2B account, linked entitled Retail purchasing context or explicitly authorized Super Admin support access. `entitlements.principal_wholesale_store_id` must match the principal row. Runtime action authorization is checked again independently on the actual B2B/Retail API, with tenant/store/channel/active-store and SoD protection. A Retail storefront being public does not grant an owner permission to purchase from their own store.
- The public guest `GET /api/v1/platform/storefront` is deliberately separate and can show public Wholesale products or Retail placements. **Never borrow that public principal into an authenticated account's selected Wholesale commerce context.**
- No new Backend routes, role, entitlement table, database mutation, or finance/dispatch change. P031/P110/P119 authority is reused; no cosmetic UI grants. Existing `PlatformCustomerMarketplaceTest` positive registered platform identity + B2B/Retail materialization and exact store cart tests remain. New HTTP regression verifies unauthenticated selector 401, authorized public Retail browse, absence of Wholesale entitlement/principal for an unentitled signed-in account in AR/EN, direct B2B protected route 403, inactive Retail store removal/404, and no accidental B2B customer materialization.

### Customer Flutter networking and selector

- `HttpStorefrontApi.selection` now returns only the **authenticated** selector response from `/api/v1/store-selector` when token is present. It no longer silently falls back to public platform data on absent, malformed, or denied B2B entitlement; 403 stays 403. Guest public discovery remains intact.
- Original issue `CustomerCommerceContextSwitcher` fail-closed decode retained: malformed top-level arrays/maps, non-string keys, invalid IDs, duplicate Retail stores, missing principal marker, mismatching principal IDs and missing entitlements reject the entire scope list **before** any context can be persisted. Platform context switching also requires a nonempty bearer token, preserving its existing `CustomerCommerceContextSwitchException` contract.
- The **actual production StoreSelectionDesignScreen** now invokes `CustomerCommerceContextSwitcher.validatedSelection` on authenticated platform selector responses, not the previous lenient `mapRows` list alone. Both live UI projection and programmatic `availableTargets/switchTo` share `targetsFromSelection`. Any extra non-principal Wholesale rows are filtered from the live UI; no invalid/duplicate Retail candidate or partial malformed selector becomes a clickable card. Invalid data displays a retryable localized error and no authorized store card.
- Retail and Wholesale taps now use `CustomerRouteLocations.retailHome/wholesaleHome` with explicit `channel` and `store_id`, preserving existing receiver-store selection, platform identity and token. Existing `CustomerAppRouter` still supplies the actual route, updates active commerce context, and revalidates route channels. Switching does not convert B2B into Driver, nor bypass destination HTTP authorization.
- Selector/card/modal labels and loading/empty/error/filter text now use **18 paired AR/EN keys** in `AppTranslations`, respecting the app's RTL/LTR inheritance and existing responsive material cards, heading, keyboard/tab focus and error/retry UI. No new translation API contract or data value is required.

### Verification / negative acceptance

| Path | Proof | Invariant |
|---|---|---|
| `apps/customer_app/test/customer_commerce_context_switcher_test.dart` | Existing valid B2B/Retail same-token switch, forged/malformed payload, no persisted scope; added live validated projection duplicates/nonprincipal tests | No invented store entitlement or double routes |
| `apps/customer_app/test/customer_storefront_selector_authority_test.dart` | Real `HttpStorefrontApi` with HTTP mock: authenticated no-Wholesale, valid B2B+Retail exact routes/same token, 403 cannot downgrade to guest, malformed envelope, guest discovery, missing token | Backend selector alone decides authenticated choices |
| `apps/customer_app/test/v2_customer_store_selector_runtime_test.dart` | Real live selector Widget in AR and EN: Retail-only authorized list, no Wholesale entry, scoped URL, entitled principal-only B2B URL, malformed duplicate rows show localized error, no partially rendered cards | The production UI uses strict decoder and navigation |
| `backend/tests/Feature/PlatformCustomerMarketplaceTest.php` | New AR/EN authenticated negative HTTP test, guest vs protected selector, active Retail browsing and inactive Retail denial, B2B 403/no writes | Server checks remain the source of truth |
| Existing platform identity and route suites | `PlatformCustomerRegistrationOriginTest`, `PlatformCustomerUnifiedOrdersTest`, `SplitCustomerDomainIsolationTest`, `RetailMerchantIdentityIsolationTest`, `CustomerRouteAuthorityTest`, `multistore_route_partition_test` | Shared customer identity and per-store business actions remain isolated |

Security failure cases: spoofed/duplicate Retail id, forged principal, misplaced B2B marker, API non-2xx, missing platform token, malformed arrays or account data, non-entitled Wholesale attempt, cross-channel URL and disabled Retail store. **No new persistent session or bearer changes on failure.** Browser bookmarks and direct HTTP requests still use real server authorization and do not trust selector UI state. Strict rules never grant a server-side action.

## Per-app impact and delivery evidence

- **Customer: AFFECTED.** HTTP selector client + typed decoder, actual storefront-choice UI and 18 paired UI translations, 3 targeted Flutter test suites, explicit store/channel routes. No reauthentication or separate account per store.
- **Backend API: AFFECTED IN TESTS ONLY.** New HTTP negative regression in the canonical existing Platform Customer marketplace test; live endpoints, tenancy, audit, user-role model, schema, public API payload and checkout semantics unchanged.
- **Driver: NOT AFFECTED.** No Driver source, API contract, driver auth, GPS, order assignment, status or B2C fulfillment changes. Existing common cross-app token partition program #2740 and master #2503 remain authoritative.
- **Van: NOT AFFECTED.** No Van source, API contract, Van auth, route/territory/visit/offline replay or B2B fulfillment changes. **B2B = VAN ONLY**, **B2C = DRIVER ONLY** unchanged.
- **Dashboard: NOT AFFECTED.** No admin screens, RBAC/layout settings, finance approvals, export or audit persistence altered.
- **Review risk: HIGH** (authenticated identity, tenant/channel scope and mobile routing). Per-file engineering audit and exact-head Policy, V2 Isolation, Required CI (Flutter analyzer/format/tests/Android, Backend Laravel/MySQL, localization/security) and any applicable exact-head 3-app Android evidence must all pass **on the final SHA** before any merge. Historical green CI on `32edecf` does not certify this implementation; do not waive failed, queued or skipped mandatory checks.

The issue-level automated source acceptance is distinct from final physical-device/staging/RTL/a11y and multi-app release acceptance on #2503/#2741/#2750. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` does not defer mandatory implementation or security CI. Record final tested SHA, PR merge SHA and CI URLs when/if source acceptance is proven. Do not close or merge if any mandatory check remains pending or red.
