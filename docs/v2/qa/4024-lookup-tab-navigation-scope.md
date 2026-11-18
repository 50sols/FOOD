# V2 #4024 — Catalog/System Lookup existing PAGE_TAB context and malformed selector

## Placement / source and ownership
- Parent #2750 G009 / #2503. Original leaf #4024. `Foodex_V2` initial HEAD `1eacd4a925802e22abd36a67d4d8712b60496449` only; no `main`, tags, releases or production.
- **Reuse existing routes**: `GET /admin/lookups` Brands/Units (Catalog & Pricing) and `GET /admin/operations/lookups` five System Lookup types including Pricing Tiers (Administration/Master Data). Both already had sibling navigational tabs, not seven stacked concurrent sections.
- Actors: existing `lookups.view` roles, store/channel scope, support-access policy and `lookups.manage` unchanged; no entitlement/readiness or new role.
- Goals: compare/maintain reference values under identical visible Store/search/status/scope and navigate by native links. Filter preservation is navigation context only, **not** permission authorization.
- Pattern: existing PAGE_TAB rail; independent tab selection is a GET, while Add/Edit, pricing-tier definition, product editor, discount/margin overrides and Preview→Publish remain their original cohesive forms/workflows and **must not be split into pseudo-wizard tabs**.
- Mobile AR/RTL + EN/LTR: min 44px native anchors, one-row horizontal overflow within nav, explicit accessible `aria-current=page` on one tab, visible keyboard focus. Browser viewport/screenreader UAT NOT EXECUTED; #2750 owns final manual acceptance.

## Source review (HIGH risk due to scope/pricing adjacency)
| File | Scope | Non-goal |
| --- | --- | --- |
| `LookupManagementController.php` | Strict raw GET `type` string gate before existing two-value allowlist; legacy unknown scalar still defaults to Brands | no lookup query/store/POST or authorization changes |
| `SystemLookupController.php` | Strict raw GET `type` string gate before existing five-value `typeKey()` allowlist; unknown scalar remains HTTP 404 | no Price Tier model/priority/POST/policy change |
| `lookup-management.blade.php` | Existing two links carry server-normalized `q,status,scope,store_id`; never page cursors / `support_access`; one current link; contained horizontal/44px/focus | no new Sidebar or create/update form changes |
| `system-lookups.blade.php` | Existing five links carry server-normalized `q,status` only; one current link; same contained horizontal accessibility | no pricing calculations, data edits, or new page |
| `backend/lang/ar/lookup_tabs.php` and `backend/lang/en/lookup_tabs.php` | New bilingual labels for changed tab links; no inline language branches in modified navigation lines | no change to pricing/value labels outside existing tabs |
| `V2LookupTabNavigationScopeTest.php` | New isolated Laravel HTTP regression: bilingual valid 2+5 tabs, query parsing, malicious nested `type[]` HTTP 404, foreign Retail store HTTP 404, roleless HTTP 403, read-only row/audit counts, legacy unknown scalar behavior | no shared source test takeover |
- Existing Catalog Products six-tab conversion #3950/PR #3971 is merged and untouched. `CommercialDashboardController.php` and its Blade are owned by #2559/PR #3326; Wholesale pricing and B2B mode owned by #2706/PR #3995. Neither updated here.
- No public API, Flutter/Customer/Driver/Van source changed; deployed API compatibility and B2B=VAN ONLY / B2C=DRIVER ONLY remain unchanged.

## CI and acceptance
- `REVIEW_RISK: HIGH` because one tab includes price tiers and the other is store-bound; audit must confirm GET-only source diff, no route/controller mutation, same query and financial source authority.
- **TECHNICAL_REVIEW: PENDING_EXACT_HEAD_CI** until all applicable Repository Policy, V2 Isolation, Laravel positive/negative HTTP, PHP syntax/Pint, AR/EN, MySQL and full Required CI succeed on the exact final PR commit.
- This file describes the intended source-level contract, **not a claim that automated tests or live browser acceptance passed**. If any CI gate fails, keep original PR DRAFT and repair on original branch, without bypassing required checks.
- Full product/commercial pricing/discount/overrides/Draft→Publish operational acceptance remains tracked by original owners; do not close #2706/#2559/#2750/#2503 from this UI leaf.
