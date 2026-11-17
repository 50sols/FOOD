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


## 2026-11-17 — P111 unified Procurement admission / 403–409 source contract

This change is strictly in **original Issue #2845 / original PR #3206 / branch `test/2845-v2-erp-entitlement-maker-checker-boundaries`**; branch merges only to `Foodex_V2`. Current authorized code/test commits: `2834930e105cea33df7022a7eb17235cc8ce295d` (Controller), `dcc964cf23e60aa26e349fd0969189b4260ca3a7` and `e81cd9db6a7fdbbc2a8707d14a7347e214df39b9` (HTTP tests).

### Existing authoritative gates composed, not bypassed

1. **Identity:** `EnsureManagementDashboardAccess` and P111 `EnterpriseProcurementController::actor` deny inactive users with HTTP 403; `User::hasPermission` already handles active Super Admin, effective roles, scoped permission, and GLOBAL/STORE explicit DENY. The Controller no longer repeats an unchecked `hasRole('SUPER_ADMIN') ||` bypass in tab/action projections.
2. **Tenant/store:** `TenantContextResolver::wholesale/retail` validates active B2B global role/channel or B2C assigned store/support context. P111 `context()` starts from actual candidate stores, filters using effective `procurement.*` permission and the **selected STORE entitlement**, and never accepts an explicit `store_id` outside the eligible set. An explicit zero, negative, nonnumeric, inaccessible, inactive or cross-channel selector yields 403 rather than silently using a default store. When no selector is present the default and visible store choices come from the same eligible set.
3. **Module:** existing `ModuleRegistry` ENABLED gate and `EnsureErpOperationalReadiness` enforce exact module state/operational readiness for HTTP. A mere registered route, sidebar item, ENABLED flag, synthetic PHPUnit evidence or healthy probe never replaces a reviewed P135 certificate. No production readiness/config edits.
4. **Entitlement:** `ModuleEntitlementResolver::allows(procurement, STORE)` enforces lineage with narrowest explicit STORE DENY over PLATFORM ALLOW. The pre-existing **zero-policy legacy compatibility contract** remains only while no Procurement enrollment row exists; once policies exist, missing/revoked STORE coverage denies requests. No Finance/AP capability or maker/checker exception is introduced.
5. **Business write/read scope:** `SupplierDirectory` and `PurchaseRequestManager` already use `EnterpriseScopeAuthorizer` and `EnterpriseDataScope` to authorize exact action, organization/company/store visibility and IDOR protection. `request/approval/PO/GRN/invoice` services own their own transaction/SoD checks. P111 does not rewrite peer-owned P135/P110 services. The existing Sidebar is **a projection only**, never a grant.

### HTTP outcome contract

| Input / state | Expected | Owner and rationale |
|---|---|---|
| Unauthenticated | 302 to existing management login / 401 when applicable | Existing authentication middleware; never authorize |
| Authenticated inactive user or unauthorized action/role/tenant/store/channel | **403** | Identity/RBAC/tenant/context |
| Explicit malformed/zero/negative/forged `store_id` | **403** | No fallback to a different store |
| Revoked STORE or explicit STORE DENY, even with Platform ALLOW | **403** | ModuleEntitlementResolver selected STORE |
| Disabled/uncertified/unhealthy module entry | **403** operational route | Existing readiness middleware; no fake certificate |
| Authorized actor + entitled selected store, but genuinely missing `EnterpriseDataScope` hierarchy | **409** | Configuration fault *after* authorization; 409 is not a security-denial success |
| Fully authorized/ready GET | **200** | Read-only; no match/audit changes |
| Fully authorized maker POST | Existing workflow 302 / documented business status | Canonical manager, idempotency and SoD enforced separately |

### Real Laravel HTTP regression suite

The original `backend/tests/Feature/V2ErpEntitlementCheckerBoundaryTest.php` retains all existing global permission DENY, Store DENY, sibling B2B, Retail forged supplier, positive maker/independent-checker, historical invoice read-only, readiness/module health negative, AR/EN RTL/LTR cases **without relaxing asserted 403**. It now adds:

