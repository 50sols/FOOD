# V2 #3925 — Targeted Customer popup membership isolation

**Source:** #3925, Customer parity #2733, master #2503. **Integration:** `Foodex_V2` only. Never merge to main, tag or deploy.

## Verified vulnerability and authority

`NotificationCampaignPopupController::visibleCampaigns` previously included `audience=user` campaigns whenever the request authenticated an existing User with the matching `user_id`. It did not require that user's **current** B2C membership in the requested store or B2B customer membership for a wholesale context. A previously targeted user could continue reading a private campaign after removal from the original retail store; an imported legacy B2B user target could be read by a B2C-only user. The identical query guarded popup impression/open/dismiss writes.

## Focused remediation

Re-use the existing `isCustomerForContext()` scope decision, which checks the authoritative `b2c_customers` user/store relationship for B2C and `b2b_customers` for B2B. Only include `audience=user` when `$isCustomer` is true. No duplicate role policy or trust in `store_id` alone. Keep `audience=all` guest discovery behavior and `audience=customer` logic unchanged, as well as existing store/channel restrictions and read/write route behavior.

## HTTP regression matrix

- Authorized Customer, correct Retail store: targeted campaign remains visible in Arabic and English.
- Same authenticated user, different Retail store: user-targeted campaign hidden; public campaign remains visible, no false 403 for an authorized public feed.
- Same user after removal of store-specific B2C customer relationship: private campaign no longer visible, targeted event returns 404.
- User who only belongs to Retail: forged/legacy B2B user target hidden, public B2B campaign remains visible, targeted event returns 404.
- Denied impression, open and dismiss events leave `notification_campaign_popup_views` unchanged.
- No migration, new client fields, route changes or additional endpoint needed.

## Impact classification

- **Backend API:** changed existing Customer popup read and events authorization; body/schema unchanged.
- **Dashboard:** no changes to campaign creation, admin placement, navigation, permission, master-data or reporting UI.
- **Customer Flutter:** existing GET/POST DTO and transport remain compatible; a previously leaked targeted campaign is now deliberately absent after access revocation. HTTP integration tests certify that boundary.
- **Driver Flutter:** not affected; controller selects only `app in (all, customer)` for the Customer popup endpoint and this change does not touch Driver routes.
- **Van Flutter:** not affected; same endpoint and changes do not alter Van routing, delivery, stock or B2B Van-only fulfillment.

## File-by-file security audit checklist

1. `backend/app/Http/Controllers/Api/V1/NotificationCampaignPopupController.php`: narrow targeted audience branch only, reuses existing authoritative scope; no data mutation, side effects, permission escalation or PII output introduced. Event route shares eligibility query.
2. `backend/tests/Feature/V2CustomerPopupTargetMembershipTest.php`: real seeded MySQL-compatible HTTP paths, positive coverage, AR/EN, cross-store negative, revoked membership and cross-channel denial, assertion of zero forbidden event writes.
3. This document: source provenance, risk register and mobile impact.

**REVIEW_RISK: HIGH** (tenant/customer privacy). Verify Pint, Laravel focused and full backend tests, AR/EN localization, Repository Policy, V2 Isolation and exact-final-head terminal Required CI before merge. A passing build is not an independent review or staging attestation. Rollback is the exact issue-scoped application-code predicate; no schema or data migration is required.

**Human/staging UAT:** deferred to master #2503 only if source code and automated acceptance are completely green on final head.
