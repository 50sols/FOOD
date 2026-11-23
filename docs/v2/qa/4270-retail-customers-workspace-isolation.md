# P031 — Retail Customers workspace HTTP tenant isolation (#4270)

- Parent #2782, master #2503, Foodex_V2 only.
- Route GET /admin/b2c/customers (admin.b2c.module, module=customers) is protected by customers.view and B2cWorkspaceController's canonical selected-store authority.
- Store A B2C_STORE_ADMIN can access scoped Customers page in AR RTL and EN LTR, including forged channel=b2b query with trusted store A.
- Deny foreign Retail store B or Wholesale store ID (404), contradictory store_id/store aliases (404) and malformed selector variants (404).
- Wholesale-only B2B_ADMIN or authenticated roleless account must return 403 even when passing the Retail store selector.
- GET must not alter orders, payments, invoices or driver_assignments counts.
- Distinct from Retail Finance #4244, Retail Orders #4238, Retail Promotions P232 #2562. Existing runtime/permissions/modules remain unchanged.
- Mobile Customer NO_DIRECT_IMPACT, Driver NO_DIRECT_IMPACT, Van NO_DIRECT_IMPACT. No mobile/API/fulfillment code changes.
- HIGH RBAC security risk; same-account COMMENT review is not independent GitHub APPROVED.
- Do not close until exact final head Repository Policy, V2 Isolation, full Required CI including Laravel/MySQL Redis and required-ci-gate SUCCESS; review/protected SHA-guarded merge Foodex_V2, verify both files on merge SHA. Close only #4270, parent/master remain OPEN. No main, production, release or APK.
