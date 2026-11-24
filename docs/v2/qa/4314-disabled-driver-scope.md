# P031 / #4314 — disabled Retail Driver identity scope

## Threat boundary
`DriverTenantScope::eligibleRetailDriver()` currently selects an active driver profile on an active B2C store for a supplied User, without checking whether the identity itself is active. A historical active driver profile must not remain eligible when the account is offboarded. Even a legacy unscoped recovery check may not bypass user revocation.

## Atomic implementation
Return null immediately for `! $user->is_active` before accessing the driver database. Active user/profile/store selection, Retail type, current legacy unscoped flag and downstream 409 handling remain unchanged. No new grant, token issuance, session or route is introduced. This is defensive backend security alignment with `OperationalTenantScope::allowedStoreIds` and `User::hasPermission`, both of which already revoke disabled identities.

## Acceptance
`V2DisabledRetailDriverTenantScopeTest` seeds a B2C store, active User and Driver, proves a valid active profile is selected, disables the User and proves neither the normal nor legacy resolution returns a Driver while the profile/store remain active; re-enables the account and proves selection resumes, then disables the store and proves denial.

Risk HIGH (identity/tenant). Review both changed source/test files and this QA record; exact-head Repository Policy, V2 Isolation and Required CI including Laravel/MySQL Redis must all terminal SUCCESS before guarded merge to Foodex_V2. Child #4314 may close only on verified integration; parent #2782 and master #2503 stay OPEN. Customer: NO_DIRECT_IMPACT; Driver: BACKEND_SECURITY_DENIAL for already-disabled identities only (no Flutter code/API payload/valid session change); Van: NO_DIRECT_IMPACT. No main, release, production or APK.
