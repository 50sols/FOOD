# V2 #4341 — persisted revocation in lookup permissions

- Parent #2782 / #2503; original issue #4341 and Foodex_V2 only.
- LookupScopeService::visible embeds an active-user exists subquery into the lookup SELECT, denying stale revoked SUPER_ADMIN and store/wholesale actors without adding an independent read query. In-memory disabled actor also fails closed.
- authorizeMutation, canManage, manageableScopes, visibleRetailStores fail closed against persisted users.is_active state. canAccessWholesale checks persisted identity by joining active users to pre-existing role membership query.
- V2RevokedLookupScopeTest seeds real reference roles and Units; covers active and stale revoked and reactivated privileged user, denied global mutation 403 and no audit changes; B2B role stale revocation and inactive role.
- Risk HIGH: authoritative lookup path only, no blanket guarantee for unrelated controllers, no claim about cross-query transaction atomicity. Require exact final-head full Laravel/MySQL Redis + Repository Policy + V2 Isolation, security source review, protected SHA merge; parent stays OPEN.
- MOBILE IMPACT: Customer NO_DIRECT_SOURCE_IMPACT; Driver NO_DIRECT_SOURCE_IMPACT; Van NO_DIRECT_SOURCE_IMPACT. No Flutter, main, tags, release or deployment.
