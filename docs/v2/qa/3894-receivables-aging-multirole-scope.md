# FOODEX V2 #3894 — P288 Receivables aging multi-role HTTP authority

**Parents:** source #2618 (P288 أعمار الديون), V2 master #2503. **Isolated integration:** `Foodex_V2` only. This is a deliberately bounded **test-only** final negative/positive coverage increment; original P288 [PR #3085](https://github.com/walidatiyaai2025-gif/FOOD/pull/3085) and complete 126-document pagination child [#3217 / PR #3218](https://github.com/walidatiyaai2025-gif/FOOD/pull/3218) are already merged and are not duplicated or modified.

## Exact source gap and canonical placement

P288's Issue acceptance explicitly includes multi-role allow/deny. Existing P288 feature tests prove authorized single store role, explicit store deny, cross-store 404, legal entity AR entitlement and full unbounded bucket-currency aging. Generic Sidebar multi-role tests do not prove **real Finance Workbench Receivables HTTP** for one person with two distinct, store-scoped Finance-capable role assignments.

- Domain: Finance & Treasury → Accounts Receivable → Aging.
- Entity: posted AR documents, existing aging summary, independent 25-item detail paginator.
- Actor: one active Retail staff user assigned `B2C_STORE_ADMIN` at Retail Store A and `RETAIL_FINANCE` at Store B.
- Placement: **Reuse** existing authorized `admin.erp.finance.index?tab=receivables&store_id=...` tab. No second Sidebar, route, backend access policy or screen.
- Authority: `TenantContextResolver` exact Retail store; `finance.view` scoped role grants, Finance lifecycle ENABLED and operational version certification, legally scoped `finance.foundation.read` and `finance.ar.read` entitlement; explicit user DENY must take priority at only the matching store.

## Automated acceptance and failure modes

`backend/tests/Feature/V2ReceivablesAgingMultiRoleScopeTest.php` uses `RefreshDatabase`, canonical `CoreReferenceSeeder`, synthetic A/B/C stores with the **same legal entity**, an actor with two different store-scoped roles (no C role), role permissions granted explicitly in this isolated test fixture exactly as the existing P288 route regression does, and active Finance entitlement.

For **Arabic RTL and English LTR**:

1. Verify Finance is operationally **READY** and required AR read entitlement exists; absence of module certification is not a false-positive reason for denied HTTP.
2. Store A and Store B both expose the **real Receivables Aging tab over authenticated HTTP 200**, including selected locale and text. Unassigned Store C remains HTTP **404**, even though the shared legal entity has Finance entitlement.
3. Insert a **scoped** `finance.view` deny for Store A. Current implementation gives Store A **HTTP 404** (denied context is removed from visible store choices) while separately permitted Store B stays **HTTP 200**; unassigned Store C also stays **404**. Original #3894 acceptance demands **403 for assigned-denied Store A**, and that exact status requirement is **NOT MET**. No global permission, role expansion or real ledger mutation occurs.
4. Verify no AR document, general ledger or finance journal mutation; existing user role assignments stay intact.

The test does **not** replace #3217's 126-document SQL, posted allocation or currency isolation regressions. It does not claim to test live production persons, an exported historical identity or real browser/staging UAT.

## Verified acceptance blocker — HTTP 403 versus 404

- On isolated GCC, `php -d extension=pdo_sqlite -d memory_limit=1024M vendor/bin/phpunit tests/Feature/V2ReceivablesAgingMultiRoleScopeTest.php` passed **1 test / 33 assertions** after aligning `OrganizationHierarchyService::scopeForStore()`'s array contract; changed PHP file passed Laravel Pint. The local test key is disposable and is never committed.
- `FinanceWorkbenchController::index()` first filters `contextOptions()` through `FinanceWorkbenchAccess::canRead()`. With two store roles, a scoped `finance.view` DENY at Store A removes only A from the choices. A subsequent explicit selection resolves to **404**, not the Issue's required **403**. This is not the same as a one-store actor, whose fully denied context produces **403**.
- Correct source acceptance requires **403 for a genuinely assigned-but-denied store**, **404 for unassigned Store C**, **200 for independently authorized Store B**, AR/EN and no mutation. Any coordinated fix must preserve no cross-store disclosure and the existing Finance/Sidebar permission projection.
- Shared `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php` is modified in active DRAFT PRs **#3309** and **#3212**. This worker owns **only the original test and QA document** and must not edit or override their shared source. Coordinate a source-safe change through the proper owner/handoff before restoring the strict `assertForbidden()` expectation.
- **STATUS: SOURCE_INCOMPLETE / DRAFT / NO_MERGE / NO_CLOSURE** regardless of green isolated test or GitHub CI. Parent #2618 and master #2503 remain open.

## Surface classification

- **Backend/Dashboard:** focused regression of existing server-authorized Finance tab; no runtime code, route, HTML, schema, permission defaults or localization catalog changes.
- **Customer:** no mobile/API/checkout/credit/invoice change.
- **Driver:** no mobile/API/B2C dispatch/collection change.
- **Van:** no mobile/API/B2B trip/collection change. B2B remains VAN ONLY; B2C remains DRIVER ONLY.

## Review and merge protocol

**REVIEW_RISK: HIGH** for finance tenant-scoped RBAC, even though test-only. File-by-file audit checks three-store legal-entity fixture, scoped role grants, independent 403/404/200 outcomes, AR/EN, module readiness, and no-write financial assertions. Same-account `COMMENTED` engineering audit is not independent GitHub `APPROVED`.

Must see exact final PR HEAD Repository Policy, FOODEX V2 Isolation and terminal FOODEX Required CI SUCCESS (Laravel/PHP/Pint/PHPStan/security/OpenAPI, AR/EN, MySQL/Redis fresh installation/upgrade/recovery as applicable). If red, fix SAME branch/PR, no gate bypass. Parent #2618 may be considered code-complete only after original and child scope acceptance is fully rechecked. Human/staging/mobile-web accessibility acceptance remains deferred under #2503, not fabricated.

No main merge, tags, release, APK/ZIP publication, cPanel or production deployment.
