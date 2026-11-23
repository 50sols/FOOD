# P031 #4381 — AI ERP principal role projection after archival

- Independent security leaf under #2782 and master #2503, integration target Foodex_V2 only.
- AiPrincipalContextResolver::roleCodes now rejects archived role memberships in both global and assigned Retail store projections. The canonical OperationalTenantScope and User effective permissions already exclude archived roles, but the principal role code snapshot had a separate unfiltered code path; stale historical identities could reach downstream AI role-based policy logic.
- DB-backed CoreReferenceSeeder Feature tests: active B2B_ADMIN and SUPER_ADMIN projected before archive, archived-but-active SUPER_ADMIN removed while B2B_ADMIN remains, both fresh and stale actor; independent active store assistant-use role retains same-store eligibility, archived B2C_STORE_ADMIN identity removed, foreign store still 404, no audit mutation for global read.
- HIGH-risk scoped security review; does not change mobile apps or claim all Assistant authorization resolved. Require exact-head Repository Policy, FOODEX V2 Isolation, full Required CI four Laravel shards/MySQL Redis, expected-SHA guarded merge and verified issue close. Parent #2782 stays OPEN.
- MOBILE IMPACT Customer NO_DIRECT_SOURCE_IMPACT, Driver NO_DIRECT_SOURCE_IMPACT, Van NO_DIRECT_SOURCE_IMPACT. No main, releases, production or Flutter.
