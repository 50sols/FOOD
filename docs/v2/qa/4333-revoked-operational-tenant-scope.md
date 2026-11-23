# V2 #4333 — persisted revocation in OperationalTenantScope

- Parent: #2782 (P031), master #2503. Base: `Foodex_V2` only.
- A previously loaded User with `is_active=true` can become revoked in the persisted `users` row; checking only the Eloquent attribute is not authoritative.
- `allowedStoreIds` now requires a persisted active user *before* Super Admin/role and override evaluation. `assertStore` inherits denial (404). No grant, impersonation, role/channel/override or mutation behavior changes.
- Test `V2RevokedOperationalTenantScopeTest` uses seeded reference roles, real Retail store records and DB revocation with intentionally stale User instances. Covers privileged and store-assigned actor, fresh revoked actor, reactivation, selected Retail, and unchanged audit rows for the Super Admin path.
- Security risk: HIGH; source and tests require independent review and exact final-head Repository Policy, V2 Isolation, Required CI including Laravel/MySQL/Redis before merge. Source-only evidence is not a claim of CI success.
- Mobile impact: Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No Flutter/mobile source or API contract changes. No release, main or production deployment.
