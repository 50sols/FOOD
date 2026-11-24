# P031 / #4320 — fail-closed revoked identity at global-search registry entry

## Authoritative boundary
The `GlobalSearchRegistry` accepts a `User` and composes registered core and optional-module providers. Each built-in provider may have its own permission checks, but a future core provider or custom module must not receive an inactive actor whose grants survive offboarding for audit. The shared registry must reject revoked identities **before** scope/factory evaluation.

## Implementation
A top-level `if (! $actor->is_active) return [];` prevents any provider resolution for deactivated users. It does not grant or mutate permissions, bypass module entitlements, modify routing or change active-actor search results. Existing direct controller authentication and built-in scope checks remain in place as defense in depth.

## Regression
`V2RevokedGlobalSearchRegistryTest` registers a concrete core provider plus an optional module whose scope/factory callbacks are observable. A disabled actor produces no results and causes no provider/scope calls, including with a positive limit. The active actor still receives the expected bounded core result. The existing `EnterpriseGlobalSearchTest` checks live AR/EN route behavior, store and organization authorization, literal query matching and lazy optional modules.

## Delivery fence
REVIEW_RISK: HIGH (identity revocation/privacy). Review source/test/QA separately. Exact-head Repository Policy, V2 Isolation and FOODEX Required CI (Laravel shards, MySQL Redis, localization) must finish SUCCESS. Guarded issue-scoped merge to `Foodex_V2` only, followed by integration file verification and leaf completion evidence. P031 parent #2782 and V2 master #2503 remain open for final acceptance.

**MOBILE IMPACT — Customer / Driver / Van:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Registry is dashboard-only; no API, Flutter, dispatch, order lifecycle, cPanel release, main or production changes.
