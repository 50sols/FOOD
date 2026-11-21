# V2 #3685 — Field Operations Dashboard Address Quality literal review search

**Scope:** independent atomic child of #2818 / #2503 and P266 #2596 (already closed); existing API-only sibling #3561 previously merged. Integration ONLY `Foodex_V2`. No main, production, release, tag, APK distribution or cPanel.

## Confirmed source discrepancy

`FieldOperationsController::addressQuality` used implicit `LIKE` and escaped only `%` / `_` with backslashes. SQLite does not recognize backslash as a default LIKE escape, and MySQL/MariaDB semantics depend on SQL mode. Dashboard search could match unintended authorized rows or miss legacy punctuation-bearing public IDs, territory codes or review reasons. This is **not** evidence of SQL injection or proof of new cross-tenant disclosure. The related `AddressQualityController::index` API route was already fixed under #3561; neither API nor mobile clients are touched here.

## Implemented reuse and authoritative guard

- Reuse existing server-rendered `GET /admin/field-operations/address-quality`, without a new route, menu, permissions, table or UI translation.
- Preserve existing `customers.view` authorization, `status` validation, 25-row pagination, review events/territory display and AR/EN accessible UI. Filter only on nonempty trimmed q, max 150.
- Reuse existing `LiteralLikePattern::contains` with explicit `ESCAPE '!'`, bound values and fixed `public_id`, `territory_key`, `reason` columns, grouped OR beneath status predicate. Typed !, %, _ and backslash remain literal. No schema or write-path changes.

## Real Laravel regression

`backend/tests/Feature/FieldOperationsUiFoundationTest.php::test_address_quality_dashboard_search_treats_symbols_as_literal_under_existing_permissions`

- `RefreshDatabase` and `CoreReferenceSeeder`; authenticated Super Admin and permissionless denied user.
- Synthetic reason, territory and public ID fields with `%`, `_`, `!` and backslash; ordinary decoy; positive and negative HTML table visibility.
- AR and EN GET rendering, status filter composition, whitespace-only search compatibility, direct 403 negative, and no review-row count changes.

## Cross-surface and security audit

| Surface | Impact |
| --- | --- |
| Laravel Backend/Dashboard | Affected: one read-only Address Quality review filter |
| Admin API | Unchanged, API sibling #3561 already complete |
| Customer | Unchanged API and Flutter; no new issue needed |
| Driver | Unchanged B2C dispatch/status/wallet and Flutter |
| Van | Unchanged B2B visits/trips/territory and Flutter |

B2B VAN ONLY and B2C DRIVER ONLY unchanged. No changes to AR/EN copy, RTL/LTR markup, sidebar, UI layout, role/permission grants, migrations or audit writes.

**REVIEW_RISK: HIGH** (PII-bearing review read). Review source/tests for query binding, denial, unexpected broadening and failure behavior. This is a bounded filter-correctness fix and DOES NOT certify pre-existing cross-tenant review-list scope. That separate authority remains subject to #2782/#2818; do not misrepresent unchanged authorization as a new security guarantee. Existing #2606 / PR #3311 edits the same controller **only in `visits()`** around lines 595–750; this change owns **only `addressQuality()`** around 1050. Reconcile hunk-level ownership and base before merging.

Exact final-head Repository Policy, V2 Isolation, Required CI (Laravel, Pint, PHPStan, security, AR/EN, MySQL/Redis, terminal required-ci-gate) must be successful before merging this atomic issue to Foodex_V2. Close this leaf only after verified integration; final staging/human acceptance deferred under #2503.