# FOODEX V2 — FINAL CLOSURE / Delivery-First Worker Playbook

**Effective owner directive:** 2026-11-23, master [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503#issuecomment-7095299490). **Governance implementation:** #4387. **Sole integration branch:** Foodex_V2. This is a finite *release-scope freeze and sequencing rule*, not an announcement that V2 is ready to deploy.

**MOBILE IMPACT — Customer / Driver / Van:** NO_DIRECT_SOURCE_IMPACT. This owner-governance documentation PR changes no Flutter source, mobile/API runtime, dispatch, notifications, binary packaging or deployed endpoint. Existing app API safety contracts and the B2B/Van and B2C/Driver routing invariants remain required regression coverage.

## 0. Mandatory precedence and scope

At every fresh or resumed worker session, read the latest #2503 owner directives, this playbook, AGENTS.md, docs/v2/V2_ISOLATION_GOVERNANCE.md and the original issue/PR/CI; use live GitHub data instead of this document for current statuses. Where previous V2_CLOSE_NEXT instructions tell workers to choose arbitrary small leaves, **prioritize final delivery gates and their existing blocking prerequisites instead**. Keep the existing worker lease, one-issue/one-owner/one-branch/one-PR, no-double-ownership, 10-minute checkpoint and exact-head review/CI contracts. This does not activate or create autonomous scheduled workers.

**In scope now:** Laravel/backend ERP, existing ERP Admin/Dashboard web surfaces, role/action/route/tenant/channel/store/module authority, finance SoD and audit, source traceability, web AR/EN/RTL/LTR/responsive/a11y, cPanel/shared-hosting compatible one-ZIP signed Setup/Web Installer, signed Admin ZIP Updates, backups/recovery, and full backend + security acceptance. Preserve existing Customer/Driver/Van API and canonical B2B=VAN ONLY/B2C=DRIVER ONLY behavior via regression tests.

**Out of current V2 scope:** new Customer/Driver/Van Flutter/mobile source or APKs, Van-exclusive product development, WhatsApp expansion, speculative new modules/features or polish, and owner-deferred source requirements. Deferred items are marked OWNER_DEFERRED/OUT_OF_SCOPE, **never PASS**. No main, releases, tags, stores, production, direct Foodex_V2 pushes, promotion or deployment without a *separate explicit owner instruction*. Required GitHub checks/branch protections remain mandatory until individually and properly changed.

## 1. Effective feature freeze and exception gate

From 2026-11-23, a new request, leaf Issue or implementation PR may enter this V2 execution queue only if it is:

- **SHIP_BLOCKER** — an unmet *already-required* ERP/web/setup/update/security/source-to-issue acceptance requirement with a concrete failing path and parent gate;
- **SECURITY_BLOCKER** — a reproducible critical authorization, tenant/store/channel, finance/data-integrity, backup/recovery or exploit/exposure defect that cannot safely ship;
- **MERGE_OR_COMPLETE** — source-complete work on an *existing* issue/branch/PR that needs real CI repair, authorized review, reconciliation, or final integration verification; or
- **OWNER_APPROVAL** — an actual owner-controlled choice without which final acceptance cannot proceed; **stop at the decision boundary**, state alternatives and consequences, and continue another safe prerequisite when possible.

Everything else is **DEFER_TO_NEXT**. Do not create new speculative leaves to increase closure counts. Even if a proposed fix sounds useful, no new feature enters without parent-gate evidence. If a real blocker requires a new Issue, check original source/issue/PR history first, document the reproducer, gate, impacted files, proposed acceptance, worker and exact ownership; create at most one justified atomic Issue. Never relabel incomplete as completed, auto-close deferred historical PRs without evidence, or delete a branch containing unmerged work.

This freeze is an **admission filter**; it does not waive approved mandatory source requirements, introduce a fixed concurrent-worker limit, weaken CI or security, or authorize independent modifications to active PR files.

## 2. Existing PRs and open issues: resolve before starting new work

At the start of each closure cycle, fetch **live, paginated** open Issues, all open/draft/merged/closed PR histories, original issue branches, current Foodex_V2 SHA, approvals, lease ownership and exact-head CI. Do not count PRs as Issues. Reconcile every existing open PR with its actual original issue; do not clone/restart work when a draft is old or a worker disconnected.

For every PR, record exactly one **disposition**:
1. **FINISH_EXISTING** — outstanding in-scope behavior or required tests; continue original Issue/branch/PR with the legitimate owner/handoff.
2. **MERGE_READY** — all relevant source and negative paths verified, actual required final-HEAD checks terminal SUCCESS, required review/approvals satisfied, branch still mergeable and no live base drift; only then guarded merge into Foodex_V2 and verify the result.
3. **BLOCKED_APPROVAL / BLOCKED_DEPENDENCY** — keep OPEN with a precise, actionable source blocker, decision maker, owner and next step; work independent delivery prerequisites instead of waiting idly.
4. **DEFER_TO_NEXT** — genuinely nonblocking work with owner-authorized deferral; preserve all implementation evidence, link the next-cycle disposition, and mark owner-deferred/not-planned only if justified. **Do not mark DONE.**
5. **DUPLICATE_ALREADY_DONE** — only after proving exact equivalent implementation, authoritative original PR history, integration SHA, tests and that the old Issue can close safely without losing obligations.

Existing green CI on an incomplete Draft **never** authorizes merge. An old red/superseded CI **never** justifies a second branch. Never reassign another active worker's file fence, force-push unrelated history, or bypass protected-branch checks.

## 3. Critical-path execution order (not simply issue-number order)

Prioritize independently owned, unblockable and parallel-safe prerequisites in these workstreams. Parent gates remain open until their **entire acceptance** is evidenced.

1. **Installation** — [#2745](https://github.com/walidatiyaai2025-gif/FOOD/issues/2745) and relevant existing original PRs: reproducible production Composer dependencies; actual PROJECT-Setup-vX.Y.Z.zip built from a tested SHA, complete Laravel/vendor/assets; web /install or /setup wizard for cPanel/shared-hosting/VPS without end-user SSH, environment checks, MySQL connection, safe env/key, migrations/seed/admin/storage/cache/queue/mail/scheduler readiness, installer lock and real fresh-MySQL acceptance. Explicitly describe cPanel steps still requiring host permissions.
2. **Upgrade and recovery** — [#2746](https://github.com/walidatiyaai2025-gif/FOOD/issues/2746) and [#2846](https://github.com/walidatiyaai2025-gif/FOOD/issues/2846): actual PROJECT-Update-vX.Y.Z.zip and admin System Updates flow, signed package provenance plus SHA-256, SemVer compatibility, ZIP traversal/symlink defenses, exclusive Super Admin action and update lock, verified backup, preserve .env/data/uploads/storage/users, migrations, health check, update history and safe recovery/stop strategy. Validate supported upgrade and failure paths; never assume every migration is reversible. Setup and Update artifacts must be generated from the same final reviewed commit when released.
3. **ERP functionality, SoD and security acceptance** — [#2748](https://github.com/walidatiyaai2025-gif/FOOD/issues/2748) with true original prerequisites such as [#2840](https://github.com/walidatiyaai2025-gif/FOOD/issues/2840), [#2858](https://github.com/walidatiyaai2025-gif/FOOD/issues/2858), related finance/roles/approval gates: real HTTP deny/allow tests, B2B/B2C and store/tenant scope, module entitlements, maker/checker, role archive/disabled-user, audit trails, positive/negative ERP workflow cycles and preservation of deployed mobile API contracts.
4. **CI, web UX, source traceability** — [#2744](https://github.com/walidatiyaai2025-gif/FOOD/issues/2744), [#2750](https://github.com/walidatiyaai2025-gif/FOOD/issues/2750), [#2742](https://github.com/walidatiyaai2025-gif/FOOD/issues/2742), [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503): exact-final-head Repository Policy, V2 Isolation and FOODEX Required CI, full relevant Laravel and MySQL/Redis deployment acceptance, AR/EN/RTL/LTR, responsive/a11y and ERP journey verification, zero unaccounted in-scope source rows/177-sidebar nodes. Excluded rows have explicit OWNER_DEFERRED/OUT_OF_SCOPE labels with evidence, never false successes.

Workstreams can run concurrently **only with verified disjoint ownership and real runner capacity**. A postmerge successful test on an older Foodex_V2 SHA does not certify the latest SHA.

## 4. Worker loop: V2_FINAL_CLOSURE replaces endless leaf churn

1. **PRECHECK**: read #2503 latest, this playbook, original Issue, owner claims, dependency graph, original branches/PRs and real CI; confirm latest Foodex_V2 SHA. Reuse original branch/PR. Observe ownership/stale-lease and file-fence contracts.
2. **CLASSIFY**: emit ISSUE | PR | OWNER | GATE | SHIP_BLOCKER/SECURITY_BLOCKER/MERGE_OR_COMPLETE/DEFER_TO_NEXT/OWNER_APPROVAL | EXACT BLOCKER | NEXT ACTION. For an unowned task, claim an existing atomic Issue; don't code on #2503 itself.
3. **EXECUTE**: finish the smallest *gating* acceptance gap under the original owner and file fence. Write positive/negative tests and real end-to-end evidence as appropriate. If externally blocked, checkpoint then continue an independent gating prerequisite.
4. **VERIFY**: source review by risk; exact last PR-head checks, current PR base, mergeability, independent human approval if actual protection/external policy requires it. Do not mistake a same-account engineering audit for independent approval. No green check may be reused from a superseded head.
5. **MERGE & CLOSE**: guarded merge into Foodex_V2 only, verify merged SHA and real postmerge acceptance on latest integrated head. Close code-complete atomic issue as completed with evidence; keep gate/master OPEN until all obligations pass.
6. **REPORT**: make tangible progress visible with GitHub links to actual Issue, original PR, HEAD/CI runs, decision, remaining gate blocker and next eligible action. **Do not report a plan/audit as feature completion.**

Existing V2_CLOSE_NEXT/كمل V2 prompts now use **this delivery-critical-path selection priority** instead of opening more arbitrary leaves. Existing CI Freeze and Merge Readiness Monitor are advisory controls, not substitute approval or automation of a worker.

## 5. Final V2 exit checklist and stop condition

V2 Backend ERP is **not** complete until:
- [ ] Every mandatory in-scope source/ERP/RBAC/sidebar requirement is proven by an integrated implementation and test, or explicitly owner-deferred; no unexplained missing or false PASS rows.
- [ ] Actual signed Setup ZIP and Update ZIP from the same final reviewed/tested SHA are available as artifacts, with manifest, Git SHA, checksums, release notes, build information and verified signature.
- [ ] Fresh clean-host MySQL install using that Setup ZIP and supported upgrade using that Update ZIP succeed; existing data/config/uploads are preserved; verified backup, failure/recovery and installer closure/security tests pass.
- [ ] Security, tenant/store/channel/RBAC and finance SoD end-to-end negative/positive tests pass, with the existing Dashboard usable in AR/EN and accessible on web layouts.
- [ ] Every applicable required CI check is terminal SUCCESS on the **actual final SHA**, and the current Foodex_V2 integrated acceptance is successful on that exact integration SHA (not a past one).
- [ ] Existing in-scope blocking PRs/issues are resolved, deferred exceptions are explicitly catalogued and final owner **manual UAT/signoff** is recorded on #2503.

Only after **all** boxes are evidenced may #2503 be proposed for owner-confirmed final closure. **Never** infer permission for main integration, release tags, cPanel production deployment or app publication. If blocked, report the finite set of real remaining blockers and owner decisions rather than restarting an infinite issue-creation loop.

## 6. Status record template for each worker handoff

- ISSUE / ORIGINAL PR / ORIGINAL BRANCH / OWNER / LAST VERIFIED HEAD:
- CLASS / PARENT DELIVERY GATE / UNMET ORIGINAL SOURCE REQUIREMENT:
- WHAT CHANGED + AFFECTED FILE FENCE:
- REQUIRED POLICY, ISOLATION, REQUIRED CI & POSTMERGE EVIDENCE (links/status):
- SIGNED SETUP/UPDATE/FRESH/UPGRADE/BACKUP/RECOVERY EVIDENCE if applicable:
- BLOCKER / DEPENDENCY / APPROVAL NEEDED / NEXT CONCRETE ACTION:
- OUTCOME: CODE_COMPLETE or BLOCKED or DEFER_TO_NEXT (not product-ready until #2503 signoff).

This file changes **worker priorities and documentation only**. It is not proof that any installer, updater, test or release artifact currently exists or has passed.
