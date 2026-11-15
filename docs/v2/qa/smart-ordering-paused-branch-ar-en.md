# V2 #3758 — Smart Ordering paused-branch rejection AR/EN

Integration: **Foodex_V2 ONLY**. Public restaurant Smart Ordering checkout; existing POST /api/smart-ordering/carts/{cart}/checkout uses BranchPolicyService::assertAccepting as its server-side operational gate.

## Source contract

`BranchPolicyService::assertAccepting` must first deny inactive branches (404), then apply SmartOrderingRuntimePolicy::assertRequest to the site's ordering module/entitlements, then reject `accepting_orders=false` with HTTP 409. This patch changes **only** the previously hard-coded 409 user-facing message to `__('smart_ordering.branch_policy.not_accepting_orders')`, with the same AR/EN keys in existing Smart Ordering language catalogs. Request acceptance state, module access, headers, price, payment, fulfillment, store scope and database writes are not changed. The branch's paused state remains fail-closed; no mobile client can override it.

## Deterministic Laravel proof

`SmartOrderingCoreTest::test_paused_branch_rejection_is_localized_in_ar_en_without_checkout_writes` uses the existing real seeded active Retail restaurant, authorized ordering runtime and published revision. It constructs token-protected carts while the branch accepts orders, pauses the branch, then requests genuine public checkout for both `ar` and `en` via `Accept-Language` and application locale. It asserts HTTP 409 plus exact translated `message`, no changes to orders, payments, audit, cart-items, cart statuses and branch acceptance. Existing successful purchase and closed-branch tests retain business acceptance coverage.

Required before merging: targeted SmartOrderingCoreTest, full Laravel, PHP syntax/Pint/PHPStan/security, AR/EN localization parity, true MySQL/Redis environment, Repository Policy, V2 Isolation and terminal FOODEX Required CI exact-final-head success. Do not treat build-only green as acceptance.

## Mobile and dashboard impacts

- Customer: public restaurant storefront receives locale-correct business error with unchanged status and JSON shape; Customer Flutter and public commerce DTO unchanged.
- Driver: no change to Driver app, assignments, permissions or B2C delivery.
- Van: no change to Van app, van routes, permissions or B2B delivery.
- Dashboard: existing branch operational toggle/RTL/LTR UI unchanged; no new route/menu/permission.
- REVIEW_RISK HIGH: public order acceptance and module entitlement enforcement. Same-account COMMENTED file-by-file engineering audit is not independent GitHub approval. Master #2503 retains manual/staging final acceptance.
- No main, production, release, tags, APK, cPanel or migration changes.
