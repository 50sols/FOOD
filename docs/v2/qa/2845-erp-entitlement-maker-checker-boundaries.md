# V2 #2845 / P111 — ERP entitlement and independent checker HTTP boundary

## Scope and route authority

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P111; parent #2503. Integration target `Foodex_V2` only. This change repairs one demonstrated Procurement ERP entry-point gap; it is **not** full P111 certification across every ERP module.

- **Business domain:** ERP / Procurement, purchase requests and approvals. **Workflow:** buyer creates/submits, independent procurement approver decides.
- **Placement:** reuse the existing Procurement workbench (`admin.erp.procurement.index`, Requests tab), plus its current POST actions. No new Sidebar, route, screen, module toggle, or decorative UI was introduced.
- **Server authorization:** `EnterpriseProcurementController::context()` is shared by the workbench and all Procurement HTTP mutations. When a Procurement entitlement policy has been configured (any Procurement entitlement row), it requires `ModuleEntitlementResolver::allows('procurement', store scope)` before returning the resolved context. This respects module lifecycle and the narrowest explicit entitlement, including store DENY beneath platform ALLOW. A legacy deployment with **zero Procurement entitlement rows** continues to use its existing module lifecycle, role, tenant/channel and SoD authorization; it is not silently declared entitlement-certified. The readiness middleware continues to require operational certification. Absence of a matching entitlement **after** module policy enrollment remains fail-closed.
- **Roles:** global `PROCUREMENT_BUYER` and `PROCUREMENT_APPROVER`, dual-role maker as an adverse case, and `SUPER_ADMIN` as control plane. Neither a global role nor Super Admin can override explicit store entitlement revocation for these routes.
- **Data and failure paths:** original submitted request persists unchanged on denial; no approval event is written; the independent checker can approve only after explicit store re-entitlement. The same maker is rejected even after receiving the checker role.

## Executable acceptance

`backend/tests/Feature/V2ErpEntitlementCheckerBoundaryTest.php` uses a seeded authenticated HTTP workflow and a real persisted request. It tests:

1. Lifecycle-enabled, platform-entitled, explicitly operationally certified Procurement with an existing buyer-submitted purchase request.
2. Store-level entitlement revocation after submit; Arabic and English HTTP GET and approval POST return 403 for checker, maker and Super Admin as applicable, and no approval transition/audit occurs.
3. Restoration of the same store entitlement and dual-role maker self-approval rejection with a localized validation error; the separate checker succeeds once with a persisted approval audit.
4. A separate active B2B sibling store remains authorized when the canonical Wholesale store has an explicit store-level DENY, including Arabic/English HTTP reads and a persisted positive sibling mutation. The denied store's identical mutation returns HTTP 403 with no new purchase request. This proves the global buyer role cannot bypass a per-store DENY without incorrectly blocking another entitled store.
5. The sibling is a test-only B2B store created under the existing organization hierarchy; no Retail B2C role, cross-store grant or live tenant data is introduced.

**Product parity:** Backend/Dashboard authorized Procurement HTTP behavior is impacted; Customer app public API and Flutter files are unchanged (no customer quote/order/credit or public endpoint change). Driver app unchanged (no B2C dispatch, assignments, collections or runtime endpoint change). Van app unchanged (no B2B visit, delivery, territory or dispatch API change). B2B = Van only and B2C = Driver only are preserved.

**First exact-head CI iteration:** Repository Policy, V2 Isolation, Localization, fast-fail and MySQL/Redis install/upgrade/recovery succeeded on `3e38fee8`; full Laravel tests reported 6 legacy procurement no-entitlement fixture failures (3063 passed). The subsequent compatibility guard fixes the uncontrolled pre-enrollment behavior without weakening explicit DENY; recheck all CI on the latest commit before claiming success. The test fixtures changed by active #2858 were deliberately not edited.

**Local validation:** GitHub Connector-only code edit environment does not include the checked-out repository with `vendor/`, DB and full worker bootstrap; `scripts/worker-preflight.sh --ci-parity`, Pint, PHPStan and Laravel/MySQL tests are not claimed locally. Exact branch-head GitHub Actions and its required child jobs must be verified before merge.

## Remaining P111 source work — do not close issue from this bounded fix

