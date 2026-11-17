# FOODEX V2 #2858 / P135 — ERP operational readiness requires a real workflow proof

**Integration**: `Foodex_V2` only. Parent: #2503. Source: Unified RBAC / ERP / Sidebar discussion P135.

## Requirement and authoritative placement

An ERP module lifecycle state of `ENABLED` does not certify operational usability. A healthy probe, broad platform role or `certified_versions` entry alone cannot turn on an operational ERP shortcut. Extend the **existing** `ModuleOperationalReadiness::status` used by the ERP Center and module administration, not a second Sidebar policy or new screen.

The authorization chain is distinct: authenticated role/permission -> tenant + channel + store scope -> module entitlement -> enabled module lifecycle -> exact manifest-version certification -> complete reviewed workflow evidence -> healthy runtime probes -> server-authorized operational route. A Sidebar link never bypasses the HTTP controller, RBAC, finance maker/checker or resource-level guards.

## Source-controlled workflow matrix (no automatic certificates)

`backend/config/enterprise_module_readiness.php` records mandatory evidence stages for five currently registered ERP modules:

| Module | Required operational stages |
| --- | --- |
| `procurement` v2 | request, review, approve, purchase order, goods receipt, invoice, return, report, audit |
| `inventory_wms` v1 | receive, reserve, pick, count, adjust, reverse, report, audit |
| `finance` v1 | create, review, approve, post, reverse, reconcile, report, audit |
| `treasury` v1 | receive, review, approve, settle, reconcile, reverse, report, audit |
| `enterprise_bi` v1 | read, filter, scope, export, report, audit |

Every newly registered module in category `operations`, `finance` or `analytics` fails closed until it has a nonempty workflow matrix. `smart_ordering` and `sample_optional` are not silently reclassified as ERP.

Two separately reviewed settings are necessary:

1. `certified_versions[module]` must equal the active module manifest version.
2. `workflow_attestations[module]` must contain that exact version, a 40-character tested Git SHA, review Issue number, canonical FOODEX GitHub Actions run URL and a named passing test for **every** required workflow step.

Both approval maps ship **empty**. An operator cannot declare readiness through a request parameter, entitlement, module lifecycle change, UI toggle or environment variable. Evidence data is checked for structure and completeness; runtime does **not** contact GitHub to independently authenticate a claim in configuration. Maintainers must validate CI run, tested SHA, test code and original acceptance before committing any real attestation. Synthetic fixtures in Laravel tests are not legitimate certificates.

## Automated evidence and security

- `V2ErpOperationalWorkflowEvidenceTest`: default absent approval, mandatory ERP module matrices, no implicit readiness for new modules, Arabic/English fail-closed, missing required step, wrong manifest version, malformed SHA/reviewer/CI URL, and an unhealthy enabled module.
- Existing `EnterpriseModuleOperationalReadinessTest` preserves separate lifecycle/version/health behavior for non-ERP optional modules.
- `ErpCenterTest` uses explicitly **synthetic test-only** provenance to retain its existing authorized CTA fixture cases; actual deployment config contains no such attestations. ERP Center continues to fail closed when certified-version or entitlement is missing.
- No schema/data migration, financial transaction, seed change, permission grant, new endpoint, production mutation, or release artifact.

### Product impact

| Surface | Impact |
| --- | --- |
| Backend | **Affected:** operational status evaluation for ERP categories; fail closed without complete per-stage attestation |
| Dashboard | **Affected through existing ERP Center and module-control diagnostics:** uncertified modules remain informational; no new Blade/JS/sidebar screen |
| Customer Flutter | **Not affected:** no Customer API, auth, ordering, pricing, response DTO or Flutter changes |
| Driver Flutter | **Not affected:** no Driver routes, status, GPS, wallet, dispatch or B2C DRIVER ONLY contract change |
| Van Flutter | **Not affected:** no Van routes, visits, allocations, collections, or B2B VAN ONLY contract change |

Authorization remains in existing server-side controllers and services; no mobile API payloads changed. AR/EN strings are unchanged, and existing tests cover ERP diagnostics localization. No production permission/scope/entitlement is changed.

## Incomplete scope / acceptance handoff

This change enforces a **proof prerequisite**, not G0-G13 certification for all five modules. Before #2858 can be closed, module owners must implement and verify the real create/review/approve/receive/post/reverse/report/audit business journeys, true role and data-scope allow/deny, cross-store/channel negatives, finance SoD, transaction rollbacks, and reviewable exact-SHA CI artifacts for every required step. An empty certification list is intentional and safe, not evidence that business workflows passed.

Obtain actual AR/EN RTL/LTR desktop/tablet/mobile, keyboard and screen-reader acceptance at the final V2 gate on #2503 / #2750, per the owner's 2026-11-10 acceptance deferral. Do **not** declare production readiness, update `main`, publish releases/tags, or deploy from this branch.

**CI gate before any merge:** Repository Policy + V2 Isolation + Laravel/Pint/PHPStan/security/OpenAPI + AR/EN + real MySQL/Redis clean install/upgrade/recovery + terminal required-ci-gate, all green on the exact PR head.

## CI rescue — synthetic readiness fixtures (2026-11-10)

The first exact-head Required CI of this prerequisite failed 28 existing tests across 15 ERP workbench/navigation suites because their fixtures explicitly certify `certified_versions` but had no new workflow proof. Each affected suite now opts into `Tests\\Support\\SyntheticErpWorkflowEvidence::install()` only in Laravel test setup. The helper provides structurally valid, clearly synthetic per-step evidence for **test simulations**, at each module's manifest version. Tests that deliberately omit version certification remain uncertified; P135 negative default and malformed/missing workflow-evidence tests do not call this helper.

This is **not** new production evidence, live readiness, proof of workflow execution, CI verification, or approval. Real `certified_versions` and `workflow_attestations` remain empty. Genuine workflow rollout and independent acceptance require reviewed module-specific exact-SHA proof and owner authorization. This fixture is never referenced by application, seed, deployed config, migration, or public API code.

## Post-reconciliation Laravel fixture regression (2026-11-11)

After the P135 workflow-evidence gate, V2 Bank Reconciliation P297 and Receivables Aging P288 tests inherited pre-prerequisite positive fixtures which set only `certified_versions`. Their intended positive finance/treasury HTTP paths must opt into `SyntheticErpWorkflowEvidence::install()` in test setup, matching existing ERP suites. Disabled-module, missing entitlement, explicit user DENY, cross-store and maker/checker negative HTTP assertions stay unchanged; production module certifications and workflow attestations remain empty. This preserves fail-closed runtime enforcement without asserting genuine G0–G13 completion. These test changes are CI compatibility for merged P297/P288 coverage, not new Bank/Treasury product behavior.

## Further isolated CI rescue — merged P033 / P247 test fixtures (2026-11-11)

The WMS Transfer (P251) test suite now installs `SyntheticErpWorkflowEvidence` to model a reviewed-workflow prerequisite only in Laravel's in-memory configuration. Its four real stock-transfer authorization, idempotency, cross-store and disabled-entitlement regressions passed on P135 CI head `bf5003b5`.

