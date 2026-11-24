# P031 / #4327 — revocation of enterprise security identity

## Root cause
`EnterpriseSecurityAccessResolver::allows` checks an Eloquent `User::is_active` attribute, which may remain `true` in a request after a different transaction offboards the user. The resolver's SUPER_ADMIN shortcut then allows an enterprise permission without consulting the persisted identity state. This is distinct from the TenantContextResolver #4325 and GlobalSearchRegistry #4320 authority boundaries.

## Atomic fix
Before any SUPER_ADMIN shortcut, direct permission, scoped role, delegation or approval-limit logic, require **both** the supplied actor's active flag and a current `users.is_active=true` database row. Revocation denies and does not mutate roles, user permissions, scopes, delegations, approval limits or audit logs. Active actors retain all existing role, scope, amount, and delegation semantics.

## Executable database evidence
`V2RevokedEnterpriseSecurityIdentityTest` creates an active SUPER_ADMIN, verifies an existing enterprise permission is allowed, then disables that account directly in the DB while retaining a stale active Eloquent object. The stale actor and fresh revoked actor must both be denied, with no new audit write; re-enabling the persisted identity restores normal permission use for a fresh object. Another test covers an already-disabled regular actor.

## Acceptance and scope
HIGH identity / enterprise authority security risk. Require exact-head Repository Policy, V2 Isolation, FOODEX Required CI including all Laravel shards and MySQL/Redis acceptance, and a file-level security review. Only protected SHA-guarded merge into `Foodex_V2` may authorize closing this leaf issue; parent #2782/#2503 remains open. **MOBILE IMPACT — Customer: NO_DIRECT_IMPACT; Driver: NO_DIRECT_IMPACT; Van: NO_DIRECT_IMPACT.** No main, production, releases or Flutter.
