# FOODEX V2 — OWNER-LOCKED ISOLATION & DELIVERY CONTRACT
Date: 2026-11-10 | Parent: #2503 | Integration: `Foodex_V2`

## Non-negotiable rules
1. **No V2 → main integration without a new explicit written owner instruction.** No main PR, cherry-pick, push, merge, tag, deployment, release or version promotion derived from V2 is authorized.
2. Every V2 Issue title MUST start with `V2`; create one issue-scoped implementation branch `feat/<issue>-...` or equivalent, and **base the PR on `Foodex_V2` only**. Before merging, verify `base.ref == Foodex_V2` and the latest exact-head required checks are GREEN. Any PR targeting `main` is invalid regardless of CI color.
3. `Foodex_V2` is an **owner-requested exception to ordinary issue-scoped branch naming**; only this permanent integration branch uses the exception. Do not create another V2 integration branch.
4. No direct feature commits to `Foodex_V2`; merge through reviewed PRs and preserve issue/PR/CI evidence. Do not use automatic merge, promotion or sync into main.
5. **Leaf coding completion (owner directive 2026-11-13, #2503 comment 6839665431):** Close a V2 implementation leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` only once **all** source-required Backend/Dashboard/Customer/Driver/Van programming and applicable automated security, RBAC, localization and functional tests are implemented, its **issue-scoped** PR is merged **only into `Foodex_V2`**, and every required exact-final-HEAD CI check is GREEN. The closure comment must cite the merged PR, tested SHA, integration SHA, CI runs and remaining human/staging/live UAT or attestation tracked by #2503 or an existing gate. **A partial PR or merely green CI never makes an incomplete source requirement code-complete.** Final manual UAT and consolidated business acceptance occur at the end of V2 coding, not before leaf code closure. **#2503 stays OPEN** until all coding is complete and the final comprehensive acceptance/audit passes on the exact isolated HEAD; neither leaf closure nor CI authorizes `main` or production promotion.
6. Preserve current production behavior and tenant contracts: B2B=VAN ONLY, B2C=DRIVER ONLY, store-scoped RBAC, secure identity lifecycle, finance maker/checker, audit and invariants.
7. V2 implementations must classify API/route/permission/role/navigation/user-status/finance/dispatch changes for **Customer, Driver, Van individually**. Create a `V2 | MOBILE ...` Issue for each actual affected app and link it to the originating backend issue. A claimed no-impact requires written evidence and contract regression checks. Do not postpone API or mobile parity until merge to main.
8. UI routes must be server-authorized. Sidebar visibility MUST be a projection of true permission/tenant/module/entitlement/readiness decisions; no independent visibility security policy. Platform Owner does not evade authentication, tenant or financial hard constraints.
9. Arabic/English, RTL/LTR, accessible desktop/mobile web, tests, storage/queue/cache/notifications, failure paths and security reviews are required for impacted surfaces.
10. Laravel Setup ZIP & Admin Update ZIP: one-ZIP install, web installer, Super Admin updater, authenticated/signed source, SHA-256, backups, migrations and recovery; exact packages must pass fresh-install and supported-upgrade MySQL CI. All three Android apps must build from same final V2 SHA. Produce **isolated staging artifacts**, never publish production tags or install on live cPanel without owner release order.
11. If a workflow, branch protection or repository settings allow a main merge, this **does not constitute owner authorization**. A dedicated GitHub ruleset requiring owner approval for `Foodex_V2` → `main` must be configured separately by a maintainer; a branch-contained workflow alone cannot fully enforce a main-base server-side ban.
12. No direct changes to original main policies or production artifacts to establish this V2 program.


## Owner-approved V2 staged independent-review policy (2026-11-14; #3615)

The owner decision in #2503 (comment 6880597654) permits **deferral of independent source review only for demonstrably low-risk, non-sensitive V2 leaf changes** until the consolidated V2 acceptance review. This does **not** defer code implementation, automated testing, or required GitHub enforcement. It does not authorize V2 promotion to `main`.

### Risk classification before merge

- **LOW / deferrable:** narrowly scoped, read-only presentation, copy, or literal search corrections where the existing server-side authorization, tenant visibility, persistence, public/mobile API contracts, and sensitive-data disclosure are unchanged. Record a file-by-file impact assessment, relevant negative RBAC/tenant tests, AR/EN coverage, and the reviewer-deferral rationale in the leaf PR and Issue. A search touching tenant/customer data is not automatically low risk: verify its scoped query and negative isolation tests; unresolved uncertainty is **HIGH**.
- **HIGH / never deferred:** authentication/identity, permissions/RBAC, tenant isolation, sensitive personal data exposure, finance/pricing/credit/settlement, order fulfillment/dispatch, Customer/Driver/Van security-relevant API behavior, migrations/data integrity, installer/update ZIP, backups/recovery, signing/provenance, CI/policy enforcement, or changes with unclear cross-module impact. An independent qualified `APPROVED` review remains required **before** merge.
- **Unknown or mixed scope:** treat as HIGH until narrowed or independently reviewed. Do not relabel a high-risk PR as low-risk merely to accelerate closure.

### Technical merge and closure gate

1. Existing issue-scoped branch and PR only, target `Foodex_V2`; no direct integration commit, `main` PR, release or deployment.
2. Complete the source-required programming and applicable Laravel/Flutter/security/RBAC/tenant/AR-EN checks. Required CI, Repository Policy, V2 Isolation and all applicable checks must be **GREEN on the exact final PR head**. No waivers for failures, pending runs, or missing required jobs.
3. Respect actual GitHub branch protection/rulesets and unresolved blocking reviews. A required server-side approval **cannot** be self-approved, administratively bypassed or silently replaced with this policy. If GitHub requires approval, obtain it or separately configure a reviewed and authorized policy; this document does not change GitHub settings.
4. For a verified LOW leaf with no required GitHub independent-approval gate, record `REVIEW_RISK: LOW`, `INDEPENDENT_REVIEW: DEFERRED_TO_V2_FINAL`, code/CI evidence, and the final-audit tracking reference #2503. For HIGH leaves record independent reviewer identity, approval URL, and tested head. Preserve `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` separately.
5. Merge only after all applicable gates pass; then close the completed leaf as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with PR URL, tested SHA, integration SHA, CI run links and any deferred review/UAT. A green PR without a merge is not a closed leaf.
6. Keep #2503 open and maintain a final independent-review register of all LOW deferred leaves, plus security/staging/manual acceptance, until each is reviewed and resolved on the frozen final V2 head. Any discovered defect reopens its leaf or creates a linked corrective issue before final acceptance.

**Server-side enforcement limitation:** GitHub's existing main ruleset does not enforce V2-specific owner approval (#2742). This policy must never be interpreted as authorization to relax `main` protection or promote V2. A ruleset/branch-protection change, if required, needs explicit authorized maintainer configuration and independent verification; the repository text alone is not technical enforcement.

## Mandatory V2 Issue template
- Source document section/paragraph/table row or sidebar line identifier.
- Exact actor/persona, entry point, data and permission scope, role/module/entitlement/readiness guard.
- Reuse → Extend → Compose → New Screen rationale and correct menu location.
- Backend/API, Dashboard, Customer App, Driver App and Van App impact matrix: each `affected` has a linked issue, or evidence-backed `not affected`.
- Definition of Done, AR/EN, RTL, accessibility, tests (functional, negative, cross-tenant, roles) and CI IDs.
- Implementation branch/PR targeting `Foodex_V2` + exact commit SHA on merge; NO production deployment claim.

## Required GitHub gates
- `FOODEX V2 Isolation Gate` applies to PRs targeting `Foodex_V2`.
- Existing `Repository Policy`, `Required CI Gate`, `Release Validation` and domain/mobile test workflows must run green on latest head where applicable.
- Before integration, review proposed PR base/head in GitHub; reject if base is `main` or if PR introduces production release tags/automatic main sync.
- The final V2 promotion protocol (only if owner later authorizes): frozen exact source SHA → independent review, exhaustive acceptance → formally approved PR to main → protected main required checks → published signed release → cPanel production verification. This protocol is **not authorized by current instruction**.

## Progress and traceability
Master #2503 coordinates the final source, UI, mobile, security, release and manual acceptance registers. Each implementation leaf keeps one owner/branch/PR, and is closed for verified coding only under Rule 5. Record deferred UAT/attestation against #2503 or an existing gate so closure never disguises unfinished acceptance. Maintain a source-line-to-Issue manifest on `Foodex_V2`; never mark a missing requirement or partial implementation as complete.

## V2 worker lease TTL — automatically released after 30 minutes without work

- Every V2 worker MUST claim an unowned Issue using a fresh public Issue comment with `<!-- foodex-worker-state:v1 -->` and fields `STATE: ACTIVE`, `OWNER: <github-login>`, `BRANCH: <existing issue branch or planned issue-scoped name>`, `HEARTBEAT: <UTC ISO 8601>`. Add label **`worker:active`** as part of the claim; this label makes the Issue eligible for the fast V2 sweeper. Verify no other valid claim or overlapping file-owner exists immediately before posting.
- A **30-minute idle TTL** is measured from the claim or the most recent independently verifiable commit on the owned branch/PR. **Comments, idle status pings, and self-declared heartbeat updates alone do NOT prove work** and do not reset the 30-minute clock. An active exact-head CI run keeps the lease safe while the CI job is genuinely running. Explicit production/human blockers must not be mistaken for permission to deploy.
- When 30 minutes elapse without verified activity, V2 watchdog removes active/waiting labels, assigns **`worker:handoff-ready`**, records a release comment, and makes the Issue free for another worker to claim. The original branch and PR remain intact: successor MUST continue them rather than creating duplicates.
- A worker who returns after an automatic release must stop until they revalidate ownership; if another worker has claimed the Issue, they must hand over. A superseding claim always wins.
- **Operational activation:** staged code `.github/scripts/v2-lease-watchdog.js` and workflow `.github/workflows/v2-idle-lease-watchdog.yml` request a sweep every **5 minutes**. GitHub only runs scheduled workflows defined on the **default branch**. Since owner has expressly forbidden modifying/promotion to `main`, this scheduled watcher is NOT YET AUTOMATICALLY RUNNING and must not be described as enabled. Future approval of a narrowly scoped default-branch dispatcher or a separate external 5-minute runner is required to enable unattended execution; no V2 feature changes may go to main under the present authority.
- The general repository watchdog on `main` runs hourly and its behavior is not an exact 30-minute V2 lease-enforcement guarantee. Until V2 watcher activation, workers must also manually revalidate leases before taking over and before every meaningful push/PR.
