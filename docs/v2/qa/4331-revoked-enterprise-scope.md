# FOODEX V2 P031 / #4331 — persisted revocation for enterprise scope authorization

## Security boundary
`EnterpriseScopeAuthorizer::allows` consumes a `ResolvedTenantContext` that may have been obtained before a concurrent account revocation. It previously checked only the supplied in-memory `User::is_active` and would accept the SUPER_ADMIN shortcut for an old active User and old context after the persisted user was disabled. This is distinct from initial context admission (#4325) and the separate enterprise role/delegation security resolver (#4327).

## Source
Check current persisted `users.is_active=true` alongside the caller's active flag before any SUPER_ADMIN shortcut or permission/scope decisions. Retain current active identity B2B/B2C context, exact Retail support and store ownership semantics, and existing normal roles; no database, permission, audit or context mutations.

## Feature acceptance
`V2RevokedEnterpriseScopeAuthorizerTest` uses a persisted seeded SUPER_ADMIN identity and a valid B2B control-plane `ResolvedTenantContext`. Active actor is allowed. A direct DB deactivation leaves its User model and context stale; `allows` returns false for cached and fresh revoked identities, `authorize` fails HTTP 403 and audit count remains unchanged; reactivation permits a fresh active User. A disabled regular actor is denied. This verifies the canonical authorizer directly and does not claim every ERP workflow is completed.

## Release governance
HIGH identity/tenant security risk. Require exact-final-HEAD Repository Policy, FOODEX V2 Isolation and FOODEX Required CI (Laravel/MySQL Redis), file-level technical review and protected SHA merge to `Foodex_V2` before issue closure. Parent #2782 / #2503 remain OPEN. MOBILE IMPACT — Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No main, releases, production or mobile source changes.
