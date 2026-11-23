# V2 #4346 — StorePolicy persisted actor revocation

- P031 leaf under #2782 / #2503, integration base Foodex_V2 only.
- StorePolicy::viewAny previously trusted the loaded User.is_active value; if another session revoked the persisted account, the stale model could still pass the class-level Store listing authorization.
- viewAny now requires in-memory active and a persisted active users row keyed by the actor; read-only query. StorePolicy::view still delegates to the existing StoreAccess::canAccess persisted-identity/channel boundary rather than adding a second standalone database query to per-store policy checks.
- Seeded DB feature regression verifies active/stale revoked/fresh revoked/reactivated B2C_STORE_ADMIN, own and foreign Store view, Gate class-level viewAny, SUPER_ADMIN revocation and in-memory inactive account, no audit mutation.
- HIGH-risk scoped security review; no claim of eliminating every authorization issue outside StorePolicy or making concurrent operations atomically revocable. Require Policy, Isolation, exact-head complete Required CI (Laravel shards/MySQL/Redis), protected SHA merge, verified issue close. Parent remains OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No Flutter source/schema, main, releases, production or tags.