- `test_forged_nonpositive_or_malformed_store_id_never_falls_back_to_an_authorized_store`: production-like CSRF session/header, valid GET first, malformed/zero/negative GET+POST `403` across AR/EN, zero request/audit mutation.
- `test_default_procurement_store_picker_excludes_store_deny_and_uses_entitled_sibling`: Platform ALLOW + primary B2B STORE DENY, only sibling B2B appears as selectable default and GET `200`; direct denied store GET `403`.
- `test_deactivated_super_admin_cannot_read_or_mutate_procurement_even_with_historical_role`: authenticated historical SUPER_ADMIN role cannot execute GET/POST after deactivation, with no request creation.
- `test_authorized_b2b_store_without_enterprise_hierarchy_returns_409_after_authority_checks`: valid B2B global role+Platform entitlement+readiness, store lacks company hierarchy: AR/EN GET+POST `409`, no request/audit write; separate valid configured store GET `200`.

The earlier `test_missing_store_entitlement_and_missing_required_certification_cannot_be_bypassed_by_reenable` incorrectly compared audit counts across an **authorized `ModuleEntitlementManager::set` grant**, which itself writes a legitimate audit row. It now captures a fresh audit baseline *after* that grant, while preserving strict no-audit assertions around unauthorized GET/POST and later read-only requests. This is not an authorization waiver.

### Merge and ownership constraints

P135 #2858 / PR #3065 actively owns `ErpCenterController.php`, `AdminNavigation.php`, module workflow readiness/certification and its own suites. P110 #2844 / PR #3921 owns legal stock title; #3960 / PR #3961 owns existing ERP tabs. **None of those files are modified here.** Two pre-existing exact-head failures remain in P111 tests until a peer-approved source integration: Super Admin diagnostic Sidebar expectation vs real navigation, and Store DENY actual HTTP 403 vs ERP Center card `available` instead of `not_entitled`. The P135 branch's historical 403→200 and 403→409 failures require combining accepted P111 Controller security in the original branch integration, not weakening tests. C13 Customer and Operational #734 integrated required jobs also have separate owners.

`SOURCE_COMPLETE:NO`, `REVIEW_RISK:HIGH`, `TECHNICAL_REVIEW: NOT PASS`, `NOT_CERTIFIED`. No actual local PHP runtime executed through GitHub Connector, and no claim of CI green on these commits before terminal exact-head checks. No merge/close/main/tag/release/deployment/production.


## 2026-11-18 — P111 concrete role assignment, B2B/B2C and maker/checker HTTP verification

**Ownership:** original #2845 / original #3206; `test/2845-v2-erp-entitlement-maker-checker-boundaries` -> `Foodex_V2` exclusively. Continued same worker `gpt6-p111-unified-auth-ci-rescue-20261117` [claim](https://github.com/walidatiyaai2025-gif/FOOD/issues/2845#issuecomment-6960548674). This is regression implementation, NOT an operational certificate or a claim of green CI.

### Source-of-truth role/permission matrix

Source `backend/config/job_roles.php` (codes, scopes, module requirements, role grants), actually reconciled to `roles` and `permission_role` by `BuiltInJobRoleCatalog::reconcile()` and bound to the authentic principal via `role_user`. A role merely present in the administration catalog is never proof the principal possesses it.

| Actual active role assigned on global pivot | purchase_requests.manage | purchase_requests.approve | purchase_orders.issue | B2C global applicability |
|---|---|---|---|---|
| PROCUREMENT_BUYER | Yes | No | No | No |
| PROCUREMENT_APPROVER | No | Yes | Yes | No |
| PROCUREMENT_MANAGER | Yes | Yes, not self | Yes, subject to independent policy | No |
| No global `role_user` pivot | No | No | No | No |
| Mis-scoped Global Procurement role injected as a Retail store-role pivot | No | No | No | No |
| Archived/disabled role; inactive or archived user identity | No business HTTP access | No | No | No |

Actual `User::hasPermission` checks active role, B2B/B2C channel, configured `job_roles` module gates and explicit GLOBAL/STORE user DENY. `TenantContextResolver` checks active Wholesale global-role context vs assigned Retail store role and selected channel/store. `EnterpriseProcurementController::context` independently checks selected-store effective Procurement capability + actual `ModuleEntitlementResolver::allows(procurement, STORE)` and declines forged or unassigned `store_id` with 403 before Enterprise scope setup, while middleware `EnsureErpOperationalReadiness` denies uncertified modules. P111 Controller now explicitly rejects even an `is_active=true` identity with historical non-null `archived_at`: a retained audit pivot is not permission to transact. No changes to global `User.php` or peer-owned permission engine.

