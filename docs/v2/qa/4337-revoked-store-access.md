# V2 P031 #4337 — persisted active identity in StoreAccess

- Atomic owner: #4337 under #2782/#2503; merge target Foodex_V2 only.
- StoreAccess was an independent model/query-level access service and previously did not check persisted users.is_active in global, direct-store, or store-owned query paths.
- The global SUPER_ADMIN membership query now requires a currently active **persisted user**, active role and global role scope; scoped user-store assignments and subquery store filters require a persisted active user. Existing channel, role, store-active and ownership filters remain. No standalone extra lookup was added to the candidate store list queries.
- Synthetic CoreReferenceSeeder/RefreshDatabase test verifies stale revoked SUPER_ADMIN and B2C_STORE_ADMIN actors, correct active and reactivated grants, foreign-store denial, inactive SUPER_ADMIN role, query visibility, direct canAccess, no audit mutation.
- HIGH-risk security review; no claim of complete mitigation of mutations outside StoreAccess, multi-query snapshot races, or production authorization. Verify exact-head Laravel/required CI, MySQL Redis, Repository Policy and V2 Isolation before merge. Keep parent #2782 open.
- Customer / Driver / Van: NO_DIRECT_SOURCE_IMPACT. No Flutter source, mobile API schema, release, main, or production changes; revoked identity denial is deliberate.
