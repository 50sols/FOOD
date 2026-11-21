# FOODEX V2 — autonomous issue closeout playbook

**Owner request:** 2026-11-21 · **Atomic documentation PR:** #4045 · **Canonical integration target:** `Foodex_V2` ONLY · **Master:** #2503.

> **Wake word:** `V2_CLOSE_NEXT` (target **one actually verified merged-and-closed issue**, exploring more than one safe candidate as needed), or `V2_CLOSE_3` (target **up to three verified closures** in succession, with the same fallback). Arabic alias: **كمل V2** means `V2_CLOSE_NEXT`. These are human-to-worker requests, not an unattended process or a guarantee of closure. A worker with repository access must read this file and live GitHub. Do not ask the owner to restate the project, issue list, or these rules.

## 0. Authority and scope

1. Live Issue/PR/branch/CI/owner decisions > `AGENTS.md` > repository contracts > this plan > dated snapshot. Read `AGENTS.md`, `docs/v2/V2_ISOLATION_GOVERNANCE.md`, `docs/v2/V2_WORKER_CAPACITY.md`, and relevant source/tests before acting. Respect `.ai/skill-router.json`.
2. Current **2026-11-18 owner scope** (see #2846 and #2503): Laravel/backend ERP, the existing ERP Dashboard, Admin configuration, signed one-ZIP web installer, Admin Update ZIP, backups/recovery and tests. **No Customer, Driver or Van Flutter/app work**, new APKs, WhatsApp feature expansion, Van-only capabilities, or unrelated feature work under the current V2 authorization. Preserve already deployed mobile API contracts with backend tests; B2B=VAN ONLY, B2C=DRIVER ONLY. If older issue/AGENTS prose conflicts, apply this newer scope without representing deferred work as PASSED.
**MOBILE IMPACT — Customer / Driver / Van:** NO_DIRECT_IMPACT for this documentation-only plan: no Flutter, backend runtime, mobile API, dispatch, notification or store code changes. Future code workers still perform their own three-app impact and regression classification.
3. No merge/push/cherry-pick into `main`, production, releases, version tags, deployment or store submission. **All task PRs base exactly `Foodex_V2`**. Green CI does not grant these permissions.
4. The goal is **zero outstanding in-scope code-completable leaf requirements**, then correct final gates and owner acceptance—not closing every GitHub issue regardless of purpose. Permanent store lanes and live production operator acceptance are separate owner-controlled work; no fabricated completion. Do not reopen or duplicate explicitly owner-deferred mobile/Van/WhatsApp issues to inflate/clean counts.
5. Do not weaken required checks, tests, branch protection, RBAC, tenant isolation, signing, rollout, legal provenance or review to improve the open-issue count. A green build without functional source acceptance is NOT complete.

## 1. Resume a bounded unit (mandatory for every worker)

When given `V2_CLOSE_NEXT`, the **unit of success is one verified issue closure**, not one candidate inspected. Select the smallest safe candidate, execute genuine code/test/remediation on its **original** branch/PR, then merge and close it only if complete. If that candidate is dependency/owner/environment-blocked, checkpoint it without takeover, **select and execute another independent eligible leaf or an authorized unowned prerequisite** in the same invocation; repeat until **one** issue is actually merged-and-closed or the *whole currently safely actionable queue* is genuinely exhausted or a hard tool/resource/safety stop occurs. `V2_CLOSE_3` repeats this closure-first loop to aim for up to three actual completed issues, **strictly sequentially**. Neither command guarantees closures or permits unbounded/background work.

**Live preflight, before creating or editing anything:**
1. Query *all pages* of repository open **issues**, open/merged PRs, relevant branches, labels, active `foodex-worker-state:v1` claims and current-head CI. Exclude PR records from issue counts. Distinguish V2 code, gate/master, permanent store and production acceptance; include new issues created after the dated snapshot.
2. Read the candidate's latest issue comments, all referenced original PRs, source/matrix acceptance, parent dependencies and recent merged overlapping PRs. Check `Foodex_V2` head and check protection/ruleset state, do not infer server-side enforcement.
3. Resolve original branch + PR **first**. Reuse them; never create a retry/final/second branch or duplicate PR. A pre-existing code-complete merged implementation is not reimplemented.
4. Check the lease registry per `V2_WORKER_CAPACITY.md`: unique worker/session identity, verified active filescope, running CI and resource availability. A red exact-head CI is eligible for the governed immediate CI-rescue handoff. A silent claim may be taken over only after verified 30-minute idle, no running CI and no red-state conflict, following `AGENTS.md`. The staged #2931 watchdog is **not** assumed active on GitHub's default branch. If ownership is ambiguous, fail closed.
5. Claim the original Issue using a `foodex-worker-state:v1` comment with WORKER_ID, OWNER, BRANCH, HEARTBEAT, SCOPE, NEXT_ACTION; re-read the live issue/PR before editing. Update a verifiable checkpoint at least every 10 minutes while actually working. Do not impersonate another worker or overwrite peer files.
6. Load minimal relevant source skill pack, read the actual implementation and tests, and write a **finite missing-acceptance checklist** with each item mapped to exact code/tests/evidence, not speculative UI requirements.

**Candidate selection, not a blind numeric sequence:**
- First finish an **unowned or validly handed-off small leaf** with a short source/test gap, few non-merged prerequisites and a clear original PR; prefer CI-rescue on red current heads where takeover is authorized. Existing draft PR with green CI but `SOURCE_COMPLETE: NO` is *not* ready to merge.
- Do not start a gate/master, a complete ERP stage, a sensitive production-account audit or a release acceptance task when an independent leaf can be completed instead.
- Before moving to the next issue, refresh current state. Do not fill slots numerically: no fixed worker ceiling, but strict file ownership and real CI/execution capacity apply.
- If a chosen candidate is blocked, preserve its original PR, record a precise dependency/lease/approval checkpoint, then advance to another verified available independent leaf or legitimately unowned prerequisite; **a single blocked candidate is not a terminal outcome of `V2_CLOSE_NEXT`**.
- If all candidate lanes are genuinely unsafe/unwritable (active peer scope, required external owner decision, unavailable tool/runtime, in-flight required CI, or no implemented source route), state which distinct candidates were checked and why each could not be executed; do not manufacture a branch merely to show activity.

## 1A. Mandatory execution-first failover (owner corrective rule #4047, 2026-11-21)

**Never answer a `V2_CLOSE_NEXT` mission with only one diagnosis such as “#2696 CI green but SOURCE_COMPLETE: NO; 0 closed” when another in-scope task can safely be coded.** A green CI is a prerequisite for merge, not evidence that the source is finished. Diagnosis itself is not closure.

Implement a **bounded, deterministic candidate queue**, recalculated from live GitHub after each attempt:
1. **Resolve before rejecting:** for each candidate, distinguish (a) missing implementable code/tests **inside its original PR** (start implementing it), (b) a prerequisite with an original unmerged PR (inspect ownership and, if legitimately available, work that dependency **on its own original PR**), (c) a genuine external approval / source-of-truth requirement (record as blocked, never invent approval), and (d) peer-owned / running-CI conflict (respect the lease and skip). Do not treat “Source Complete: NO” as automatically unworkable.
2. **Build a finite shortlist** of remaining, distinct in-scope code leaves and their *legitimately available* source prerequisites; rank unclaimed small source/test deltas first, then authorized CI-rescue, then other valid unowned full leaves. Do not rank based solely on an issue number, whether a PR is green, or how many issues the worker promises to close.
3. **Execute, not just inspect:** after safe ownership preflight, edit source/tests on the original branch, demonstrate positive/negative coverage, run relevant checks, audit, and merge/close only on proven full completion. A candidate counts as a **work attempt** only when the worker has attempted material code/test remediation or verified with precise evidence that no safe source write is authorized. A read-only note repeating existing PR status is NOT an execution attempt.
4. **If blocked, move down the queue immediately** rather than ending the mission; update its original Issue with exact blocker, branch/PR/head, safe next action and handoff. Check a new candidate's lease, original branch, source ownership and dependencies from scratch before editing; never edit two overlapping scopes at once or override another worker.
5. **Return only when** one completed leaf has a verified merge SHA and is actually CLOSED, or when no safe candidate exists for this invocation (including hard GitHub/CI/tools limits). If no closure is possible, give a *full, concrete exhaustion report* with attempted tasks and reasons; do not report “everything blocked” based on one issue. Do not fake work, indefinite loops, forced merges or hypothetical future execution.

**Concrete first-reply regression scenario (not an instruction to seize ownership):** #2696 / original PR #3221 has green checks but unmerged source authority; #2633 / original PR #3309 owns real AR settlements and may have a **new active worker claim** even if a prior watchdog handoff existed; #2851 still requires an approved cross-domain Finance/Treasury SoD role/exception policy and cannot be declared approved by an AI. For `V2_CLOSE_NEXT`, do **not** merge #2696 or hijack #2633. Inspect the **latest** #2633 lease and CI; only take that original dependency over if authorized and proven idle. Otherwise pick another independent currently writable V2 ERP/Dashboard/installer/updater leaf and actually work on it. For explicit `كمل #2696`, stay scoped to #2696 and report its genuine unsatisfied dependency; do not silently edit a different issue.

**Completion report must differentiate:** `CLOSED` with PR/merge SHA/CI/issue evidence, `CODE_UPDATED_NOT_CLOSED` with new exact HEAD/tests/blockers, `BLOCKED_AND_SKIPPED` with precise ownership/dependency/approval evidence, and `NO_SAFE_WORK` only after current eligible queue inspection. Zero closures is possible but only after real, verifiable effort or a real safety limit. This contract doesn't create a cron, worker daemon, or independent agent session.

## 2. Execution contract: finish the **whole** scoped source requirement

1. **Source:** implement genuine server-enforced HTTP/API behavior and appropriate existing UI. Reuse → Extend → Compose → New Screen, no new Sidebar unless justified. Keep migrations additive/retriable and preserve real data, .env, secrets and uploads.
2. **Security:** positive and negative requests for allow/deny, direct-URL 403, role inheritance and SoD/maker-checker, B2B/B2C and cross-tenant/store isolation, feature entitlements/readiness, error/retry/race/audit paths as applicable. No proof by screenshots or mock-only routes where real endpoints are required.
3. **UX and config:** AR/EN RTL/LTR, responsive desktop/mobile web, keyboard/a11y, real data, meaningful error/success. If a *business preference* is missing, use authorized guided Settings with validation, explicit unconfigured/readiness state, safe defaults only if defensible and actual server behavior; never fake a legal, bank, stock-owner or finance approval.
4. **Tests/CI:** unit, feature/integration, real MySQL/Redis where the contract needs it, regression, lint/static/security, localization, packaging and relevant workflow gates. Follow the exact change-area plan; SKIPPED is not PASS when the test is applicable. Run/prevent deterministic checks before push. Fix red on the same original branch/PR. Never infer success from past commits.
5. **Review:** produce file-level `TECHNICAL_REVIEW: PASS` for the final exact head (risk, code paths, security, negative cases, migrations, evidence), `SOURCE_COMPLETE: YES` for the **entire issue**, exact current SHA and all required GitHub checks **SUCCESS**. Independent approval if enforced by branch rules; an author-controlled note is not a substitute for enforced approvals.
6. **Merge:** verify original PR base=`Foodex_V2`, head exact SHA, no active ownership/file conflict, review, all applicable required checks terminal green and mergeability. Merge the original PR only; verify the resulting integration SHA, source presence and no regression.
7. **Close:** only the genuinely source-complete leaf after verified merge. Record in issue: `CODE_COMPLETE`, original PR, exact tested head, merge SHA, CI URLs, negative tests, scope, and `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` if policy permits. Do not call a partially fulfilled source `completed`; don't delete work without verification. Keep #2503 and gates open until their own acceptance passes.
8. **Handoff:** if blocked, preserve the original branch/PR and leave a machine-readable checkpoint: missing source cases, prerequisite Issue, exact CI error, next safe action, worker state and reason. Mark external blocker distinctly from code work; leave issue OPEN.

**State vocabulary:** `READY` (preflight verified) → `OWNED` → `SOURCE_INCOMPLETE` / `CI_FIX` → `SOURCE_COMPLETE` → `REVIEW_PASS` → `EXACT_HEAD_GREEN` → `MERGED_V2` → `CODE_CLOSED`; or `BLOCKED_DEPENDENCY`, `BLOCKED_OWNER`, `BLOCKED_ENVIRONMENT`, `HANDOFF_READY`. Labels alone never prove these states.

## 3. Current work map (dated 2026-11-21; NEVER trust without live refresh)

The adjacent `V2_ISSUE_CLOSEOUT_SNAPSHOT.json` records the **49 open issues before this documentation issue #4045 was created**, 11 associated existing Draft PRs, and coarse lanes. This is a **snapshot, not a live scheduler, reservation, automatic approval or authoritative dependency resolver**.

### Original active PRs: reuse, repair, finish before seeking new branches

| Issue | Original PR | Current category / missing acceptance (recheck live) |
| --- | --- | --- |
| #2846 | #4019 | Organization guided setup is partial; coordinated full P112 signed distribution evidence |
| #2840 | #4012 | Readiness UI is partial; actual ERP full-path acceptance and #2858 |
| #2706 | #3995 | Wholesale price-tier read-only precheck; full commercial stage incomplete |
| #2746 | #3968 | Updater permission guidance partial; real verified signed ZIP/backup/recovery |
| #2844 | #3921 | Stock-owner provenance partial; immutable per-owner lifecycle and ledgers |
| #2745 | #3444 | Composer determinism partial; complete reproducible signed Setup ZIP and fresh install |
| #2559 | #3326 | Profitability rules blocked on cost/title, approvals, credit/profit source |
| #2633 | #3309 | AR settlements partial; receipt → invoice → GL, reversal and real concurrency |
| #2696 | #3221 | AR role partial; Finance/SoD and settlement cross-issue integration |
| #2845 | #3206 | Entitlement/checker partial beyond Procurement; Finance/Treasury/WMS scope |
| #2858 | #3065 | ERP operational readiness partial; complete stage workflow and security evidence |

All these PRs were found DRAFT in the dated inspection; **a passing Required CI does not reverse `SOURCE_COMPLETE: NO` or authorize merge**. PR text may reference older SHAs: always fetch actual head.

### Dependency-driven waves (parallel only if files/scopes truly disjoint)

| Wave | Work groups | Admission / exit condition |
| --- | --- | --- |
| 0 — governance and inventory | #2742 (main/V2 protection), #2931 (default-branch watchdog limitation), master #2503 | Identify real policies, approvals and leases. Neither unresolved governance issue authorizes bypassing CI/branch rules; do not block independent safe coding unnecessarily. |
| 1 — authorization & SoD foundation | #2758, #2751, #2782, #2783, #2784, #2789, #2818, #2768, #2771, #2851, #2854, #2856, #2802, #2813, #2901 | Canonical allow/deny, scope, maker/checker and Sidebar project from real auth; unit + real HTTP tests. Only mark complete when their **individual** contracts pass. |
| 2 — operational ERP leaf flows | #2620, #2633, #2696, #2697, #2699, #2824, #2843, #2844, #2845, #2853, #2559 | Trusted stock-title/cost and finance settlement/credit/profit rules before dependent calculations; no cross-owner mutation. |
| 3 — commercial stages and readiness | #2705, #2706, #2707, #2709, #2714, #2858, #2840 | Real create→approve→post→reverse→report journeys, store/channel isolation and existing Admin navigation; readiness is not `ENABLED` alone. |
| 4 — physical, signed release path | #2745, #2746, #2791, #2846 | Same-commit production dependencies and assets; actual Setup ZIP and Update ZIP, signed trusted manifest + SHA-256, safe archive processing, preflight, full backup and restore validation, MySQL fresh-install and supported-upgrade tests, cPanel/shared hosting web installer/updater with zero shell commands for user. |
| 5 — exact-head integration & gates | #2744, #2748, #2750, #2742, #2503 | All applicable code leaves closed; final source-to-issue/matrix accounting, required CI on final integration head, signed artifacts, full end-to-end security/UX evidence and owner/UAT. No `main` promotion. |
| Privacy-sensitive account snapshots | #2906, #2907, #2916 | Authorized least-privilege source/current data required; do not copy identities, credentials or personal data into GitHub. Can implement privacy-safe audit and tests; keep real-data verification pending if unavailable. |
| Outside isolated V2 code backlog | #2502, #2468, #2461 | Live production acceptance and permanent store lanes. Cannot mark done from V2 CI or close permanent lanes automatically; require their own real production/store evidence or explicit owner decision. |

**Dependency checks to verify live, not blindly assume:** #2844 + #2853 + #2706 feed profitability #2559; #2633 + #2851 intersect AR role #2696; #2745 + #2746 feed distribution #2846/#2791; ERP stages + #2858 feed #2840; accepted leaves feed #2744/#2748/#2750/#2503. Keep issue-specific graph authoritative if it differs. Avoid circular gate dependencies by completing independently bounded code before the aggregate acceptance.

**Scope overrides:** Existing matrix/docs may still demand three APKs: the newer owner decision #2846/#2503 defers Flutter and APK work *for current V2 product acceptance*, not preservation tests or already-required GitHub checks. Never mark those deferred rows PASS, and never silently remove CI requirements.

## 4. Delivery evidence and reporting (every invocation)

Report concise, verifiable results, not optimism:
- `issue` / `original_branch` / `original_PR` / `current_HEAD` / `scope`;
- `source_complete` / `technical_review` / `CI run URLs and terminal conclusions` / `merge SHA` / `closed issue URL`;
- `open_total` and breakdown: `in_scope_code_leaves`, `gates_and_master`, `external_or_permanent`, `owned`, `blocked`, `open_draft_PRs` (fresh GitHub counts, do not assume 49 forever);
- missing requirement & owner/dependency issue for each blocker, the next **unowned** safe leaf candidate and handoff instructions.

**Stop/failover conditions:** Never merge the blocked issue if CI, review, source acceptance, ownership, external authority or branch protection is unsatisfied. However a **candidate-specific** dependency, failed CI on another owned branch, or external approval is not a reason to stop the **entire** `V2_CLOSE_NEXT` run: record that candidate as blocked, release or respect its ownership, and inspect the next safely writable independent candidate or original prerequisite. End only upon one verified merge+closure (three for `V2_CLOSE_3` if possible), true repository-wide actionable-queue exhaustion, or a hard tool/runtime/authorization limit for the invocation. State what was attempted and what was changed; never imply a future asynchronous worker will complete anything.

**Proof and packaging (Laravel standard):** `PROJECT-Setup-vX.Y.Z.zip` and `PROJECT-Update-vX.Y.Z.zip` must be physically built from the **same tested final commit**, include manifest, build-info, release notes, SHA-256 **and verified digital signature**; verify no traversal/symlink ZIP attacks, concurrency, Super Admin RBAC, backups, safe migrations, rollback/recovery limitations, fresh install / upgrade / data retention. Shared hosting may require user to create MySQL DB or set document root in cPanel; guide them without pretending this can be done without server permissions. CI success alone is not release acceptance.

## 5. Do not confuse plan installation with continuous execution

This playbook provides deterministic instructions **when invoked**; committing it does not launch workers, schedule jobs, grant approvals, close tickets, run CI or release anything. A coordinator may periodically refresh a dated snapshot as a **separate scoped PR**, but live GitHub is always the source of truth; do not mutate the plan on every single issue closure.

**Canonical handoff request to any connected coding worker:** `V2_CLOSE_NEXT`.
