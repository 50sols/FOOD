# FOODEX V2 — Phase 2 PR CI Freeze & Merge Readiness

Scope: Foodex_V2 only. All Required CI, security, RBAC/tenant, and release checks remain authoritative. No default-branch workflow promotion or production deployment.

## Operating procedure

1. Iterate normally in Draft while changes are still being consolidated. Run fast feedback locally / focused tests first; do not launch a final CI repeatedly for cosmetic updates. Existing full PR checks remain mandatory before merge.
2. When the source and targeted tests are complete, record a trusted PR conversation comment (association OWNER, MEMBER, or COLLABORATOR) using the exact SHA, then stop pushing changes unless CI exposes a real failure:

```text
<!-- foodex-ci-freeze:v1 -->
HEAD: <40-character exact PR head SHA>
```

3. Required PR Repository Policy and FOODEX Required CI both verify the current SHA against the frozen record. A changed head fails this contract unless the authorized worker records either a verified red-check correction, or a reasoned explicit unfreeze before revising the PR.

For a reproducible failing CI job on the frozen SHA, first identify the actual named failing GitHub check at that SHA; commit a focused fix to the original branch and record:

```text
<!-- foodex-ci-fix:v1 -->
FROM: <previous frozen head SHA>
TO: <new PR head SHA>
FAILING_CHECK: <exact failed GitHub check name>
```

The guard validates that check against GitHub's old SHA and re-freezes the new SHA. Cancelled/pending/green runs are not accepted as proof of failure. Multiple fixes each require an exact-head correction event.

For a planned review change rather than a failed check, add a trusted auditable comment before changing the code:

```text
<!-- foodex-ci-unfreeze:v1 -->
HEAD: <current frozen head SHA>
REASON: Planned reviewer-requested adjustment of <specific scope>
```

Then batch edits, run focused feedback, and arm a new SHA freeze when genuinely ready for final checks. Markers from untrusted commenters never authorize an exception. PR comments are an auditable coordination control, not a GitHub branch protection substitute.

## Merge Readiness Monitor

The independent read-only `V2 merge readiness monitor` job runs **after** the existing terminal `required-ci-gate` job on each Foodex_V2 PR CI event. Its job summary reports the exact current SHA and next action: wait for CI, fix red CI/freeze, complete source/review evidence, mark a green Draft ready for review, resolve conflict, seek human approval, or submit for merge review. It never mutates a PR, marks it ready, approves, merges, closes an Issue, or circumvents branch protection. If GitHub temporarily reports `unstable` because this advisory monitor is itself in progress, it may suggest `READY_FOR_MERGE_REVIEW` only when every other visible exact-head check is complete and nonfailing; all true external pending/red checks and human gates still block. This is a request for a final merge review, not an authorization to merge.

Before readiness is considered, the PR must explicitly declare `SOURCE_COMPLETE: YES` and `TECHNICAL_REVIEW: PASS | HEAD: <40-character SHA>` after real file-level review. Those markers do not replace required independent GitHub review approvals. A `gate:*` label or `CHANGES_REQUESTED` review blocks positive readiness advice. Only the latest exact PR head and target base can be considered. The monitor independently fetches the live Foodex_V2 branch tip and blocks positive readiness advice when the integration branch has advanced since the PR event. It also verifies the issue-scoped branch has an open original Issue, reads its `gate:*` labels, and treats requested reviewers/teams as outstanding human gates.

**Operational limitation:** the job is event-driven by Required CI; it does not run as a cloud daemon and does not continuously monitor approval changes when no new CI run occurs. GitHub's scheduled workflows are sourced from the default branch, so a schedule added to Foodex_V2 alone would not be active. Trigger fresh CI after a substantive review change or use the read-only preflight workflow for on-demand status.

## Safety

The freeze is explicit/opt-in and does not weaken non-frozen PR checks. We never consider a successful build alone proof of functional source completeness. Use one original Issue/branch/PR, log a worker state for CI recovery, and keep all existing full merge gates. A read-only readiness result is guidance rather than merge authorization.