The P111 requirement also demands a complete ERP module/role/company/branch/legal-entity/store entitlement matrix and maker/checker SoD enforcement across Finance, Treasury, WMS, Procurement, and their related UI/HTTP entry points, with disabled-module, multi-role, cross-channel, audit and appropriate AR/EN/accessibility coverage. `#2858` / draft PR `#3065` separately owns operational readiness evidence; `#2695` and `#2699` own specific Finance/Treasury role security. Those are not certified or altered here. Do not merge conflicting changes to their files. Add source-exhaustive acceptance and complete remaining scoped work before closing #2845. Manual staging/browser/device UAT is deferred under the latest #2503 decision, but missing source implementation is **not** deferred.

**Prohibited:** No `main` changes, release, tag, production deployment, tenant-data alteration, or silent privilege grant.

## 2026-11-17 — Direct HTTP authority ordering, original #3206

**Issue-only scope / no readiness bypass.** The original #2858/P135 exact-head Required CI #44072825443 (backend job #134005147770) reports `ErpCenterTest.php:853` direct Requests GET after revocation **expected 403, got 200**, and `ErpCenterTest.php:1079` forged Retail Supplier GET with GLOBAL DENY **expected 403, got 409**. This is **not** evidence that #3206's previously green build reproduced those failures: #3206's controller already enforces selected-store module entitlements but is **unmerged**, so P135's independent PR build still exercises the older `Foodex_V2` controller.

**Source diagnosis and change:**
- P111's existing `context()` rejects any configured STORE entitlement DENY before tenant context resolution for both GET and mutation; no platform fallback when a single STORE grant is revoked. The preexisting P111 tests cover platform ALLOW + STORE DENY and cross-store sibling ALLOW.
- A global Wholesale buyer can also hold an unrelated `RETAIL_FINANCE` store role, making that active Retail store appear in the *tenant candidate options*. Selection alone was incorrectly followed by `TenantContextResolver::retail()` and then the `enterpriseScope === null` setup guard, which responded **409** when the actor actually held **zero Procurement permissions in that Retail store**. A setup conflict must not be the first verdict for an unauthorized action.
- `EnterpriseProcurementController::context()` now checks that the selected store has at least one **effective Procurement action permission**, derived from the existing `User::effectivePermissionCodes(storeId)` and excluding unrelated Finance-only rights, **before** it resolves any tenant hierarchy. Missing authority is **403**; only a genuinely authorized scope may reach the existing 409 setup diagnostic. Super Admin retains the existing audited support context, not a new grant; downstream per-tab/per-action `hasPermission`, entitlement, source readiness middleware, store/channel and maker/checker gates are unchanged.
- This early relevance check does not make all Procurement tabs readable. A buyer with separately allowed Requests still gets that GET, while a `procurement.suppliers.view` global DENY cannot be overridden by STORE ALLOW and the direct Suppliers route remains 403. No business calculation or data write changed.

**New source tests in the existing P111 file:**
1. `test_store_only_entitlement_revocation_denies_direct_requests_read_and_mutation_without_platform_fallback`: actual B2B authenticated Arabic/English Requests GET succeeds on the sole STORE grant; after revocation both direct GET and POST create return 403, no purchase request or audit is written, and a phantom platform grant never exists.
2. `test_global_supplier_deny_and_unrelated_retail_assignment_reject_direct_http_before_setup_conflict`: real seeded Wholesale buyer + unrelated Retail Finance role + B2B allowed Requests + GLOBAL Supplier DENY versus STORE ALLOW; direct Suppliers GET/POST and forged Retail GET return 403 (not 409), distinct B2B Requests 200 AR/EN; no forged supplier or audit mutation.
3. `SyntheticErpWorkflowEvidence::install()` is used in PHPUnit setup only to model positive signed evidence when PR #2858's future readiness validator is present; production `certified_versions`, trust keys and attestations remain untouched and unapproved.

**CI truth:** Previous original #3206 HEAD `cec76fe4` had Required #44022523691, Policy #44022523712 and V2 Isolation #44022523666 SUCCESS, but those results do **not** cover these new commits. Recheck new exact-head required workflows and review risk HIGH before any merger. Original #2845 remains OPEN; #2858/#3065 owns the separate workflow-certification tests, and only an integrated exact-head build can discharge its current 200/409 regressions. Neither local runtime, real tenant data nor browser UAT has been tested. No main/tags/release/deployment/production.
