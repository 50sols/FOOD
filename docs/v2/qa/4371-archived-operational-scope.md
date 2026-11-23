# #4371 — Archived roles in operational store scoping

- Atomic P031 security leaf under #2782/#2503, target Foodex_V2.
- OperationalTenantScope::allowedStoreIds now excludes archived role candidates in 3 independent paths: role-permission store assignments, scoped direct ALLOW's underlying assigned role, and global role channel projection. Existing active flag, persisted user, active store, B2C vs B2B, explicit DENY, and Super Admin guards remain.
- Seeded DB regression tests: active Retail assignment allowed but archived active role denied even for stale actor; store-scoped direct override cannot reanimate archived assignment; active B2B role contributes orders.view while archived SUPER_ADMIN cannot introduce B2C channel, and B2B channel persists. No audit log mutation.
- HIGH technical security scope; does not claim system-wide auth completeness or atomic concurrent revocation. Require exact-head Repository Policy, V2 Isolation, full Required CI all Laravel shards/MySQL Redis, SHA-guarded Foodex_V2 merge and verified issue closure. Parent stays OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT to Flutter; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No main, release, production, tags.