Later merged V2 test-only P033 Procurement/Treasury SoD (#2784 / #3164) and P247 WMS Movement Ledger suites were not present in the older P135 branch tree but ran via GitHub's synthesized PR-merge checkout. They supplied only `certified_versions` and were consequently rejected by the new fail-closed workflow gate before the intended SoD/store/warehouse checks. The two newly integrated fixture setups now opt into **the same synthetic test-only attestation helper**. This does not bypass direct HTTP guards, cross-store/tenant isolation, maker-checker, role-level DENY, or actual disabled module/entitlement behavior; all negative assertions and production ERP certification settings remain unchanged.

The performance baseline comparison failure is tracked independently by #3160 / PR #3171 and requires independent source-anchor approval. None of these synthetic test attestations constitutes real G0–G13 operational acceptance, and no production release or ERP enablement is authorized.


## 2026-11-16 — P135 exact-head 159-failure Laravel fixture rescue

- Original PR #3065 and branch recovered; source reconciliation at `92836e9690072917d4f96ceec7a4e544ed2d2f43` from `Foodex_V2` `fd7c85a6281ffdb217c0a8080512c4e517f1b9ae` preserved original history. Required CI run 43863943091 terminal failed due **159** Laravel assertions (4,040 passed, one skipped), overwhelmingly positive authorized ERP/WMS/Finance/Procurement HTTP cases receiving 403 because they set test-only `certified_versions` without the newly required workflow-attestation fixture. Required Policy, Isolation, Performance, AR/EN and MySQL/Redis acceptance were green on that commit.
- Rescue adds `Tests\\Support\\SyntheticErpWorkflowEvidence::install()` to the **existing positive-oriented suite fixtures** rather than weakening `ModuleOperationalReadiness` or the live configuration. All intentional disabled-module, tenant/store, role, stale-certification and SoD negative assertions remain in place. The one P135 predecessor test `V2ErpWorkflowReadinessCertificationTest` receives proof **only inside its positive revocation scenario**, not in its test asserting unapproved readiness remains forbidden.
- **Ownership fence:** `EnterprisePurchaseReturnFoundationTest.php` and `EnterpriseWmsProfessionalCompletionTest.php` are edited by concurrent #2844 / PR #3921; do not touch either in #2858. Their four formerly failing positive WMS/procurement cases must be reconciled by their active file owner (or after release) before an all-green P135 final HEAD.
- Synthetic data contains placeholder SHA and nonreal CI URL and is test-only; it is never accepted as genuine production certification. Actual versioned module workflow attestations, source acceptance and G0–G13 reviews are **not** complete. No production readiness or merge is claimed. Re-run full exact-head Required CI including all Laravel and real MySQL/Redis after the rescue. Resolve any further defects on this same original branch, with risk review before merge.


## 2026-11-16 — genuine attestation provenance hardening, before G0–G13 sign-off

P135 discovered that a shape-valid fake 40-character SHA, GitHub Actions URL and per-stage "passed" strings could previously satisfy the operational readiness configuration. This is not proof of external CI execution. The source now requires a detached **Ed25519 signature** of the exact canonical module code + manifest version + reviewed SHA + issue + CI run + ordered mandatory stage/test results, verified against explicitly source-controlled trusted public keys. Missing sodium support, absent/unknown signing key, invalid signature, changed SHA/test/stage/module identity and empty trust roots **fail closed**. Both live trust-key and module-certificate maps ship empty. A signing private key must never be included in FOODEX source, composer artifacts, Setup ZIP, Update ZIP, deployment env dumps or the database.

PHPUnit's disposable synthetic helper generates an **ephemeral, in-memory signing keypair** and similarly ephemeral signed fixtures solely for tests whose positive setup explicitly opts in; this is not a production certification, and no example test signature/key belongs in a deployed config. Signed payloads cannot by themselves prove the referenced GitHub Actions run was actually green; human/source audit of tested SHA, stage-level test content, branch protection and real CI output is separately required before an authorized signer certifies an ERP module. The helper is not imported by the application, migrations, seeders or live UI.

**Scope remains incomplete:** truly tested G0–G13 operational flows for Procurement/WMS/Finance/Treasury/BI, legal-stock supplier/FOODEX ownership, channel and store boundaries, finance maker/checker, and linked source issue outcomes still require verified evidence. Four outstanding Laravel positives in two #2844-owned files are outside this PR file fence and require coordinated test fixture changes; do not pretend the high-risk P135 leaf is fully accepted or ready to merge on the basis of this crypto increment alone.

## 2026-11-16 — missing module category denial regression

On the existing P135 original branch, `ModuleOperationalReadiness` now fails closed when a module manifest's category is absent, empty, whitespace-only, malformed or outside the explicitly reviewed registered categories (`operations`, `finance`, `analytics`, `platform`, `restaurant`). Previously an unknown-category module without a workflow matrix could be treated as optional, allowing version certification and healthy probes alone to mark it ready. The isolated Laravel regression confirms valid non-ERP module compatibility and denies invalid categories in AR/EN. This does not populate real certifications, replace the separately signed stage evidence, certify G0–G13 business workflows or waive the four #2844-owned Laravel failures. Backend/Dashboard readiness is the only affected contract; Customer, Driver and Van Flutter/API routes, payloads, auth and dispatch remain unchanged. Manual UX acceptance stays deferred to V2 final; source/CI must still pass before any merge.

## 2026-11-16 — signing-key identity anti-replay security regression

The existing P135 Ed25519 payload now additionally binds the reviewed `key_id` to the signature. A certificate cannot be relabeled under a different trusted key ID **even where both IDs happen to map to the same public key**. The test-only signed fixture sets its ephemeral synthetic signer ID before signing; no private keys or production trust roots are created. The original P135 regression exercises valid signed evidence and denies same-key alias relabeling, changed but syntactically valid review Issue, changed canonical GitHub CI run, and forged positive stage-test identifier, for both Arabic and English. No permissions, routes, Sidebar layout, module certification or deployment values are changed.

This is scoped cryptographic evidence hardening, **not** actual G0–G13 operational certification or an independent verification of referenced remote CI results. #2858 remains OPEN/DRAFT; four peer-owned #2844 Laravel positive failures and all source/role/SoD/build-provenance dependencies remain unwaived. No merge until full source acceptance, TECHNICAL_REVIEW: PASS and exact-final-HEAD required CI SUCCESS.

## 2026-11-16 — scoped B2B Procurement read-projection parity (P135)

The existing Procurement controller's authorized Wholesale B2B store chooser supports a real store-specific module entitlement. Previously `ErpCenterController::isEntitled` and `AdminNavigation::enterpriseModuleAccessible` only projected platform or assigned Retail store module entitlements; a Wholesale buyer with an active authorized B2B store and a positive store entitlement saw an incorrect **not_entitled** card and no existing Procurement menu route. The read projection now reuses `OperationalTenantScope::allowedStoreIds` for the existing `procurement.suppliers.view` permission in B2B, including explicit per-user deny filtering, and the canonical `ModuleEntitlementResolver::allows('procurement', STORE)`. No new route, Sidebar group, role permission, entitlement grant, module certification, middleware bypass or change to other ERP module decisions.

`ErpCenterTest` includes authenticated AR/EN + RTL/LTR read positive against the existing Procurement workbench, and negative unentitled and uncertified Center status/CTA, with a direct HTTP 403 in the uncertified case. The fixture uses ephemeral P135 signed workflow evidence and exact module version **in PHPUnit only**; live `certified_versions`, `workflow_attestations`, and trust keys remain empty. Read-projection parity must **not** be presented as proof that every Procurement POST/GET server handler evaluates store-specific module entitlements; `EnterpriseProcurementController::allowed` currently checks role permission by store and has a separate canonical source authorization gap tracked with P111/#2845. That source gap and complete G0-G13 module certification remain blocking for full #2858 acceptance. Customer/Driver/Van contracts and B2B VAN ONLY / B2C DRIVER ONLY unchanged.


## P135 — canonical Procurement Workbench eleven-tab HTTP acceptance matrix (2026-11-16)

All eleven existing tabs (\`suppliers\`, \`requests\`, \`rfqs\`, \`orders\`, \`contracts\`, \`receipts\`, \`invoices\`, \`matches\`, \`returns\`, \`statements\`, \`reports\`) are GET query parameters on the **single** \`admin.erp.procurement.index\` route. No new route, Sidebar group, or standalone screen. \`EnterpriseProcurementController::TABS\` is authoritative for view permission, while \`EnsureErpOperationalReadiness\` enforces true middleware HTTP 403 independently of Sidebar links.

\`backend/tests/Feature/V2ErpProcurementTabRouteMatrixTest.php\` adds four actual authenticated HTTP cases looping each of 11 tab links in AR/RTL and EN/LTR: (1) Procurement Manager, enabled/healthy, exact matching certified manifest and test-only signed workflow, platform-entitled, with correct B2B store permission => HTTP 200, correct active tab and projected link; (2) same fully permitted actor but missing certified version => HTTP 403; (3) ready module but explicit global DENY of the mapped view permissions => HTTP 403; (4) B2B store B explicitly denied per-store for all mapped views => HTTP 403 while B2B store A remains 200. A globally authorized Wholesale manager otherwise can see all B2B stores, so case 4 tests **true store-DENY scope**, not an invented per-store global assignment requirement. The test also asserts exactly one existing Procurement navigation destination; it never relies on Sidebar appearance to establish HTTP authorization.

This is synthetic fixture evidence, **not** real G0–G13 certification or a live installation success. The production \`certified_versions\`, trusted keys and attestations remain empty. Any failing backend/MySQL/Redis/AR-EN exact-final-HEAD CI remains an explicit blocker; do not claim a passing matrix before observing the test run. \`SOURCE_COMPLETE=FALSE\` for full P135 due authentic operational stage proof, P110 legal stock, Finance settlement/SoD, build source provenance and high-risk review. No Customer/Driver/Van APIs or UI source changed.


## P135 — trustworthy supplier RFQ award through PO issue/receipt (2026-11-16)

**Source-documented defect:** \`ProcurementCompletionManager::awardRfq\` previously accepted an already submitted quote without revalidating supplier status/qualification/store-company visibility, invitation linkage, quote expiration and quoted money/line-source integrity at award. A supplier can be disqualified after invitation, a quote can expire, and accepted prices can become inconsistent with the originating RFQ before a PO is issued. The new pre-mutation guards run within the existing scoped RFQ + quote transaction and fail closed; they do not alter roles, source record money, module certification, or maker/checker controls.

**Executable original-branch HTTP+DB tests:** \`V2ErpProcurementRfqReceiptFlowTest\` drives real Buyer/Manager HTTP maker request → submit → RFQ create/publish → token-bound supplier quote → independent manager approval → RFQ award → PO issue → GRN create/post. Checks supplier ID, immutable company/store/currency linkage, trusted quote amount/line price, maker/checker separation, exact-once PO and stock movement on replay, and audit readback. Negatives cover expired/mismatched quoted amounts, supplier becoming inactive, creator self-approval/award, foreign store supplier, negative price and forged client supplier/total. Real mutes use source-validated HTTP; external supplier price remains unapproved until an independent checker awards it. \`SyntheticErpWorkflowEvidence\` is **test-only**; no production module approved. P110 legal stock title remains owned by #2844 and **not** certified by quantity movement.

**Files:** \`ProcurementCompletionManager.php\` scoped quote award guard; EN/AR existing enterprise procurement validation keys; new #2858 test; this original QA. No new routes, Sidebar, global permission, inventory handoff or finance source. Track exact-head Laravel/MySQL CI and HIGH-risk review separately; \`SOURCE_COMPLETE=FALSE\` for full P135 G0–G13.


## P135 Finance card ↔ authenticated Finance Workbench HTTP parity (2026-11-16)

Verified source defect: ERP Center originally advertised Finance \`available\` from a global \`finance.view\` role plus platform/retail entitlement; its canonical Workbench independently rejects actors without an actual selected, scoped store/legal-entity read capability. The original P135-owned \`AdminNavigation::financeWorkspacesAccessible\` already mirrors the Finance Workbench active B2B/Retail store chooser (ordered and bounded to 100) and \`FinanceWorkbenchAccess::canRead\`; it now exposes \`firstAuthorizedFinanceStoreId\` for ERP Center reuse. This is an **authorized read-only projection**, not a grant. A ready Finance card includes the exact eligible \`store_id\`, otherwise \`not_entitled\`/unlinked; the untouched HTTP controller continues enforcing real channel/store/ledger/module readiness/SoD gates. No new Sidebar route or Finance module permission.

New \`ErpCenterTest\` real Laravel HTTP cases (AR/RTL, EN/LTR): ACCOUNTANT without matching finance entitlement gives \`not_entitled\` and Workbench 403; STORE-only grant without legal-entity entitlement also 403; matching legal-entity + active store-effective \`finance.foundation.read\` produces card \`available\` with concrete store URL and actual Finance overview/accounts HTTP 200; uncertified manifest produces degraded/unlinked and direct HTTP 403; child STORE DENY removes CTA and rejects direct HTTP despite parent entitlement; two-store Wholesale actor links only to a readable store, disallowed B2B store direct GET 404; AR_OFFICER/AP_OFFICER global catalog assignments without B2B channel admission cannot expose a Finance CTA or direct Workbench tab. ACCOUNTANT does **not** acquire \`finance.approve\` or any GL/settlement action; no Finance journal/ledger mutation is performed.

Finance source dependency audit: \`GeneralLedgerService::post\` uses DB locks, balanced 3-decimal lines, maker/checker approval context and transactional audit/ledger; \`AccountsReceivableService\`/\`AccountsPayableService\` drafts and posted allocations lock rows, reject self-post and over-allocation, and produce idempotent GL, while \`TreasuryReceiptService::post\` uses a distinct actor and outbox integration. **Partial** #2633/P303 settlement remittance→per-invoice AR→Treasury→GL exact-once/reversal depends on typed #2602/P272 custody allocations, MySQL concurrency and actual Customer/Van parity. #2696 AR officer B2B positive admission is blocked on #2633-owned Controller; #2697 AP officer has overly broad \`finance.manage\` and needs AP-only SoD before safe channel admission; #2851 incompatible-role exception policy is still pending. Do not modify those shared files or infer that assigned ACCOUNTANT proves Finance module G0–G13 readiness. Live \`certified_versions=[]\` and signed \`workflow_attestations=[]\` remain empty. P135 remains SOURCE INCOMPLETE; all final exact-HEAD checks and HIGH technical review required.


## P135 Finance /admin/erp missing-scope follow-up
The existing exact HTTP Finance read context stays authoritative. A Finance module grant with no authorized legal/store context is now diagnosed as localized `missing_scope`, distinct from absent `entitlement_scope`. Both deny the Workbench URL. Existing `uncertified`, `disabled`, and `unhealthy` states remain independently gated, not manufactured from a role/entitlement. Added seeded AR/EN real GET negative test for denial reason, no Open button, no raw UUID/JSON or journal writes; existing Finance positive and STORE DENY tests remain. No API, sidebar, view markup, migration, finance SoD or frontend/mobile changes. Customer/Driver/Van individually unchanged. The wider P135 source and exact-head Required CI still govern merge; this is only a bounded increment.


## P135 — genuine G0–G13 certification ledger and manifest SHA guard (2026-11-17)

**Authority:** `docs/execution/FOODEX_ENTERPRISE_ERP_PROGRAM.md` G0–G13 and
`docs/execution/FOODEX_ENTERPRISE_MODULE_GATES.json`; this is distinct from
the narrower `required_workflows` stages. G14/main promotion is **not
authorized** for V2. No source test, synthetic CI attestation or this ledger
automatically marks a promotion gate PASS.

### Identity and provenance contract

- The source `enterprise_modules.modules` manifest is the configured
  *candidate* version, **not proof of the version installed on any external
  environment**. Verify the target's `enterprise_modules.manifest_version`
  and the persisted canonical `manifest` for the same module before
  certification. A module that is ENABLED but has a stale installed version or
  different persisted contract is **uncertified**.
- `ModuleWorkflowAttestation::manifestSha256` hashes canonical
  `ModuleManifest::toArray()` with JSON_THROW_ON_ERROR, unescaped
  Unicode/slashes and SHA-256. This is a **content hash**, not GitHub's Git
  blob SHA-1. The signed attestation must carry `manifest_sha256` and match
  the current source AND installed manifest; the Ed25519 signature binds
  `module_code`, `version`, `head_sha`, `manifest_sha256`,
  `review_issue`, `ci_run`, and exact required per-stage tests. A silent
  same-version change to a hard/optional dependency, required core,
  capability, permission or declared health check fails closed. Git SHA is
  separately required and must be the **tested** final commit.
- Actual Composer production lock hash, migrations/seed ordering, upgrade
  artifact checksums, external Actions status/commit, reviewer authenticity,
  stage-to-real-test lineage, cPanel health/queue and Pilot are **NOT**
  established by the manifest hash. Verify them separately with signed,
  reproducible CI evidence and ownership review. An arbitrary signed record
  is not sufficient; the runtime intentionally does not fetch GitHub.
- `certified_versions=[]`, `trusted_public_keys=[]` and
  `workflow_attestations=[]` **stay EMPTY** in production source. The
  synthetic Ed25519 keys and test-only CI placeholder from
  `Tests\\Support\\SyntheticErpWorkflowEvidence` are never deployable proof.
  Store no private keys or provider secrets in release/config/attestations.

### Five independently assessed candidate manifests

| Module | Candidate source version | Required core (in addition to identity/tenant_context/rbac/audit/module_registry) | Hard dependencies | Optional integration contracts | Real G0–G13 readiness |
| --- | --- | --- | --- | --- | --- |
| `procurement` | v2 | none | `[]` | inventory, accounting, treasury | **NOT CERTIFIED** |
| `inventory_wms` | v1 | catalog | `[]` | procurement, accounting, reporting_bi | **NOT CERTIFIED** |
| `finance` | v1 | none | `[]` | sales, procurement, inventory, treasury | **NOT CERTIFIED** |
| `treasury` | v1 | none | `[]` | finance | **NOT CERTIFIED** |
| `enterprise_bi` | v1 | entitlements | `[]` | sales, inventory, procurement, finance, treasury | **NOT CERTIFIED** |

All modules default to `DISABLED`; a source version does not establish
the deployed/installed version on cPanel, staging or production. The
`health_checks` manifest list is `database` for procurement and empty
for the other four; generic registry/health-service checks are not evidence
of every module-specific worker, finance, supplier, reporting or provider
probe. The empty `hard_dependencies` lists are configuration facts, not
proof that optional accounting/stock/treasury/BI source integrations work.

### Original G0–G13 acceptance ledger — apply separately to EACH module

| Gate | Original acceptance domain | Current auditable evidence / indispensable blocker | Decision |
| --- | --- | --- | --- |
| G0 | Ownership/convergence | P135 #2858 and one original PR #3065 exist, but dependent implementation leaves remain OPEN and integration proof is incomplete | **BLOCKED** |
| G1 | Architecture/manifest/dependencies | Five normalized manifests exist; signed canonical SHA-256 and persisted-version guards now reject drift. Real required-core/hard and optional integration contracts still require source/CI review | **PARTIAL** |
| G2 | Migration/seed/data/upgrade | No certified per-module fresh/upgrade/backfill/resume/rollback evidence over the real final Setup/Update ZIP; no proof seed data maps safely to admin pages | **BLOCKED** |
| G3 | RBAC/tenant/store/channel | Existing direct HTTP+DB role/store-DENY tests and scoped routes are partial; cross-store/legal entity/SoD and permission/entitlement parity for each operational action are not fully verified | **PARTIAL** |
| G4 | Canonical functional journeys | `required_workflows` enumerates stages; none has full externally verified exact-commit passing tests and complete real source. Never replace missing receipt/title/ledger/settlement/reconciliation outcomes with synthetic stage names | **BLOCKED** |
| G5 | Enterprise UX | Some AR/EN RTL/LTR authenticated HTTP + responsive page source exists. All task journeys, errors, viewport and accessibility evidence still need final gate | **PARTIAL** |
| G6 | Audit/workflow/SoD | Procurement maker/checker and accounting/treasury posting guards exist in partial tests; independent checker, denial, replay, compensation and audit for complete journeys remain unresolved | **PARTIAL** |
| G7 | Failure isolation | Individual failed health/disabled/uncertified tests protect HTTP; real provider outage, queue/scheduler interruption, cross-module failure isolation and data preservation remain unproven | **PARTIAL** |
| G8 | Performance/cPanel | A green generic smoke benchmark never substitutes module-specific seeded p95/p99, query/memory, queue/scheduler/disabled overhead, cPanel install and thresholds on the accepted head | **BLOCKED** |
| G9 | Security | Authentication, entitlement and signature negative regression exists; full secret/PII, uploads, replay, mass assignment, injection, rate limits and privilege escalation review remains open | **PARTIAL** |
| G10 | Regression | Must pass exact-final-head Required CI + Policy/Isolation, Laravel AR/EN, actual ZIP install/upgrade, applicable Orders/Finance/Catalog/Inventory/WhatsApp/mobile regressions. A prior Required CI failure cannot be promoted | **BLOCKED** |
| G11 | Monitoring/operational readiness | Health gateway exists but genuine module probes, jobs/metrics, support runbook, failed-job diagnostics, backups and recovery evidence per module incomplete | **BLOCKED** |
| G12 | Disable/re-enable/data preservation | Lifecycle tests alone do not prove ON→OFF→ON retains uploaded files, audit, stock/ledger/posted records and fails safely across every module | **BLOCKED** |
| G13 | Selected-tenant pilot | No owner-authorized, verified staging/live pilot, monitoring window, incident acceptance, emergency disable and independent tenant data isolation results; NEVER simulate this in PHPUnit | **BLOCKED** |

No gate here is marked full PASS merely because a unit/feature test exists.
The integrated V2 branch, current source and exact-head CI are inspected per
cycle; the final G13 pilot needs explicit owner deployment authorization
outside this coding-only PR. **G14/main is prohibited by V2 isolation.**

### Module-specific source/test dependency blockers

| Module | Partial positive/security evidence | Blocking original owner / acceptance |
| --- | --- | --- |
| Procurement v2 | `V2ErpProcurementRfqReceiptFlowTest`, `V2ErpProcurementTabRouteMatrixTest`, exact B2B scope and RFQ award → PO/GRN checks | #2844/#3921 P110 legal title vs warehouse custody/COGS, procurement returns and source-finance invoice mapping, all stages/rollback/real CI |
| Inventory WMS v1 | WMS transfer/count/reservation and warehouse-scope test fixtures; unknown-owner projection is fail-closed | #2844/#3921 owner/title provenance, stock movement valuation and supplier/FOODEX attribution; #2588/#3317 Van target and #2602/#3332 custody; genuine count/post/reverse/GL evidence |
| Finance v1 | Existing `GeneralLedgerService` SoD/money guards, scoped Finance Workbench HTTP fixtures and P135 card parity | #2633/#3309 AR collections, #2696/#3221 AR role, #2697 AP role, #2851 SoD, typed receipts/ledger exact-once, reversal and crash recovery |
| Treasury v1 | Receipt/payment/reconciliation services have scoped entitlements, maker/checker and 3dp; some HTTP fixtures | #2632/#3942 upstream guards, #2602/#3332 collection provenance, independent reconciliation/receipt/post/reverse and live bank/GL source evidence |
| Enterprise BI v1 | `V2BiExplicitScopeScheduleAuthorityTest` in original #3958/#3959 tests Store A allow/Store B deny, roleless/inactive and schedule write denial | #3958/#3959 still needs source+merge acceptance, P135 version-specific evidence compatibility, report/export multi-store privacy, scheduled jobs and complete analytics provenance |

The separate existing stage matrix remains authoritative; it is **not** an
assertion that request/review/post/reverse/export/audit actually passed.
For each stage attach the exact original test file/method, CI job and
tested Git SHA, migration/seed and fixture provenance, reviewer/SoD signoff,
negative store/channel test, health result, manifest SHA-256 and installed
version, plus fresh/upgrade/recovery/Pilot evidence when applicable.
Resolve cross-owner files in their original PRs; do not cherry-pick or edit
an active peer's source. Customer, Driver and Van have NO_DIRECT_API_DIFF
from this manifest-gate change, but true full-chain mobile readbacks must
be proven under their original V2 issues before G10/G13 acceptance.

**Disposition:** `SOURCE_COMPLETE: FALSE` for the whole P135; high-risk
`TECHNICAL_REVIEW: FAIL_FOR_FULL_SOURCE`; zero production module
certifications. Keep original #3065 DRAFT; no main, tag, release,
live cPanel, or production modification.


## P135 security regression closeout — 2026-11-17 (exact-head CI PENDING)

This is a **source/test ownership matrix, not a certification**. A committed
test is only `IMPLEMENTED_NOT_VERIFIED` until its exact-final-HEAD GitHub
Actions job has a terminal SUCCESS with individual cases executed. A SKIPPED
job, old-HEAD green run, or synthetic `SyntheticErpWorkflowEvidence`
fixture is **not** G0–G13 PASS. The prior P135 Required run #43991195019
failed Backend Pint `ordered_imports` on
`V2ErpOperationalWorkflowEvidenceTest.php`; Backend Tests and MySQL/Redis
were SKIPPED on that run. The original-owned import ordering is corrected
on the SAME PR; final test execution still requires fresh CI.

| Security / failure dimension | Evidence/test within original P135 or existing V2 source | Result as of this checkpoint |
| --- | --- | --- |
| Inactive / archived roles | Added `V2ErpProcurementTabRouteMatrixTest::test_direct_procurement_http_revokes_inactive_and_archived_roles_even_with_signed_test_readiness`: active manager/Buyer HTTP positive, deactivate manager and archive/deactivate Buyer, same B2B URL HTTP 403 in AR/EN; permissions false, role assignment and audit history preserved | **COMMITTED; CI PENDING** |
| Direct URL / revoked module | Extended `V2ErpWorkflowReadinessCertificationTest::ROUTES` to all five `procurement/inventory_wms/finance/treasury/enterprise_bi` routes; both AR/EN require HTTP 403 when module is uncertified or stale despite ENABLED + platform entitlement. Existing `V2ErpProcurementTabRouteMatrixTest` loops 11 actual tabs across active, unapproved and denied paths | **COMMITTED; CI PENDING** |
| Forged `store_id`, cross-tenant & B2C vs B2B | Added `test_forged_procurement_store_ids_and_retail_to_wholesale_direct_urls_never_cross_channel`: Manager positive authorized B2B, reject retail store ID, nonexistent store ID, retail-only actor forging B2B and roleless direct URL for suppliers/orders/receipts with unchanged audit/PO/GRN counts; existing `test_all_eleven_direct_tabs_deny_an_excluded_b2b_store_without_denying_another` verifies Store A allow vs B explicit DENY for each tab | **COMMITTED; CI PENDING** |
| Explicit DENY precedence | Existing P135 `test_all_eleven_direct_tabs_return_403_when_permission_is_explicitly_denied` and Store B scope-denial test; `ErpCenterTest` checks ERP card does not turn a denied store into actionable CTA | **EXISTING CODE; final exact-head test result PENDING** |
| Supplier-owned vs FOODEX-owned legal stock | #2844 original P110 PR #3921 owns the stock-title provenance, attribution and purchase returns, supported by `EnterpriseWmsProfessionalCompletionTest` and `EnterprisePurchaseReturnFoundationTest`. Quantity movements/warehouse ownership **do not prove** legal stock title or COGS attribution. Do not modify those files in #2858. | **BLOCKED on #2844 source + actual P110 CI** |
| Maker/checker, cross-domain SoD | Existing `V2FoodexAccountCrossDomainSoDAuditTest::test_multi_role_finance_maker_cannot_approve_own_request_even_with_treasury_and_procurement_roles`; `V2ProcurementTreasurySodBoundaryTest`; `Enterprise/EnterpriseFinanceApprovalFoundationTest::test_gl_approval_limit_enforces_maker_checker_poster_and_retry_safety`. Full AP-only/AR-only/GL/Treasury overlapping-role posting authority still requires #2851/#2696/#2697/#2633 original file owners | **PARTIAL SOURCE; full integration BLOCKED** |
| Duplicate/replay | `EnterpriseGeneralLedgerFoundationTest::test_balanced_journal_create_and_post_are_idempotent_and_ledger_is_immutable`; `V2ErpProcurementRfqReceiptFlowTest::test_awarded_rfq_without_matching_purchase_order_fails_closed_instead_of_returning_phantom_id` and `test_awarded_rfq_cannot_rewrite_an_existing_issued_purchase_order_or_its_line_prices`; #2844 purchase-return retry source | **PARTIAL SOURCE; full chain not certified** |
| Negative prices / negative finance balances | `V2ErpProcurementRfqReceiptFlowTest::test_self_approval_and_cross_store_supplier_or_negative_amount_never_persist_forged_procurement`; `EnterpriseGeneralLedgerFoundationTest::test_journal_rejects_unbalanced_closed_period_and_non_posting_accounts` tests unbalanced **journal** rejection. Neither is evidence of all negative receivable/cash/ledger balance and over-credit rejection across posted/reversed collections; #2633 and #2851 own that source | **PARTIAL; negative finance balance acceptance BLOCKED** |
| Concurrency, retries, transaction rollback | `GeneralLedgerService::createDraft/post` use DB transactions, row `lockForUpdate`, idempotency request hash and posted replay return; `ProcurementCompletionManager::awardRfq` locks RFQ/PO in `DB::transaction`; approval service tests retry. A sequential replay test or SQLite transaction is **not** proof of two independent MySQL workers racing, partial failure rollback, exactly-once Treasury → AR → GL write-through or safe irreversible-migration recovery | **SOURCE GUARDS FOUND; MySQL concurrent/rollback acceptance BLOCKED on respective owners** |
| Readiness attestation replay/forgery | `V2ErpOperationalWorkflowEvidenceTest` rejects untrusted signatures, alternate trusted-key identity, malformed SHA/review/CI, altered per-step tests, version drift, SHA-256 manifest content/dependency drift, and stale persisted module manifest/version. Includes test-only signed positive; live cert maps remain `[]` | **COMMITTED; CI PENDING** |

**Non-mutating assertions** in the new HTTP suite check unchanged
`audit_logs`, `enterprise_purchase_orders` and
`enterprise_goods_receipts` for denied read requests. This is not
evidence about POST side effects or power-loss recovery. Do NOT create a
fake posting/approval record to satisfy the workflow checklist.

**Required handoff and final gate:** #2844 P110, #2633/P303, #2602
Van custody, #2632 Treasury, #2851 SoD, #2696 AR role, #2697 AP role,
#3958 BI remain independent. Complete their original source and real
authenticated positive/negative tests (including multi-connection MySQL
race and rollback where necessary) under their original Issues/PRs.
#2858 can certify none of the five modules until those implementations
and G0–G13 original proofs are complete. Keep
`certified_versions=[]`, trusted keys and workflow attestations empty.
No source-complete claim from any test that is SKIPPED.


## P135 existing ERP navigation and workbench UI regression evidence (2026-11-17)

**Placement/ownership:** The existing Procurement business-domain Sidebar has exactly
one canonical `admin.erp.procurement.index` link. Its authorized sibling
functions stay inside the same Procurement Workbench using native, server
rendered `<nav class="foodex-tabs"> / <a href>` links; NO new Sidebar,
route or screen. The UI markup/layout is independently owned by #3964
original PR #3965 (Procurement) and #3960 original PR #3961 (ERP Center).
P135 tests remain in P135-owned test files; no peer-owned Blade edited.

**Added HTTP/markup checks on the original #2858 branch (execution is
PENDING exact-head GitHub Actions and must not be called PASS early):**

| Acceptance | Original P135 test | Evidence scope |
| --- | --- | --- |
| Sidebar route only if server allows | `V2ErpProcurementTabRouteMatrixTest::test_one_authorized_sidebar_destination_renders_keyboard_focusable_localized_workbench_tabs_and_feedback` | Exactly one canonical Procurement Sidebar route; GET its concrete route returns 200 for same actor, tab requests 200; revoke test-only certification and Sidebar link disappears **and** direct GET returns 403 |
| Procurement tabs stay inside Workbench | Same test + existing `test_all_eleven_existing_tabs_allow_direct_authorized_ready_http_in_ar_and_en` | All current permitted tabs are native `href` anchors inside one labelled `<nav>`, with exactly one `aria-current=page`. Other tabs do not become Sidebar destinations; check each true target against same selected `store_id` |
| Arabic/English and RTL/LTR | Same tests, plus `ErpCenterTest::test_uncertified_erp_card_keeps_translated_readiness_explanation_and_accessible_empty_state_without_a_denied_cta` | Render actual authenticated AR `dir=rtl` and EN `dir=ltr`; check localized title/tab labels/explicit uncertified reason and real missing-cert HTTP 403 |
| Keyboard/accessibility *structural* contract | Same tests | Real native keyboard-focusable links, labelled `<nav>`, one `aria-current`, no `tabindex=-1` on tab rail; `:focus-visible` CSS and `role=status` (empty/flash), `role=alert` (validation errors) in existing markup |
| Responsive HTML/CSS *source* contract | Same tests | Existing viewport meta, 767/1023px CSS breakpoints and bounded scrollable table wrapper; no UI markup or CSS modification. **Real browser checks at 320/360/390/412/430/768/1024/1280px remain NOT VERIFIED**, not fabricated PHPUnit success |
| Synchronous loading and error paths | Procurement test and ERP Center test | Existing server navigation/non-JS `<noscript>` store-switch fallback; translated empty-state status and live simulated session status/validation error. There is no async loading widget in this scope; end-to-end network loading/failure, keyboard TAB/Escape and focus movement require a browser test, **NOT VERIFIED** |
| Denied workbench has no fake CTA | ERP Center new test and existing `V2ErpDenialDiagnosticsTest`, `ErpCenterTest`, `V2SidebarOperationalReadinessProjectionTest` | Enabled + entitled but *real config* cert maps cleared → `uncertified` localized reason, exact module card contains neither `href` nor Open button; direct route HTTP 403; no module granted by saved UI preference |
| Negative security | Existing P135 11-tab tests + Store A/B and inactive/archived role tests | Explicit DENY, forged/cross-channel store_id, roleless/inactive/archived, uncertified direct URL remain denied independently of visual navigation |

**Important evidence limitations:** Laravel HTTP/HTML assertions cannot
measure actual focus order, viewport overflow, screen-reader speech, AJAX
load performance or a user pressing the keyboard. Those items require
separate browser acceptance on the final integrated view after original
#3961/#3965 merges, with test results and measured evidence. Never claim
visual/manual accessibility PASS from source markup alone. Only the
exact-final-HEAD Required CI success plus genuine G0–G13 acceptance can
authorize completion; `certified_versions=[]` remains unchanged.


## P135 / #2858 — Customer, Driver and Van source-impact matrix (2026-11-17)

**Comparison:** original [PR #3065](https://github.com/walidatiyaai2025-gif/FOOD/pull/3065) against `Foodex_V2`, checked at source HEAD `788c63d6177fa67b4d5499a6ff4832c82497dda9`. The branch diff contains **zero** files under `apps/customer_app/`, `apps/driver_app/`, `apps/van_app/`, and **zero** changes to `backend/routes/`, `backend/app/Http/Controllers/Api/` or dispatch/fulfillment routing services. Relevant PHP production changes concern ERP module readiness/attestation, Admin ERP Center/Sidebar, and `ProcurementCompletionManager::awardRfq` (restricted source-valid RFQ award → Purchase Order, quote validity/amount/expiry, scoped idempotent retry). This does **not** add a Customer checkout API, a Driver or Van assignment API, a collection/payment posting API, or an order-fulfillment actor change. Existing tests for connected contracts are listed as **SOURCE EVIDENCE**, not assertions that this PR's CI executed them.

### Per-application decision (direct delta vs upstream integration)

| Application | Direct code/API contract impact of PR #3065 | Evidence-backed disposition | Required indirect/parity dependency on a DIFFERENT original issue / worker | Baseline verification (execution gate) |
| --- | --- | --- | --- | --- |
| **Customer** | No app Dart/widgets/tests, `/api/v1/b2b` routes/controllers, B2B account ledger, quote/pricing, checkout or order dispatch contract changed. Procurement RFQ→PO is supplier purchasing, **not** a Customer order or credit posting. | **DIRECT_NO_IMPACT** on Customer mobile/API for this PR. **INDIRECT_INTEGRATION_DEPENDENCY_OPEN** for Wholesale credit, invoice, supplier availability and collections when the other source owners integrate their features. | **#2732 MOB-C03 / original PR #3337** (`feat/2732-v2-customer-wholesale-commerce-parity`, Customer Flutter/API owner) owns pricing/discount/credit, signed balance, invoice/statement export, session/store scope and partial collection readback. **#2633 P303 / PR #3309** owns AR settlement source. **#2844 P110 / PR #3921** owns supplier vs FOODEX legal stock title, not Customer app #2858. Do not edit their files or create a duplicate PR. | Existing `backend/tests/Feature/B2bAccountLedgerContractTest.php` includes `test_account_summary_and_statement_apis_share_currency_balance_and_running_balance`, `test_captured_field_collection_reduces_authoritative_invoice_outstanding` and `test_invoice_settlement_retries_are_idempotent_and_conflicting_reuse_is_rejected`; existing `apps/customer_app/test/b2b_journey_test.dart` has B2B dashboard/statement/checkout scope tests. **These were NOT EXECUTED by the #2858 exact-head run if Customer was SKIPPED**. API/Flutter positive/negative acceptance belongs to #2732. |
| **Driver** | No Driver Dart/widgets, auth, store/channel scopes, assignment, delivery status, wallet/custody or `/api/v1/driver` contract modified. Neither `FulfillmentActorPolicy` nor B2B routing was changed. | **DIRECT_NO_IMPACT** for Driver mobile/API. Retain **B2C = DRIVER ONLY**. Never create Driver fallback or manual exception for B2B from ERP visibility, supplier PO or dispatch errors. | **#2735 MOB-D01** owns B2C-only Driver dispatch/assignment/status/proof; **#2736 MOB-D02** owns Driver wallet/collection role scope and settlement API; **#2602 P272 / PR #3332** owns shared collection/custody channel checks. Driver source is not granted B2B dispatch authority by this issue. Any future code-change to Driver-specific assignment/runtime belongs to its original mobile/fulfillment owner, NOT #2858. Older `docs/workflows/DRIVER_WORKFLOW.md` and `ORDER_LIFECYCLE.md` still mention *manual* B2B Driver execution; these are **STALE vs current executable `FulfillmentActorPolicy::assertManualDispatchActor`**, which calls strict `assertOrderActor`. Existing stale documentation is a separate source-owner cleanup, **not permission to restore a Driver path**. | Existing `backend/tests/Feature/B2BVanFulfillmentMigrationAuditTest.php::test_channel_actor_contract_is_explicit` and `::test_even_manual_b2b_driver_dispatch_is_reported_as_contradiction`; `backend/tests/Feature/OrderTerritoryRoutingServiceTest.php::test_post_create_coordinator_routes_supported_b2b_sources_and_never_falls_back_to_driver`. These existing Backend tests are **not newly run/proven by #2858 until exact-head Backend job executes**, and Driver Flutter jobs being SKIPPED is **NOT PASS**. |
| **Van** | No Van Flutter, auth/visit, territory, loading, trip session, collection/remittance, wallet or `/api/v1/van` contract changed. ERP procurement validates internal purchase RFQs only; it does not transfer legal stock title, load a Van or settle a Van invoice. | **DIRECT_NO_IMPACT** on Van mobile/API in #2858; **INDIRECT_INTEGRATION_DEPENDENCY_OPEN** before ERP Warehouse/Finance/Treasury can be certified end-to-end. **B2B = VAN ONLY**, with `awaiting_dispatch` on no eligible Van and **NO Driver fallback**. | **#2844 P110 / PR #3921** legal supplier-owned vs FOODEX-owned inventory, independent of physical custody; **#2588 P258** Van loading; **#2604 P274** Van stock; **#2606 P276 / PR #3311** authorized trip sessions; **#2602 P272 / PR #3332** B2B Van collection/custody and cross-channel finance; **#2607 P277 / PR #3313** trip closeout/ledger provenance; **#2633 P303 / PR #3309** downstream AR reconciliation; **#2738 MOB-V02** Van assignment/territory Flutter/API and **#2739 MOB-V03** consignment, settlement and offline replay. These issue workers own any actual API/Flutter changes. | Existing `backend/tests/Feature/VanDeliveryExecutionTest.php` checks van B2B lifecycle, stale assignment/cross-van DENY, proof, delivery blocked pending required settlement; `backend/tests/Feature/VanCollectionControllerTest.php` checks scoped Van collection/remittance and same-key retry; `backend/tests/Feature/OrderTerritoryRoutingServiceTest.php::test_post_create_routing_inability_persists_explicit_awaiting_dispatch`. **Sequential replay is NOT proof of simultaneous MySQL different-key race**, rollback or ERP→Treasury→GL parity. Van Flutter/CI must run under original owners, not fabricated here. |

### Non-negotiable channel guard and source evidence

1. In the **current executable source** `backend/app/Services/FulfillmentActorPolicy.php`, `actorForChannel('b2b') = 'van'` and `actorForChannel('b2c') = 'driver'`; both `assertOrderActor` and `assertManualDispatchActor` reject any opposite actor. Keep this exact contract; never use an old manually-assigned B2B Driver reference as an exception.
2. `OrderTerritoryRoutingServiceTest::test_post_create_coordinator_routes_supported_b2b_sources_and_never_falls_back_to_driver` and `::test_post_create_routing_inability_persists_explicit_awaiting_dispatch` exist in the base. An unassigned B2B order stays `awaiting_dispatch`; do not quietly create `driver_assignments` or mark a Van cash collection as B2C Driver execution.
3. The original P135 `ProcurementCompletionManager::awardRfq` diff strengthens a supplier RFQ/quote/PO transaction, validating legal org/company/store and supplier invitation, quote expiry and price lines, and idempotent matching PO. This can affect future **upstream procurement source data** consumed by WMS/accounting but has **no direct Customer, Driver or Van network contract delta**. P135 procurement tests must pass on the exact HEAD; source-owner #2844 must separately certify title/valuation; #2633 must separately certify AR/GL consistency.
4. Retain B2B Van legal identity distinct from warehouse physical custody and supplier/FOODEX **legal stock owner**. Never infer FOODEX-owned inventory merely from a supplier ID, a Goods Receipt, or a Van physical loading record. #2844 and #2602/#2604/#2588 own source proofs and integration.
5. Retain transactionality and 3-decimal monetary precision at the actual owner: no double collections/credit ledger postings; no over-collection; cross-tenant/store/channel and explicit role DENY; replay same key is idempotent, conflicting payload is rejected, and **two separate simultaneous MySQL connections plus rollback** must be proved before calling Van custody/AR G6/G10/G12 complete. #2602/#2633 own these tests, not the P135 readiness guard.

### CI/test status and update trigger

At the last app-impact preflight the #2858 prior exact-head Required CI #43994657813 was **FAILURE** (PHPStan on original `ErpCenterController::safePreviewReferences`), while Backend Laravel/MySQL and Customer/Driver/Van Flutter jobs were **SKIPPED**, not PASS. The original #2858 branch now contains a bounded PHPStan rescue, which must be judged only by its **new final HEAD** workflow runs. The three app decisions above are **direct-source-change classifications**, **not executed runtime device or API acceptance claims**.

If a future #2858 commit changes `backend/routes/api*`, a Customer/Driver/Van API controller, common order/collection/dispatch contracts, or any `apps/*` path, this matrix becomes stale: reclassify the affected app as **IMPACT**, hand off to the named original issue/worker and run its Laravel API + Flutter positive/negative tests on the same integration SHA. Until then, **NO duplicate Flutter edits/tests/branches** are authorized on #2858. Full G0–G13 ERP operational acceptance remains BLOCKED even if this no-direct-impact matrix is complete.

## P135 — exact-HEAD Customer/Driver/Van impact matrix revalidation (2026-11-17)

**Fresh original scope:** #2858 OPEN; original #3065 OPEN/DRAFT, base `Foodex_V2`, compared exact source head `7fec9d30ee5f653dbd97f8bb173a9f98f580c9fa` to integration HEAD `a6969f88e36af3aeb717565509cd6b370eaa8876` (the base may advance again). `list_pr_changed_filenames` enumerated **86** PR changed files. There are **zero** changes to `apps/customer_app/**`, `apps/driver_app/**`, `apps/van_app/**` or other `apps/**`; **zero** `backend/routes/**`, **zero** `backend/app/Http/Controllers/Api/**`, and **zero** `FulfillmentActorPolicy`, `OrderTerritoryRoutingService`, `OrderManualDispatchService`, `VanExecutionStateService` or `CollectionCustodyService` modifications. Original P135 production PHP delta is ERP readiness/attestation, **Admin** ERP Center/Sidebar, and supplier-side `ProcurementCompletionManager::awardRfq` (scoped quote → internal purchase-order authority). This last change **can alter genuine internal supplier/PO/GRN source records** and indirectly affect stock accounting; absence of API changes does not certify its downstream data parity.

| App / explicit result | Evidence-backed DIRECT code/API/mobile decision for #3065 only | Downstream dependencies — exact original issue/PR owner | Which tests are actually in scope |
| --- | --- | --- | --- |
| **Customer — NO_IMPACT (direct API/mobile contract)** | No Customer Flutter or API controller, B2B checkout/account-summary/credit/statement, pricing, partial collection or customer order dispatch changes. Internal ERP *purchase* order is not a Customer order. **INDIRECT BUSINESS PARITY: OPEN** for WMS saleable/legally owned stock, Customer credit and collection/readback when owning changes merge. | **#2732 MOB-C03 / original [PR #3337](https://github.com/walidatiyaai2025-gif/FOOD/pull/3337)** owns Customer Flutter and `CheckoutController` source + tests; **#2633 P303 / [PR #3309](https://github.com/walidatiyaai2025-gif/FOOD/pull/3309)** owns AR settlement/B2B Finance API; **#2844 P110 / [PR #3921](https://github.com/walidatiyaai2025-gif/FOOD/pull/3921)** owns legal inventory title. All are separate open originals and no file was taken over. | #2858: no Customer/API acceptance test triggered by a changed Customer contract (none exists); original #2732 owner must run Flutter B2B pricing/credit/checkout, AR/EN, offline/retry and B2B API scope; #2633 owner must run B2B ledger/collection HTTP; #2844 owner must run supplier/FOODEX title / stock valuation. **Do not claim their tests passed on this PR.** |
| **Driver — NO_IMPACT (direct API/mobile contract)** | No Driver Flutter/API, GPS, B2C delivery, proof, wallet or driver dispatch/assignment code changed. Verified source `FulfillmentActorPolicy::actorForChannel('b2c') === 'driver'`; its `assertManualDispatchActor` reuses strict `assertOrderActor`, not a B2B override. **B2C DRIVER ONLY**, **never** Driver fallback for a B2B unassigned order. | **#2735 MOB-D01** owns B2C-only Driver assignment/status/proof; **#2736 MOB-D02** owns Driver collections/wallet/API; **#2602 P272 / [PR #3332](https://github.com/walidatiyaai2025-gif/FOOD/pull/3332)** owns shared custody provenance/channel boundaries, distinct from P135. | #2858: no Driver API/Flutter code or schema delta. Owner Driver/fulfillment tests must assert B2C DRIVER-only vs B2B DENY and manual-dispatch DENY if those contracts change elsewhere; **Driver CI SKIPPED on this PR, NOT PASS**. |
| **Van — NO_IMPACT (direct API/mobile contract), INDIRECT FINANCE/CUSTODY IMPACT OPEN** | No Van Flutter/API, assignment/territory, cargo-load, stock custody, visit/trip session, wallet/remittance or collection/settlement route changed in #3065. Verified `FulfillmentActorPolicy::actorForChannel('b2b') === 'van'`; opposite actor and manual exceptions denied. An unavailable Van remains an `awaiting_dispatch` exception in the existing routing contract, not Driver fallback. A Procurement goods receipt by itself does **not** prove supplier vs FOODEX legal title, transfer of Van custody, collection or GL/AR closeout. | **#2844 P110 / [PR #3921](https://github.com/walidatiyaai2025-gif/FOOD/pull/3921)** owns stock-owner attribution and return/WMS valuation; **#2588 P258 / [PR #3317](https://github.com/walidatiyaai2025-gif/FOOD/pull/3317)** Van loading; **#2604 P274** Van stock; **#2602 P272 / [PR #3332](https://github.com/walidatiyaai2025-gif/FOOD/pull/3332)** collection/remittance custody; **#2607 P277 / [PR #3313](https://github.com/walidatiyaai2025-gif/FOOD/pull/3313)** closeout/ledger; **#2738 MOB-V02** Van assignment/territory; **#2739 MOB-V03 / [PR #3351](https://github.com/walidatiyaai2025-gif/FOOD/pull/3351)** Van order/offline replay/consignment source; **#2633 P303 / PR #3309** Finance/AR settlement mapping. These owners must implement and validate effects in their original PRs. | #2858: only its internal Procurement RFQ→PO→GRN scoped HTTP/DB test is relevant, and it is **not** a Van Flutter or real custody acceptance. Owner-specific real MySQL multi-worker retry/rollback, legal-title isolation, Van B2B auth/custody/remittance/GL+AR parity and Flutter tests remain mandatory in those issues. **Van CI SKIPPED on this PR, NOT PASS**. |

**Exact-head CI truth at this source review:** [Repository Policy #44013912053](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44013912053) **SUCCESS**, [V2 Isolation #44013911883](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44013911883) **SUCCESS**, [Required CI #44013913089](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44013913089) **IN_PROGRESS** on `7fec9d30ee5f653dbd97f8bb173a9f98f580c9fa`. Backend fast-fail and AR/EN localization jobs SUCCESS; Laravel full tests and MySQL/Redis acceptance were IN_PROGRESS; Customer, Driver, Van jobs were explicitly **SKIPPED** (do **not** count them as test PASS). A source-only comparison proves the direct-scope classifications, **not a runtime device/API E2E PASS**.

**Evidence/control boundary:** `backend/app/Services/FulfillmentActorPolicy.php` is still authoritative; B2B VAN ONLY, B2C DRIVER ONLY, with no B2B Driver/manual fallback; existing dispatch `awaiting_dispatch` failure contract must remain. The P135 internal `ProcurementCompletionManager::awardRfq` change has per-request transactions/row locks but is **not** a validated Van custody/legal-title or Customer credit settlement transaction. P135's `test_real_http_maker_rfq_quote_checker_po_and_posted_receipt_keep_verified_supplier_scope_and_money` asserts idempotent goods receipt stock movement on source fixtures, **not** ledger/title ownership or offline Van replay.

**Reclassify rule:** If new commits to original #3065 add a public/mobile API route/controller/contract, checkout/collection/dispatch policy, or any `apps/**` file, re-run the changed-file audit, mark each affected app **IMPACT**, hand off to its existing issue worker and require authentic Laravel HTTP/API + Flutter positive/negative CI on the same tested SHA. Do not create duplicate issues or pull files out of an active owner PR. Do not convert indirect pending Finance/Van/Customer work into a false `ALL_DONE`. Keep P135 `certified_versions=[]`, unsigned attestation store empty; G0–G13, true operational acceptance, exact-final-head required CI, and review stay **BLOCKED**.

## P135 — exact-head app contract/owner revalidation on 2026-11-17 (PR #3065 @ 3a9f64d)

**Live GitHub source-of-truth at this checkpoint:** \`Foodex_V2@6a1809b432be911e093e4df829ac3710fe547374\`; original [#2858](https://github.com/walidatiyaai2025-gif/FOOD/issues/2858) OPEN (\`worker:waiting-ci\`), original [PR #3065](https://github.com/walidatiyaai2025-gif/FOOD/pull/3065) OPEN/DRAFT, only base \`Foodex_V2\`, HEAD \`3a9f64d595b2c7f9eba9dd75513ec862596acc51\`. The GitHub PR files endpoint lists **87** changed paths. Exactly **0** are \`apps/**\`, \`backend/routes/**\`, or \`backend/app/Http/Controllers/Api/**\`; no change to \`FulfillmentActorPolicy\`, \`OrderTerritoryRoutingService\`, \`OrderManualDispatchService\`, \`VanExecutionStateService\`, or \`CollectionCustodyService\`. The production changes remain ERP operational-readiness/attestation, Admin ERP Center/Sidebar read projection, and the *internal* supplier RFQ → purchase-order/GRN authority and source validation. This is **evidence-backed absence of direct mobile/API diff**; it is not evidence that downstream stock, credit, finance or fulfillment business journeys already passed.

| App / P135 verdict | Exact **direct code and wire-contract** proof | **Indirect/source dependency with original Issue, PR and last released owner** | Allowed executable regression evidence and actual execution limit |
| --- | --- | --- | --- |
| **Customer: \`NO_IMPACT\` on P135 Flutter/API; \`INDIRECT_DEPENDENCY_OPEN\`** | No Customer Dart, \`/api/v1/b2b\` route/controller, checkout, account summary, customer order/pricing, credit or ledger payload changed. ERP *procurement PO* is supplier-side, not a B2B Customer sales order. | [#2732 MOB-C03 / PR #3337](https://github.com/walidatiyaai2025-gif/FOOD/pull/3337), previous mobile owner \`gpt6-device-c-2732-financial-statement-scope-20261116-2143\`, now \`worker:handoff-ready\`, owns B2B Customer Flutter, credit/pricing, stale-session/store PDF/XLSX statement/export and API parity. [#2633 P303 / PR #3309](https://github.com/walidatiyaai2025-gif/FOOD/pull/3309), last worker \`gpt6-v2-2633-finance-ui-authority-20261117\` **EDIT_LEASE_RELEASED**, owns AR allocation, partial receipts/settlement/GL and 3dp/retry/SoD source. [#2844 P110 / PR #3921](https://github.com/walidatiyaai2025-gif/FOOD/pull/3921), last worker \`gpt6-device-d-config-delivery-20261117\` **EDIT_LEASE_RELEASED**, owns legal stock title. All are OPEN/incomplete original scopes. | Base \`backend/tests/Feature/B2bAccountLedgerContractTest.php\` contains signed balance/credit, running statement, settled/overpayment and append-only ledger regression cases. Base \`apps/customer_app/test/b2b_journey_test.dart\` contains B2B awaiting-dispatch-without-Driver, checkout credit, session/scope and AR/EN widget scenarios. **SOURCE PRESENT, NOT P135-FLUTTER-EXECUTED**; run real API and Flutter when integrating their own issues/contract changes, never reclassify this P135 docs-only change as app impact. |
| **Driver: \`NO_IMPACT\` on P135 Flutter/API; NO B2B fallback** | No Driver Dart, \`/api/v1/driver\` route/controller, GPS/status/proof, dispatch, wallet, assignment or B2C order contract changed. Current immutable actor policy: \`actorForChannel('b2c') = driver\`; \`assertManualDispatchActor\` does not bypass \`assertOrderActor\`. | [#2735 MOB-D01](https://github.com/walidatiyaai2025-gif/FOOD/issues/2735) owns Driver B2C status/proof and [#2736 MOB-D02](https://github.com/walidatiyaai2025-gif/FOOD/issues/2736) owns Driver wallet/collections; both original issues currently \`worker:handoff-ready\`/previous engineer released, no new P135 file assignment. [#2602 P272 / PR #3332](https://github.com/walidatiyaai2025-gif/FOOD/pull/3332) owns mixed custody/financial identity, \`worker:handoff-ready\`. | Base \`B2BVanFulfillmentMigrationAuditTest::test_channel_actor_contract_is_explicit\` and \`::test_even_manual_b2b_driver_dispatch_is_reported_as_contradiction\`, plus \`OrderTerritoryRoutingServiceTest::test_post_create_coordinator_routes_supported_b2b_sources_and_never_falls_back_to_driver\`. These are **existing source tests**; Driver CI on P135 HEAD is \`SKIPPED\`, not PASS. |
| **Van: \`NO_IMPACT\` on P135 Flutter/API; \`INDIRECT_CUSTODY_FINANCE_DEPENDENCY_OPEN\`** | No Van Dart, \`/api/v1/van\`, assignment, visit, cargo, trip, collection/remittance/wallet or actor policy changed. Source P135 goods-receipt safeguards protect supplier/PO data but do NOT prove legal owner, custody transfer or cash/GL settlement. B2B routing failure retains \`awaiting_dispatch\`, without Driver substitution. | [#2844 P110 / PR #3921](https://github.com/walidatiyaai2025-gif/FOOD/pull/3921) legal owner (\`FOODEX\` vs \`SUPPLIER\` vs \`UNKNOWN\`) distinct from physical holder; [#2588 P258 / PR #3317](https://github.com/walidatiyaai2025-gif/FOOD/pull/3317) load to assigned Van (\`worker:handoff-ready\`); [#2604 P274 / PR #3330](https://github.com/walidatiyaai2025-gif/FOOD/issues/2604) Van inventory (\`worker:handoff-ready\`); [#2602 P272 / PR #3332](https://github.com/walidatiyaai2025-gif/FOOD/pull/3332) true B2B collection custody/provenance (\`worker:handoff-ready\`); [#2607 P277 / PR #3313](https://github.com/walidatiyaai2025-gif/FOOD/pull/3313) closeout ledger (\`worker:handoff-ready\`); [#2633 P303 / PR #3309](https://github.com/walidatiyaai2025-gif/FOOD/pull/3309) AR and GL; [#2739 MOB-V03 / PR #3351](https://github.com/walidatiyaai2025-gif/FOOD/pull/3351), prior worker \`gpt6-device-c-2739-van-wallet-receipt-only-20261116-1645utc\`, HANDOFF_READY, owns Van app/collections/offline replay. All contracts await their own source/test acceptance; P135 does NOT take over those original branches. | Base \`VanCollectionControllerTest::test_van_collection_and_remittance_are_authoritative_scoped_and_retry_safe\`, \`OrderTerritoryRoutingServiceTest::test_post_create_routing_inability_persists_explicit_awaiting_dispatch\`, plus Van lifecycle and B2B custody source cases are available. Sequential idempotent replay does **not** demonstrate two independent MySQL writers, cross-tenant capture/reconciliation, rollback or title/COGS. Van CI on P135 HEAD is \`SKIPPED\`, not PASS. |

**Trust boundary requiring original P272 verification (NOT a P135 change or a confirmed B2B Driver-dispatch bypass):** Current baseline \`VanCollectionControllerTest::test_driver_compatibility_session_can_collect_for_visit_owned_by_same_van_representative\` deliberately authenticates a \`B2B_DRIVER\` role with \`app:van\` and tests collection under an existing *Van representative's* assigned visit. This is a **legacy Van collection credential compatibility path, not evidence of a Driver assigned to a B2B order**. The #2602/#2739 original owners must review that this session never authorizes B2B Driver dispatch or creates a Driver fallback, and that any collection is bound to the real Van assignment, principal, store, idempotency and ledger. Do NOT claim this legacy role itself certifies B2B finance custody or weaken the mandatory \`FulfillmentActorPolicy\`.

**Executable invariant:** \`backend/app/Services/FulfillmentActorPolicy.php\` (verified unchanged) maps \`b2b → van\`, \`b2c → driver\` and rejects inverse manual/automatic actor use. Existing backend \`OrderTerritoryRoutingServiceTest\` asserts \`awaiting_dispatch\` when no authorized Van is available, **never Driver fallback**. Existing \`B2BVanFulfillmentMigrationAuditTest\` rejects manual B2B Driver contradictions. Source tests are not automatically pass evidence for the current 87-file PR.

**New exact-head CI and allowed test scope:** At this review, [Repository Policy #44038009435](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44038009435) = **SUCCESS**, [V2 Isolation #44038009480](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44038009480) = **SUCCESS**, [Required #44038009420](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44038009420) = **IN_PROGRESS**; backend fast-fail, AR/EN and performance child jobs SUCCESS; Laravel Backend Tests and real MySQL/Redis acceptance children IN_PROGRESS. Customer, Driver, Van jobs = **SKIPPED / NOT PASS** because no app contract/files changed. No P135 app/APIs were mutated, no fake Flutter job was reported, and no additional app-scoped branch or duplicate PR was opened. Baseline test source references above are **READ/INSPECTED only**, unless exact-final-HEAD job evidence later shows them executed. Any future PR change to \`apps/**\`, \`backend/routes/api*\`, \`backend/app/Http/Controllers/Api/**\`, ledger/checkout/dispatch/wallet/Van custody or the channel actor policy must trigger NEW per-app change inspection, actual Flutter/API positive/negative CI at the owning original issue, and a matrix reclassification from \`NO_IMPACT\` to \`IMPACT\`.

**Disposition:** This per-app *direct-source* classification is complete on the inspected HEAD, but overall **P135 G0–G13 is SOURCE_INCOMPLETE/NOT_CERTIFIED**. Keep \`enterprise_module_readiness.certified_versions=[]\`, \`trusted_public_keys=[]\`, \`workflow_attestations=[]\`; do not claim module readiness or a completed cross-app finance/stock journey. Never merge, close, tag, release or deploy until original full source, TECHNICAL_REVIEW PASS, and exact-final-head Required CI SUCCESS. G14/main promotion forbidden.
