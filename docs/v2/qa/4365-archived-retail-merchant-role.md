# #4365 — archived Retail manager role cannot confer merchant identity

- Atomic P031 leaf, parent #2782/#2503, integration target Foodex_V2 only.
- RetailMerchantIdentityService::managedRetailStoreIds now requires roles.archived_at IS NULL in addition to the existing B2C_STORE_ADMIN code, active role, store scope, active Store and B2C store type requirements. This prevents an archived-but-active role from being projected as managed merchant/retail store identities and downstream account bindings. Actual owner records in retail_wholesale_accounts are not modified or conflated with role-based management.
- Seeded DB-backed Feature tests cover legitimate active Retail role, archival without toggling active, cached vs refreshed user, identityPayload/managedRetailStoreIds/retailStoreIds/isRetailMerchant denial, B2B and inactive Store boundaries, inactive role, no audit mutation.
- Risk HIGH authorization, narrowly scoped; separate persisted-disabled-account authorization and owner linkage are not changed by this leaf. Require exact-head Policy/Isolation/Required CI Laravel shards and MySQL/Redis, source review, guarded merge then verify closure. Parent stays OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT to Flutter, Driver NO_DIRECT_SOURCE_IMPACT, Van NO_DIRECT_SOURCE_IMPACT. No main/production/releases/tags.