**New actual committed test source**: original `backend/tests/Feature/V2ErpEntitlementCheckerBoundaryTest.php`:
- `test_canonical_procurement_global_roles_require_live_account_pivot_and_actual_job_role_permissions`: asserts seeded **`permission_role` equals `job_roles` grants**, three active global database roles and **`role_user` exact actor link**, live B2B `hasRole`/`hasPermission` matrix, AR/EN authenticated GET 200, no global pivot => HTTP GET/POST 403 with zero data/audit mutation.
- `test_wholesale_global_role_and_retail_store_role_cannot_cross_channel_or_forge_assignment`: B2B A positive, B2B B explicit STORE DENY negative, Retail A assigned Finance store-role (but no Procurement), Retail B unassigned, Retail-only actor, and synthetic invalid store-role-pivot pointing to a GLOBAL Procurement role; AR/EN GET/POST 403 negative and no business/audit mutation. B2B owner-only global role remains exclusively B2B; neither Retail assignments nor `store_id` payload elevate authority.
- `test_inactive_archived_procurement_identity_and_archived_global_role_are_denied_with_audit_unchanged`: archived identity with retained role and still-active legacy identity flag, disabled/archived Global Procurement role with retained pivot, and separately deactivated Approver user; AR/EN GET/POST 403 and no business/audit writes.
- `test_multi_role_maker_checker_cannot_self_approve_or_issue_po_with_explicit_global_deny`: a real PROCUREMENT_MANAGER with additional BUYER+APPROVER actual global assignments creates/submits a request with a valid Supplier; self-approval denied and creates no approval audit; independent APPROVER 302 approves once and emits exactly one audit; manager creates draft PO, then an explicit GLOBAL DENY on `procurement.purchase_orders.issue` rejects its issuance (as does BUYER-only), leaves order `draft` and emits no issue audit; separate authorized APPROVER then issues once and emits exactly one issue audit. This asserts **no privilege expansion by role combinations, override ALLOW or changing store context**, and preserves canonical business services and SoD rules rather than adding new grants.

**Source commits:** archived-identity admission guard `3d7d175dc6ba75eae3a022e0972b5f2be9c65a95`; 4 Laravel HTTP matrix tests `8754342b3ca8e17c0c29bf057490769b36c60d97`; Pint method-spacing correction `2e15cfaae57407d111980127875fd44d4b4efdef`. No edits to P135 #2858's signed readiness, ERP Center, AdminNavigation, P110 #2844 or #3960 files; no middleware/CI disabling, `certified_versions`, production data or broad role grants.

**Outstanding owner-dependent acceptance:** (a) P135/P034 exact selected Wholesale STORE DENY UI card `available` vs authorized direct HTTP 403 remains a real source mismatch, do not suppress the P111 negative regression; (b) P135 original #3065's unintegrated original controller still needs P111 source reconciliation before declaring historical 403→200 and 403→409 resolved together; (c) full P111 matrix beyond Procurement/Finance/Treasury/WMS original owners; (d) all new tests **must be proven by exact-final-head GitHub Required CI**. `SOURCE_COMPLETE:NO / TECHNICAL_REVIEW:NOT PASS / REVIEW_RISK:HIGH / MERGE:BLOCKED`. No main, tag, release, deployment or production.


## 2026-11-18 — Canonical 12-tab Procurement navigation / HTTP GET acceptance

**Source of truth:** `EnterpriseProcurementController::TABS`, not route names, new Sidebar items or an independently maintained UI map. Exact order and inherited read authorities:

| Tab | Existing action-read permission |
|---|---|
| overview / suppliers | `procurement.suppliers.view` |
| requests / rfqs | `procurement.purchase_requests.view` |
| orders / contracts | `procurement.purchase_orders.view` |
| receipts | `procurement.goods_receipts.view` |
| invoices / matches | `procurement.purchase_invoices.view` |
| returns | `procurement.purchase_returns.view` |
| statements / reports | `procurement.supplier_statements.view` |

