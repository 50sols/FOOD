# V2 #3733 — Smart Ordering merchandising cross-restaurant validation AR/EN

- **Source:** `docs/quality/LOCALIZATION_CONTRACT.md` and existing `SmartOrderingMerchandisingAdminController` store-bound scope checks.
- **Integration:** `Foodex_V2` ONLY. Never main, release, tag, APK publishing, cPanel or production.
- **Actor and entry:** authenticated B2C restaurant store administrator, `POST admin.smart-ordering.merchandising.offers.store` and `POST admin.smart-ordering.merchandising.recommendations.store`. Existing `RestaurantSaasStoreAccess::assertStore` enforces exact store/permission/channel and entitlements; no changes to that authority.
- **Scope:** four existing `ValidationException` human-facing errors for promotion, coupon, product and branch references are resolved from four AR/EN keys, maintaining each original validation field and 422 JSON response. English copy is unchanged.
- **Negative regression:** real `RefreshDatabase` + reference-seeded authenticated HTTP rejects nonexistent/foreign-scope values with exact locale, 422 field errors and no offer/recommendation writes. The authenticated cross-store offer mutation stays 404 with no extra write.
- **Positive regression:** valid unscoped reference offer persists and responds 201 in both locales, proving error localization did not break successful CRUD.
- **Security and failures:** no route/auth/role/tenant/scope/entitlement, SQL predicates, payload schema, money calculation, secrets, audit, session, migration or delivery changes. Reject without side effects; do not suppress errors.
- **Dashboard:** authenticated restaurant merchandising API messages only; no Blade layout or visible UI component modified. Existing AR RTL / EN LTR and accessible rendering stay unchanged; only message text varies.
- **Customer app:** NOT AFFECTED — no Flutter or public Customer API/DTO changes.
- **Driver app:** NOT AFFECTED — no Flutter/dispatch/notification changes.
- **Van app:** NOT AFFECTED — no Flutter/wholesale/route changes; B2B VAN-only and B2C DRIVER-only invariant intact.
- **Review risk:** HIGH because messages sit on tenant-scope guards. Record a separate file-by-file engineering audit; a same-account COMMENTED review is not independent APPROVED.
- **Acceptance:** run focused and complete backend tests, Pint, PHPStan, security, AR/EN catalog parity, MySQL/Redis acceptance and terminal exact-head Repository Policy, V2 Isolation and Required CI before any `Foodex_V2`-only PR merge. Final staging/manual UAT tracked by #2503; neither this doc nor a green build is a production certification.
