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
