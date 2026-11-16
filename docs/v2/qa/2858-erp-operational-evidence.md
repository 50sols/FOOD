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
