# P031 — Wholesale Web Storefront Admin HTTP isolation (#4267)

- **Owner:** bounded issue #4267, parent #2782, master #2503; Foodex_V2 ONLY.
- **Canonical route:** GET /admin/b2b/web_storefront, via admin.b2b.module with module=web_storefront, protected by settings.view.
- **Distinct scope:** B2bWorkspaceController::webStorefrontModuleData() is separate from the storefront module covered in #4264. No public web, Customer app or Flutter changes.
- **Positive:** seeded B2B_ADMIN receives HTTP 200 in AR RTL and EN LTR, canonical WholesalePrincipal-derived storeIds.
- **Forged selectors:** channel=b2c and an actual Retail store_id do not broaden B2B actor's server-side Wholesale storeIds.
- **Negative:** B2C_STORE_ADMIN authorized only for Retail and a roleless authenticated user receive HTTP 403, including channel=b2b forgery attempt.
- **Read-only:** GET leaves orders, payments, invoices, driver_assignments row counts unchanged.
- **Mobile impact:** Customer NO_DIRECT_IMPACT; Driver NO_DIRECT_IMPACT; Van NO_DIRECT_IMPACT. Preserve B2B VAN ONLY / B2C DRIVER ONLY and current APIs.
- **Risk:** HIGH tenant isolation; same-account GitHub COMMENT is not independent APPROVED review.
- **Acceptance:** exact-final-head Repository Policy, V2 Isolation, full Required CI including Laravel and MySQL/Redis / required-ci-gate SUCCESS; reviewed protected SHA-guarded merge into Foodex_V2; verify both committed paths at the merge SHA; close only #4267, keep #2782 and #2503 OPEN. No main/release/production.
