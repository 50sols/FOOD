# P031 — Retail Inventory workspace HTTP two-store authorization (#4274)

- Parent security #2782, master #2503. Integration Foodex_V2 only.
- Canonical GET /admin/b2c/inventory, admin.b2c.module module=inventory, protected by inventory.view and server-side B2cWorkspaceController scope.
- Positive: Retail store A-scoped B2C_STORE_ADMIN gets HTTP 200 in Arabic RTL and English LTR; supplying forged channel=b2b cannot shift the authorized store.
- Negative: forged store_id for Retail B and Wholesale B2B store return 404; contradictory store_id/store alias and malformed selectors return 404; authenticated Wholesale-only B2B_ADMIN and roleless actors receive HTTP 403.
- Operational GET does not mutate orders, payments, invoices or driver_assignments row counts.
- Distinct focused Inventory module from Retail Finance #4244, Retail Customers #4270, Wholesale Inventory #4258 and broad Retail role/layout tests #2966/#2779. Reuse current controller/routes/authorization without runtime modifications.
- Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. No Flutter, mobile API or B2B Van / B2C Driver fulfillment changes.
- HIGH risk tenant RBAC review. Same-account GitHub COMMENT does not constitute independent APPROVED.
- Closure only after actual exact-final-head Repository Policy, V2 Isolation, full Required CI including four Laravel shards and MySQL Redis installation/upgrade/recovery terminal SUCCESS, file review and normal protections, SHA-guarded merge into Foodex_V2, verification of both files at exact merge SHA. Close only #4274; #2782 and #2503 remain open. No main, release, production or deployment.