**Actual read-only source checks on P111 original PR #3206:** all 12 keys exactly match both `backend/lang/ar/enterprise_procurement.php` and `backend/lang/en/enterprise_procurement.php`. The existing `admin.erp.procurement.index` GET handles `?store_id=&tab=` and action-specific query filters; no new route or Sidebar item. `visibleTabs` derives access from `User::hasPermission`, while `authorizeTab` refuses visible-but-unpermitted real keys with 403 and `index` refuses unregistered tab keys with 404 (after higher-priority identity/readiness/store checks). The shared `foodex-tabs` horizontal pattern supports Arabic RTL and English LTR including contained scroll on small screens. Page access is not evidence of approval/posting/SoD/Finance readiness.

**Actual P111-owned source commits:**
- [`44443480`](https://github.com/walidatiyaai2025-gif/FOOD/commit/44443480dcbc7b73aa586bef344e48f0b3eacd79): existing `enterprise-procurement.blade.php` keeps one `<nav class="foodex-tabs erp-procurement-tabs" data-procurement-tabs>` with exactly one canonical link per permitted tab and `aria-current="page"` on active tab; no extra Sidebar/route. Fixes duplicate pagination: RFQ/Contract/Match render their **already-existing inline paginator** in `_enterprise-procurement-completion.blade.php`, while Suppliers/Requests/Orders/Receipts/Invoices/Returns use exactly one footer `data-procurement-footer-pagination`. Overview/Statements/Reports do not introduce a false general list paginator. `withQueryString()` remains service-authoritative, preserving `store_id,tab,q,status,page`. Additional [P111 view `a5428061`](https://github.com/walidatiyaai2025-gif/FOOD/commit/a54280616ce3ca7f33f1bb959034a3747993dd72) retains the last scalar `q/status/scope_type/page` in six existing exact-record links (Supplier/Request/Order/Receipt/Invoice/Return) while the canonical Clear and tab-change links intentionally reset list-specific filters. [P111 HTTP `c0dcd8e`](https://github.com/walidatiyaai2025-gif/FOOD/commit/c0dcd8eee10216e4bd0b877521bf1d38ad5ded75) asserts AR/EN filtered Purchase Request list-to-record deep link keeps `tab,store_id,q,status,page,request` and denies that same request from foreign B2B store with 404. Completion-partial RFQ and Contract deep-link presentation remains peer-owned and was not changed.
- [`89591bae`](https://github.com/walidatiyaai2025-gif/FOOD/commit/89591baea5c5c09ce5c6f6dd9ad1fe8cbbf713d4): existing P111 Laravel feature test adds **four real HTTP regression methods**: (a) authorized PROCUREMENT_MANAGER GET each of 12 tabs, AR/EN, RTL/LTR, canonical order, localized tab text, one rail + one active item + canonical `store_id/tab` tab deep link, no GET business audits; (b) authenticated GET with `q/status/page` for all nine list tabs checks values, paginator page-2 URL query and exactly-one pagination location, both locales; (c) existing permission GLOBAL DENY hides each denied tab link and returns 403 to direct tab URL, registered allowed tabs still GET 200, unknown tab 404, no audit writes; (d) an existing stored Purchase Request GET deep link returns exact record only in owning B2B Store A, not foreign B2B Store B (404) and no GET audit.
- [`e03634f`](https://github.com/walidatiyaai2025-gif/FOOD/commit/e03634f1a9368f872ae90483fd464f85bb1fe362): negative test correctly applies `job_roles.php`: BUYER **does not have** `procurement.purchase_returns.view`; Returns tab stays hidden and direct HTTP 403 even if sibling read tabs are allowed. **No broader role grants**.
- [`bf7df193`](https://github.com/walidatiyaai2025-gif/FOOD/commit/bf7df193c15d19329fdd58c89c8bc02a499c5604): test additionally verifies exactly one registered named `admin.erp.procurement.index` route, existing shared Sidebar exactly once, and one Procurement workbench region per GET.

**Peer-source reconciliation:** P135 #2858/PR #3065 independently owns `V2ErpProcurementTabRouteMatrixTest.php` (11 non-overview workflow tabs) and `V2ProcurementWorkbenchTabLayoutTest.php` (12 tabs, AR/EN, denied URLs, pagination and no duplicate navigation). Its branch also owns AR/EN `enterprise_procurement.php` translations and readiness; these files were **not modified** in P111. #3960/PR #3961 owns ERP Center horizontal tabs (a separate workspace), also untouched. The P135 layout test requires the exact tab rail and pagination attributes fixed above and proves why page-only source appearance cannot be called acceptance. Existing P111 ERP Center selected-store DENY vs card/link `available` mismatch remains cross-owner BLOCKED; do not remove that test or fabricate ready certification.

**Validation truth:** Github Connector edits are **committed test source, not a local PHPUnit execution**. Check Required CI, Laravel Full/fast-fail, MySQL/Redis, AR/EN, Policy and V2 Isolation on the exact final P111 HEAD after commits; preexisting C13/Operational integrated owner failures cannot be waived. `SOURCE_COMPLETE:NO`, `REVIEW_RISK:HIGH`, `TECHNICAL_REVIEW:NOT_PASS`, `MANUAL_ACCEPTANCE:DEFERRED_TO_V2_FINAL`, `MERGE:BLOCKED`, no main/tag/release/deploy/production.


## 2026-11-18 — Supplier legal/scope and Purchase Request source transaction matrix

**Original P111 #2845 / PR #3206:** All work stays on `test/2845-v2-erp-entitlement-maker-checker-boundaries`, based on `Foodex_V2`, no peer-owned backend files changed and no live/production DB/reseed. PHPUnit `RefreshDatabase` + `CoreReferenceSeeder` are **isolated test fixtures**, NOT a live setup, certification or real government registration verification.

### Committed code/test evidence

- [`0fbca1be`](https://github.com/walidatiyaai2025-gif/FOOD/commit/0fbca1be26a4de5ca67efdf363ac5bc7fe5416bf): existing P111 Feature suite adds `test_supplier_legal_identity_create_service_edit_and_http_archive_respect_company_store_scope_and_audit`. Actual authenticated POST supplier create in B2B Store A; proves organization/company/store and real actor IDs are server-derived, `name_ar`, `tax_registration_number`, `commercial_registration_number` and payment terms persist; genuine `SupplierDirectory::update` changes legal tax number and emits an audit; GET supplier Store A authorized AR/EN, Store B same-company but outside supplier **store** scope 404; HTTP foreign Store B archive with forged `company_id`/`user_id` 404 and zero audit; Store A archive sets inactive and records historical update audit. This is a persisted legal **identity record**, not an external tax/commercial-registry verification.
- [`357553e6`](https://github.com/walidatiyaai2025-gif/FOOD/commit/357553e66d1952b463239e75a33187838432c550): `test_purchase_request_http_create_submit_replay_and_forged_supplier_price_credit_actor_are_scoped_and_audited` creates two valid B2B stores and independent store-specific suppliers, then a real Buyer HTTP Purchase Request for Store A; validates active legal Supplier A, request `draft`, scoped company/organization/store and authenticated created/updated identity, persisted `item_code`, `unit`, **quantity 2.125** and **unit cost 123.456** in `decimal(14,3)`-style domain fields with currency `KWD`; forged `created_by`/`submitted_by`/`company_id`/`organization_id`/`unit_price`/`credit_limit`/`tax_amount` are not accepted as authoritative source; Store B supplier on Store A request 404; negative quantity/cost fail validation without writes; authenticated AR/EN GET shows real request. Submit `draft -> submitted` is replay-idempotent: two POSTs to the same request produce **one** submitted Audit event; extra client `status` and `submitted_by` cannot force approval or spoof maker. Explicit Store B Procurement entitlement DENY blocks forged Store B submit 403 and leaves Store A request/audit unchanged.
- [`b7038303`](https://github.com/walidatiyaai2025-gif/FOOD/commit/b7038303f6e364153e5bc743899b7e0255181d86): **small P111-owned source correction only** in `SupplierDirectory::update()`. After existing authorization, scope verification, per-supplier row lock and field validation, skip `save` and `AuditLogger` if no business field is dirty; `updated_by` changes only for actual supplier mutation. Replayed archive/edit by any otherwise authorized actor cannot generate a false duplicate success-audit or rewrite prior updater. No change to financial domain, roles, migrations, routes, peer P135 readiness or tenant policy.
- [`e25708c`](https://github.com/walidatiyaai2025-gif/FOOD/commit/e25708c260325a307c4e96b2bcdbb2db5774a33f): extends supplier test with two HTTP archive replays, stable `updated_by`, no new audit; reject duplicate supplier `code` even from Store B in same company, including after supplier archiving. Existing database has `enterprise_suppliers_company_code_unique` to enforce company uniqueness under concurrency.

### Explicit source gaps, untested acceptance and owners

| Acceptance item | Verified source state / owning dependency | Disposition |
|---|---|---|
| Supplier create | Existing scoped HTTP POST + service + company/code database unique; new P111 HTTP test source | **TEST_COMMITTED, CI_NOT_PROVEN** |
| Supplier edit | `SupplierDirectory::update` exists and is scoped/audited, but no canonical supplier-edit HTTP route/form beyond P135's governance-specific update; direct service test demonstrates safe source mutation only | **INCOMPLETE HTTP acceptance; Stage 3 #2707 + UI owner coordination needed** |
| Supplier archive | Existing HTTP POST and scoped update; P111 repairs no-op replay audit under lock | **SOURCE FIX COMMITTED, CI_NOT_PROVEN** |
| Purchase Request create/submit | Existing HTTP POST + `PurchaseRequestManager::create/submit`; submit has locked idempotent same-status return; decimal:3 fields, issuer derived from authenticated identity | **TEST_COMMITTED, CI_NOT_PROVEN** |
| Draft Purchase Request edit | `PurchaseRequestManager` has NO `updateDraft`/edit method, and Procurement controller/routes have NO canonical `requests.update` route or form; P111 owns neither missing service nor new route | **BLOCKED: Stage 3 #2707 source/branch/owner handoff required** |
| Duplicate Purchase Request CREATE (same payload/replay) | Every request create generates a new UUID/PR number; no idempotency key and uniqueness contract for repeated POST exists. Replayed `submit` is idempotent but **not the same thing** | **BLOCKED: Stage 3 #2707 canonical idempotency design/schema/HTTP test required** |
| Item/unit/price/credit/tax authority | `item_code` and `unit` are free strings, not authoritative Catalog/Product/Unit foreign-key validation; price is estimated caller input validated positive/three-decimal storage, **not** approved negotiated pricing or Finance ledger; supplier tax-registration is stored, while PR line VAT/tax jurisdiction/rounding data and credit ceilings are absent in request service | **BLOCKED: Stage 3 #2707 + actual Catalog/Finance owners; no fabricated tax, prices or credit effects** |
| True external legal supplier verification | Tax/commercial registration references are validated as bounded text, but no external registry-backed KYB/ownership verification is shown here | **NOT VERIFIED, never describe stored IDs as verified legal standing** |

**Owner coordination:** P111 owns only existing `SupplierDirectory.php`, `EnterpriseProcurementController.php`, P111 Feature suite and QA file; PurchaseRequestManager and true Stage 3 missing transitions require the appropriate original Issue/PR owner or explicit handoff. P135 #2858/PR #3065 owns readiness/operational certification, P110 #2844 legal stock owner and Finance/WMS integration remain isolated. Existing admin web endpoint contracts change no Customer/Driver/Van API schema or app dispatch: Customer **NO_IMPACT**, Driver **NO_IMPACT**, Van **NO_IMPACT** to the scoped SupplierDirectory idempotent no-op and isolated Feature tests; no app source modified. B2B VAN ONLY and B2C DRIVER ONLY unaffected.

**Exact CI warning:** Older Required [#44177169323](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44177169323) on `1918d713` finished FAILURE: 4323 Laravel tests passed/2 P111 failed/1 skipped, C13 and Operational integrated children red. The two actual previous Laravel failures are (a) P135-owned card for Store DENY reports `available` vs direct route 403, and (b) P111 missing-hierarchy test demanded 409 but current HTTP returned 403 (authority decision requires source-based clarification, not a blind expectation reduction). New exact HEAD actions were not returned at this checkpoint; **do not claim any new tests passed**. `SOURCE_COMPLETE:NO / TECHNICAL_REVIEW:NOT_PASS / REVIEW_RISK:HIGH / MERGE:BLOCKED`. No main, tag, release, deployment, live database or production.
