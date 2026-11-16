# FOODEX V2 — Resource-aware parallel coordination contract

**Owner authorization:** 2026-11-16; #3933  
**Program and integration base:** `Foodex_V2` only  
**Machine-readable contract:** [`V2_WORKER_CAPACITY.json`](V2_WORKER_CAPACITY.json)  
**Capacity:** No fixed maximum on open V2 Issues, active distinct workers, or issue-scoped work branches. Actual admission is governed by verified disjoint ownership, leases and available runtime/CI resources.

This changes the **maximum number of independently coordinated active workers**, not GitHub Actions runner count or ChatGPT account/session concurrency. No worker is started by committing this document. It does not modify `main`, enable production deployment, or turn on the unactivated V2 five-minute idle-lease workflow (see #2931).

## Suggested worker allocation

| Lane | Slots | File/scope boundary |
| --- | ---: | --- |
| Backend / API / ERP | Dynamic | Independent service/feature Issue fences; one owner per shared service |
| Dashboard / Finance | Dynamic | Disjoint modules/routes/views; finance, RBAC and shared UI interfaces reviewed |
| Customer / Driver / Van | Dynamic | One app lane each or equivalent verified non-overlapping app/backend fences |
| RBAC / Security | Dynamic | Own common permission/tenant/auth files; coordinate API/finance impacts |
| Setup ZIP / Admin Update ZIP / Backup & Recovery | Dynamic | Own installer/updater shared infrastructure and signed-release safety contracts |
| CI rescue / integration | Dynamic | Fix owned failed PRs by same-branch handoff; verify exact-head green before merge |
| Coordination / lease management | Dynamic | Admission, stale-lease verification, file-fence registry and handoffs; does not impersonate implementers |
| **Total** | **No fixed ceiling** | No overlapping ownership |

The distribution is a preferred schedule, **not** fixed authorization for any particular Issue. When a coordination worker is actually active it occupies a distinct non-overlapping scope; when absent, a qualified independent implementation worker may take that free slot without overlapping another worker's file ownership.

## Mandatory admission gate — before every new worker claim

1. Read the current `Foodex_V2` Issue, PR, branch, current-head CI and ownership records from GitHub (not saved chat summaries). Enumerate **all pages** of V2 Issues with `worker:active` or `worker:waiting-ci` and resolve their latest verifiable worker-state/lease, branch/PR activity and external gates. Consider any distinct active coordination worker not represented by an Issue lease.
2. De-duplicate each verified active **worker identity/session**; do not double-count an Issue's labels or CI runs as extra workers. Treat an active Issue with uncertain ownership as occupying a slot until independently reconciled. A worker waiting on current-head queued/running CI retains ownership and its slot. Do not assume that two ChatGPT chats using one GitHub login are the same worker.
3. Admit only when the candidate has available execution resources and a distinct, unclaimed, atomic Issue and disjoint file/subsystem ownership are proved. **Fail closed** if GitHub pagination, lease identity, CI, repository state or file-ownership information is unavailable; return to an existing owned PR or wait rather than oversubscribe.
4. Comment on the original Issue with `<!-- foodex-worker-state:v1 -->` plus `STATE`, `OWNER`, a unique `WORKER_ID`, `BRANCH`, `HEARTBEAT`, exact `SCOPE` and `NEXT_ACTION`; apply `worker:active`, then **re-fetch** Issue/PR and active roster before first edit. If a newer claim, capacity race, overlapping file fence or human gate appeared, do not edit or create a duplicate branch.
5. During execution, provide a verifiable checkpoint at least every 10 minutes; obey the strict 30-minute verified idle-lease expiry and actual exact-head running-CI protection. Never revoke a genuine peer's lease or erase a gate to increase available slots. The staged V2 watchdog is **not live on the default branch**; manual and authorized hourly coordinator checks remain necessary pending #2931.
6. At PR integration, require all relevant **SUCCESS checks on the exact final head**, audit and policy, base `Foodex_V2` only, protected ownership and verified merge. Release the coordination slot only when execution finishes or a safe, verified handoff/release occurs; close only genuinely accepted leaf issues.

## Existing mission exceptions and resource constraints

- The older standalone #1034 UI/UX mission in `AGENTS.md` retains its narrower six-lane rule **within that mission**. The no-fixed-cap rule applies to FOODEX V2, not legacy mission governance.
- GitHub-hosted CI concurrency, GitHub API limits, scheduling jitter, and available ChatGPT execution sessions are separate constraints. More slots can increase queue time; avoid redundant CI reruns and preserve required gates.
- ChatGPT scheduled automations are not increased or rescheduled by this repository policy; starting additional independent sessions/jobs is a **separate operational action**. Never report workers actually running without runtime evidence.
- All changes remain isolated from `main` and production; owner approval to increase V2 concurrency is **not** authorization for main merges or deployment.

## Impact classification

Coordination/documentation only. Backend/API: not affected. Dashboard: not affected. Customer: not affected. Driver: not affected. Van: not affected. No schema/migration, auth/tenant, API contract, user-facing AR/EN, compiled assets, release, ZIP, CI runner configuration, or production behavior changes.

## 2026-11-16 owner override (#3933)

Numeric issue, branch and worker-count thresholds are not admission blockers for V2. The preceding suggested allocation is illustrative only. Each new worker must verify live leases, original PR/branch, disjoint file ownership, available device/session and CI capacity, and GitHub rate limits. Reject overlap or resource exhaustion, never reject solely because a count exceeds 12. Never create duplicate branches for the same Issue. The policy itself does not spawn workers.
