# P031 — Retail incoming orders canonical HTTP selector isolation (#4292)

**Scope:** Dedicated bounded Laravel Dashboard/ERP Admin HTTP regression on `GET admin.b2c.orders.canonical` with `status=pending` and canonical B2C selected-store authority. Parent #2782, master #2503, integration `Foodex_V2` only.

**Prior behavior:** #2538 / merged PR #3260 already verifies the Retail incoming/pending queue, basic Store B/foreign order rejection, sidebar authority and inactive actors. This independent leaf covers **additional malformed/noncanonical `store_id` and contradictory `store_id/store` aliases**; do not reopen or copy the previous PR or create an unsupported `incoming_orders` module.

**Allow:** seeded B2C_STORE_ADMIN assigned to Retail Store A sees pending queue HTTP 200, `selectedChannel=b2c`, `selectedStatus=pending`, `storeIds=[Store A]`, Arabic RTL and English LTR. Caller-forged `channel=b2b` must not replace trusted Retail authority.

**Reject:** explicit Retail Store B and Wholesale B2B Store selector HTTP 404; conflicting Store A `store_id` versus Store B `store` alias HTTP 404; malformed/noncanonical selectors `0`, `-1`, `not-an-id`, `01` HTTP 404; Wholesale-only B2B_ADMIN and roleless authenticated actors HTTP 403. GET row counts on orders/payments/invoices/driver_assignments do not change.

**Ownership:** exactly two additive paths: `backend/tests/Feature/V2RetailIncomingOrdersSelectorIsolationTest.php`, `docs/v2/qa/4292-retail-incoming-orders-selector-isolation.md`. No runtime controller, routes, statuses, persistence, API, Flutter, dispatch or fulfillment source modifications.

**Risk:** HIGH tenant/authorization; same-account technical engineering COMMENT is not independent GitHub APPROVED. Exact-final-HEAD Repository Policy, V2 Isolation and FOODEX Required CI (all four Laravel shards, backend fast-fail, MySQL Redis real install/upgrade/recovery, Enterprise performance) must conclude SUCCESS. Only normal protected expected-HEAD-SHA squash to Foodex_V2; verify both merged blobs before closing bounded #4292. Parents #2782/#2503 remain OPEN; no main, releases, deployment, APK or production. Customer, Driver and Van **NO_DIRECT_IMPACT**.
