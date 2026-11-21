# V2 #3695 — Coupon mutation error localization and authorized no-write guards

Parent: #2503. Source: P233 (Catalog & Pricing coupons), P082 effective action permissions and AR/EN quality. Integration: **Foodex_V2 ONLY**. This is a distinct narrow localization/security-regression leaf, not a recreation of #3588's already integrated literal coupon search.

## Scope and placement

- Domain/role/entity: canonical Coupon Management in Catalog & Pricing, for authorized Super Admin, Wholesale, or Retail store managers according to existing `coupons.view` / `coupons.manage`, store assignments and effective DENY.
- UI: **Reuse** the existing `/admin/coupons` dashboard page and its create/edit/delete actions. No new route, Sidebar entry, UI control, permission, role, master-data dropdown, or policy.
- Direct routes: `POST /admin/coupons` (422 duplicate code, 422 discount >100), `DELETE /admin/coupons/{coupon}` (409 if used). Existing `web` and `management.dashboard` middleware remain authoritative. Unauthorized actors receive 403 as before; entity scope decisions are unchanged.
- Locale: the existing shared guarded `authorizeAccess()` chooses `ar` or `en` from authenticated actor on every GET/POST/PATCH/DELETE request (Arabic fallback), instead of relying on the index GET to initialize locale. Existing paired `backend/lang/{ar,en}/coupons.php` keys render 409 and 422 business failures.
- States: used coupon cannot be deleted; duplicate scoped code cannot be created; percentage >100 cannot be persisted. No partial coupon/audit writes, no status-code changes, no permissions granted, no exception secrets displayed. The localized response is returned to the existing form or JSON consumer.
- AR/EN RTL/LTR: error strings follow existing selected language; existing dashboard RTL/LTR and responsive rendering are unchanged. No new visual element to audit.

## Regression evidence

`backend/tests/Feature/V2CouponMutationLocalizationTest.php` runs a real authenticated `RefreshDatabase` coupon HTTP flow with `CoreReferenceSeeder`:

1. For both Arabic and English Super Admins, used coupon DELETE returns 409 with correct locale.
2. Duplicate code and percentage >100 creation both return 422 with correct field-level localized messages.
3. Coupon rows and audit count remain unchanged by all rejected operations.
4. A roleless actor's direct DELETE returns 403 and cannot delete even an unused coupon.

Run focused tests and Pint/PHPStan plus mandatory current-head `Repository Policy`, `FOODEX V2 Isolation Gate`, full `FOODEX Required CI Gate`, Laravel, AR/EN and MySQL/Redis acceptance before merging. Until actual exact-final-head CI and technical audit pass, source status is **NOT VERIFIED FOR MERGE**.

## Surface impact

- **Backend:** affected, existing guarded Dashboard mutation controller and pair of language catalogues.
- **Dashboard:** affected only in existing coupon error text; no new screen, route, placement, interaction, or permission.
- **Customer:** not affected — no checkout, coupon public API, DTO or Flutter file change.
- **Driver:** not affected — no Driver backend/API/Flutter change.
- **Van:** not affected — no Van backend/API/Flutter change. B2B=VAN ONLY and B2C=DRIVER ONLY remain intact.
- No schema migration, dependencies, Setup ZIP, Update ZIP, release, production or `main` modification.

## Review checklist

- [ ] Verify all final-head focused tests and the terminal Required CI child jobs.
- [ ] Audit changed files for locale correctness, 403/404/409/422 contract stability, no store/tenant widening and no unintended writes.
- [ ] Confirm PR base `Foodex_V2`, SHA, no active conflicting worker and reviewed file fence before integration.
- [ ] Only after verified merge: mark atomic #3695 `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` and close it; keep master #2503 final UAT open.
