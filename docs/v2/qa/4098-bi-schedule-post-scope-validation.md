# V2 #4098 — BI schedule POST scope-before-payload validation

- Integration target `Foodex_V2` ONLY. Parent #2818/#2782/#2503; distinct from completed #4094 PATCH and #3958 BI authority scope.
- Existing `admin.erp.bi.schedules.store` POST route and request field schema, UI, report registry, schedule construction and scheduling idempotency remain unchanged.
- The first validation pass handles **only the explicit authorization selector** `report`, `scope_type`, `scope_id`. Server reuses the report registry, `OperationalTenantScope` store/legal-entity checks and `ReportAccessResolver::allowsExport` to deny a foreign store **before** dates/measures/interval fields are inspected.
- The canonical full validation schema still follows the same checks, so authorized malformed fields produce 422 and authorized valid schedule persists normally; invalid selector inputs remain validation errors.
- Authenticated seeded Laravel tests cover Store A BUSINESS_ANALYST assigned to Store A with Store B *independently entitled* but not assigned, foreign valid/invalid POST 403 in Arabic and English, and zero schedule writes; authorized malformed field 422 and valid schedule creation.
- Threats: validation side channel on unowned tenant, accidental privilege grant, unauthorized writes, AR/EN request handling. No migrations, report-scope widening or auth scope bypass; deny uses existing policy. `REVIEW_RISK: HIGH`.
- Customer, Driver, Van: **NO_DIRECT_IMPACT**; no Flutter, API routes, mobile response shape, dispatch or fulfillment changes. B2B VAN ONLY and B2C DRIVER ONLY unaffected.
- No tags, main, release, deployment, production, installer/update package or unrelated changes. Full relevant GitHub Policy/Isolation/Laravel/MySQL/AR-EN and Required CI exact-head mandatory before merge/closure.

**Test evidence / review:** to be appended as exact-head CI/review in PR and issue.
