# V2 #3831 — B2B account customer-mapping 409 AR/EN

## Scope and invariant
A legitimate B2B administrator cannot activate/modify a wholesale account when `b2b_accounts.b2b_customer_id` does not resolve to an authoritative `B2bCustomer`. Previously the existing HTTP 409 error text was fixed English; Arabic B2B dashboard admins received the wrong language. Both identical existing rejection sites (`updateStatus` and `resource`) now use a paired translated key and the authenticated actor's supported saved locale, with supported Accept-Language fallback. The existing original English, HTTP409, JSON `message` shape, B2B guard, Retail-linked status control, transaction and audit behavior are unchanged.

## Risk and negative tests
REVIEW_RISK HIGH — B2B identity, security gate, account activation. In `V2B2bAccountMissingMappingLocaleTest` an authorized B2B_ADMIN first creates a real pending account via HTTP, and the test then simulates a pre-existing orphan with a NULL linkage without deleting actual customer data. Two opposite persisted locales and contradictory Accept-Language headers assert exact translated HTTP409, pending status, inactive customer user, unchanged customer identity and zero audit/payment/user count mutation. A FINANCE actor is HTTP403 denied direct status changes. Existing `B2bAccountLifecycleTest` positive create/activate/deny remains intact and must run in full CI.

## Impact matrix
- Backend/API: same 409, message JSON schema and authorization, human copy localized only.
- Dashboard: B2B admin sees selected language in existing error response; no Blade, route or menu modification. Existing B2B account management entry point reused; no new navigation.
- Customer: no Customer Flutter/DTO/API shape change or identity activation semantics change.
- Driver: no Driver API, Flutter, dispatch or finance behavior change.
- Van: no Van API, Flutter, assignment or finance behavior change. B2B VAN ONLY, B2C DRIVER ONLY remain enforced.

## Final acceptance
This note is source and test traceability, not evidence of execution, independent review, MySQL/Redis or staging UAT. Required: separate file-by-file COMMENTED HIGH engineering review, PHP/Pint/PHPStan/security, AR/EN, full Laravel positive/negative tests, real MySQL/Redis install/upgrade/recovery, Repository Policy, V2 Isolation, terminal Required CI and exact-head green evidence before SHA-guarded merge **Foodex_V2 only**. Close #3831 code-complete only after verified integration; master #2503 remains open for final UAT. No main, release, tag, APK, cPanel or production.
