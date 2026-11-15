# V2 #3735 — Retail Store inactive primary owner AR/EN 422

Parent: #2503. Source: docs/quality/LOCALIZATION_CONTRACT.md. **Only integration base: Foodex_V2.** No main, releases, tags, production, cPanel or APK.

## Existing entry and source

Canonical Management Dashboard > Retail Stores > Update Store PATCH /admin/retail-stores/{store}, authorized only to SUPER_ADMIN, includes optional primary_owner_user_id. The original validator checks that the referenced user exists. The next active-user lookup fails closed with HTTP 422 before any store or linked Wholesale account mutation, but its user-visible error was hard-coded in English even when the authorized actor's selected locale is Arabic.

Fix: replace precisely that existing 422 message argument with the controller's established msg(ar,en) resolver; preserve the user-active predicate, input validation, status, auth, store/channel scope and mutation order. No new field, route, permission, store lifecycle or screen; existing Dashboard AR/EN and RTL/LTR remain unchanged.

## Tests / safety

New real RefreshDatabase/CoreReferenceSeeder authenticated HTTP test uses a SUPER_ADMIN and existing inactive owner user ID in a valid B2C Retail Store PATCH request. It checks exact 422 message for Arabic/English actor locale, unchanged store code/name/is_active, no new Retail↔Wholesale owner links, no B2B account, role grant or audit write. A roleless user must be denied with 403. The existing RetailStoreProvisioningTest covers permitted active primary owner updates, store provisioning and positive account identity linking without changes.

Risk HIGH because primary store owner controls business/account identity; review file by file including negative no-write/authorization and data-scope threats, with same-account COMMENTED review recorded explicitly as **not independent APPROVED**. Verify final exact-head Policy, V2 Isolation, Laravel/PHP/Pint/PHPStan/security/OpenAPI, AR/EN, MySQL/Redis and terminal Required CI before merge; only close code-complete atomic leaf after GitHub confirms Foodex_V2 integration SHA. Final manual UAT deferred to open #2503.

## Cross-surface effects

- Dashboard: human-only 422 error copy on the existing authorized Retail Stores update action. No new nav/UI/layout, retaining RTL/LTR.
- Customer: NOT AFFECTED: the inactive owner guard is unchanged, no new Customer API, DTO, Flutter or identity change.
- Driver: NOT AFFECTED: no Driver API or Flutter change, no B2C dispatch change.
- Van: NOT AFFECTED: no Van API or Flutter change, no B2B dispatch change.
- No schema/migration, finance ledger postings, queue, cache, storage or notifications changed.
