# V2 #4357 — persisted revocation for B2C customer policies

- Independent P031 leaf under #2782/#2503; base Foodex_V2 only.
- B2cCustomerPolicy view/update/delete now require an in-memory active user and currently persisted active users row before own-profile shortcut or store-scoped role/permission checks. Existing enabled customer-owner, B2C_STORE_ADMIN scoped grants and SUPER_ADMIN scope restrictions are preserved.
- Seeded DB-backed Feature regression tests real stale revoked actors, fresh revoked actors, reactivation, own vs other/foreign store customer, class Gate, store staff view/update/delete, inactive store role, SUPER_ADMIN deny and audit nonmutation.
- HIGH security source review; this leaf is not a claim that unrelated endpoints or already-running concurrent transactions are atomically revoked. Require exact-head Repository Policy, V2 Isolation, all Laravel shards/MySQL Redis Required CI and protected expected-head merge before close; parent #2782 remains OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT to Flutter/schema (denial for revoked identity intended), Driver NO_DIRECT_SOURCE_IMPACT, Van NO_DIRECT_SOURCE_IMPACT. No main, production, release, tags or mobile code modifications.
