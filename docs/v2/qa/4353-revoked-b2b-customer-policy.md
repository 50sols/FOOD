# V2 #4353 — B2B customer policy persisted actor revocation

- Parent #2782 / #2503, Foodex_V2 only. Three issue-owned files.
- All view/update/delete B2bCustomerPolicy authorization paths fail closed when loaded user is disabled or the persisted users row is no longer active. This includes the direct customer.user_id owner shortcut and configured Wholesale role permission branch. Existing owner and wholesale active-role/permission grants remain unchanged for enabled actors.
- Seeded Feature regressions cover customer owner vs foreign customer, stale revoked vs fresh revoked, reactivation, Gate policy, Wholesale B2B_ADMIN staff authorized cross-customer then revoked, inactive role, and no audit changes.
- HIGH risk targeted policy; does not assert full atomic revocation of already-running transactions or unrelated policies. Require exact-head Repository Policy, V2 Isolation, Required CI all four Laravel shards and MySQL Redis, review and protected SHA merge before closing. Parent stays OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT to Flutter/schema (revoked authorization deliberately denied); Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No main/production/releases/tags.
