# V2 #4355 — archived Wholesale role denies Lookup eligibility

Parent P031 #2782 / V2 master #2503. Integration target `Foodex_V2` only.

**Defect:** `LookupScopeService::canAccessWholesale` checked persisted actor and role `is_active`, scope and B2B global-role code, but omitted `roles.archived_at IS NULL`. An archived active B2B role was still eligible for Wholesale lookup projection.

**Fix:** add `whereNull('roles.archived_at')` in the existing role query. This preserves active B2B eligibility, stale/deactivated principal denial, inactive-role denial, and read-only behavior. No migration or new route.

**Tests:** Seeded Laravel `V2ArchivedWholesaleLookupRoleTest` explicitly verifies granted active role, archived-but-active denial with stale actor, refreshed denial, persisted user revocation/reactivation and disabled role; audit rows unchanged after lookup checks.

**Acceptance gate:** High security technical review, exact HEAD Repository Policy, FOODEX V2 Isolation, full Required CI including Laravel/MySQL/Redis, SHA-guarded merge and postmerge verification. Self-review is not independent approval.

**Mobile impact:** Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No Flutter, main, release, tag or production edits.
