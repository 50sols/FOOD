# V2 #4359 — archived roles cannot resolve tenant contexts

P031 atomic security leaf under #2782 / master #2503. Integration branch **Foodex_V2 only**.

**Defect:** `TenantContextResolver::wholesale()` accepted archived-but-active global B2B roles, and `assignedRetailStoresQuery()` accepted archived-but-active B2C scoped roles. This could retain stale tenant authorization and store enumeration despite historical role archiving.

**Implementation:** require `roles.archived_at IS NULL` alongside existing active-role and channel/store predicates in both queries. Preserve persisted user-active check, SUPER_ADMIN support-access auditing, active B2B and B2C role access and existing tenant channel boundaries.

**Regression:** seeded `V2ArchivedTenantRoleResolutionTest` verifies B2B role admission before archival then 403 with stale and fresh actors; B2C store enumeration/resolution before archival then no IDs/default and 403 after archival; ensures audit counts remain unchanged.

**Security acceptance:** HIGH risk source inspection, exact-head Repository Policy, V2 Isolation and full Required CI including Laravel shards and MySQL/Redis; protected SHA merge with postmerge source test and issue closure. A self-authored technical review is not independent approval.

**Impact:** Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No Flutter, main, release/tag, or production change.
