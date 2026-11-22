# FOODEX V2 — Worker Continuity and Resumption

Scope: `Foodex_V2` backend ERP and CI governance only. This protocol does not authorize changes to `main`, production, releases or Flutter applications.

## Re-enter an existing Issue before doing work

1. Read the original Issue, labels, comments and dependencies. Search *all* pull request states (open, merged, closed without merge), not just open PRs; inspect issue-linked branch history.
2. Run **FOODEX V2 Worker Resume Preflight** via `workflow_dispatch` on the V2 workflow with `issue_number`. The workflow is **read-only**: it emits an Action summary containing the original branch, PR, latest authoritative SHA, checkpoint and next action. It cannot spawn a ChatGPT worker or create, merge or close a PR.
3. If the report says `RESUME_ORIGINAL_PR`, use that PR/branch; if `RESUME_ORIGINAL_BRANCH`, finish the existing branch and open at most one PR. Never create a replacement merely because the newest lease expired.
4. A merged PR with an open Issue yields `VERIFY_INTEGRATION_ACCEPTANCE`. Compare the **current** `Foodex_V2` SHA with the completed **FOODEX V2 Integrated Backend Acceptance** workflow for that *same SHA*. A green run on an older SHA never certifies the new integration head. If missing, pending or failed, keep the Issue open and investigate without duplicating the completed implementation.
5. For `BLOCK_*` outputs, reconcile ambiguous original branches/PRs, conflicting worker claims, human approvals or missing refs before changing code. `CANDIDATE_NEW_BRANCH` is only a recommendation; recheck live leases and ownership before any write.

## Durable checkpoint comments

Use a comment with a standalone marker followed immediately by a single JSON line:

```text
<!-- foodex-worker-checkpoint:v1 -->
{"schema":1,"issue":4151,"branch":"ci/4151-v2-resumable-worker-recovery","pr":null,"head":"<verified-head-sha>","done":["unit tests"],"remaining":["exact-head CI","security review"],"next":"resume original PR"}
```

The checkpoint records progress, **not authorization**. Always re-fetch PR head, base, ownership, last commit, CI runs, review state and permissions from GitHub. Never let stale text grant approval, renew a lease, override failing checks or bypass a required gate. Do not put credentials, private user information or production data in comments.

## Ownership and safe handoff

An Issue must have one authoritative original branch and PR chain. Maintain an exclusive changed-file fence; compare it with all *open* V2 PRs and reconcile historic conflicts, especially when another PR merges. Avoid parallel edits to the same file. Record ownership, latest SHA, completed and remaining tests and the exact next action when handing off. Release an idle worker label only after the watchdog verifies its policy and rechecks live branch/PR/CI state; do not delete the original branch or PR as part of handoff.

The lease watchdog must preserve merged/closed PR history and protect live relevant CI from false idle release. The staged V2 scheduled watcher is **not an active every-five-minute service**: GitHub scheduled workflows run from the repository default branch, and permission to modify `main` has not been granted. An authorized external dispatcher or a separately approved default-branch-only dispatcher is needed for automatic five-minute checks.

## CI and integrated acceptance

The V2 integrated backend workflow runs after pushes to `Foodex_V2` and can be dispatched by an authorized operator. `cancel-in-progress: false` avoids canceling long Laravel/MySQL/Redis acceptance when another merge occurs. Pending newer runs may queue; final certification always requires a successful run on the **latest** integration SHA, not just any successful historical run.

Before marking Ready for Review or merging a HIGH security PR, verify on its immutable last head SHA: Repository Policy, V2 Isolation Gate, Required CI Gate including Laravel full tests, MySQL/Redis deployment acceptance and AR/EN. Obtain the independent security approval required by repository policy. Use expected-head-SHA guarded merge only after approval and branch-protection checks, and verify the actual merged PR, integrated SHA and Issue closed/completed before recording closure.

## Acceptance and recovery evidence

Exercise resume fixtures for open, merged, closed-unmerged, missing and duplicate PRs, stale checkpoints, branch conflicts, race conditions, pagination over 100 records, active integrated CI and obsolete success. Run the Node test suites for the preflight and watchdog, the Python deployment-risk regression and all required GitHub workflows. If an Action is queued, cancelled or fails, report its precise status instead of claiming success. No change to `Foodex_V2` alone activates default-branch scheduled dispatch or an autonomous worker process.
