'use strict';

/**
 * Read-only, fail-closed FOODEX V2 worker resumption plan.
 * Remote GitHub refs/PRs/checks take precedence over commentary checkpoints.
 * Never creates a branch, issue, PR, label, merge or deployment.
 */
const MARK = '<!-- foodex-worker-checkpoint:v1 -->';
const STATE = '<!-- foodex-worker-state:v1 -->';
const MAX_PAGES = 20;

async function pages(fetch, opts, key, maxPages = MAX_PAGES) {
  const all = [];
  for (let page = 1; page <= maxPages; page++) {
    const res = await fetch({ ...opts, per_page: 100, page });
    const data = Array.isArray(res?.data) ? res.data : res?.data?.[key];
    if (!Array.isArray(data)) throw Error('incomplete-github-' + key + '-payload');
    all.push(...data);
    if (data.length < 100) {
      if (res.data?.total_count > all.length) throw Error('incomplete-github-' + key + '-search');
      return all;
    }
  }
  throw Error('github-' + key + '-pagination-cap');
}

function readCheckpoint(comments, issueNumber) {
  for (const item of [...(comments || [])].reverse()) {
    const body = item.body || '';
    const marker = body.indexOf(MARK);
    if (marker < 0) continue;
    try {
      const line = body.slice(marker + MARK.length).trim().split(/\r?\n/)[0];
      if (line.length > 8192) continue;
      const value = JSON.parse(line);
      if (!value || value.issue !== issueNumber || value.schema !== 1) continue;
      if (!Array.isArray(value.done) || !Array.isArray(value.remaining)) continue;
      if (value.done.some(x => typeof x !== 'string') ||
          value.remaining.some(x => typeof x !== 'string')) continue;
      return { ...value, comment_id: item.id };
    } catch (_) {
      // An invalid comment can never authorize skipping work or merging.
    }
  }
  return null;
}

function claimReferences(comments) {
  const refs = [];
  for (const item of comments || []) {
    const body = String(item.body || '');
    const at = body.lastIndexOf(STATE);
    if (at < 0) continue;
    const fields = {};
    for (const line of body.slice(at + STATE.length).split(/\r?\n/)) {
      const m = line.match(/^\s*([A-Z_]+):\s*(.*?)\s*$/);
      if (m) fields[m[1]] = m[2];
    }
    // Some historical owner claims appended known display-only notes to
    // the real branch. A Git ref cannot contain whitespace, but do not
    // strip arbitrary suffixes: unknown provenance must still block.
    // The P110 variants below are grounded in actual original Issue comments
    // whose exact branch/PR also exist on GitHub.
    const rawBranch = fields.BRANCH || fields.ORIGINAL_BRANCH || '';
    // P111 #2845 historic comment #6939031180 describes a past missing
    // branch, not a second Git ref. Accept only the exact SHA-bound
    // narrative grammar; the current GitHub PR and branch still constrain it.
    // Unknown prose, malformed SHA and mismatched PR remain blocked.
    const historicMissingBranch = rawBranch.match(
      /^original ((?:feat|feature|fix|test|chore|ci|docs|refactor|worker)\/[1-9]\d*-[A-Za-z0-9._-]+) absent; historical commit [a-f0-9]{40} still fetchable\. Restore exact original branch name at original historical commit, reopen original PR #([1-9]\d*) to Foodex_V2, keep Draft\.$/
    );
    // P135 #2858 historic claims append one of these *verified* display
    // annotations to a single real branch. Keep exact tails only; arbitrary
    // prose, unknown checksums and actual alternate branches remain blocked.
    const p135OriginalPr = rawBranch.match(/;\s*ORIGINAL_PR:\s*#([1-9]\d*) OPEN\/DRAFT -> Foodex_V2 ONLY; latest verified source HEAD [a-f0-9]{40}\. Previous worker WAITING_CI\/EDITING_RELEASED\. No peer-active file overlap, 26 other OPEN PRs inspected\.$/i);
    const p135Pr = rawBranch.match(/;\s*PR #([1-9]\d*) OPEN\/DRAFT → Foodex_V2 ONLY$/i);
    // Other historical issue claims include a PR number in descriptive
    // branch prose. Retain it as a provenance constraint, never as authority.
    const legacyOriginalPr = rawBranch.match(/;\s*PR\s+#([1-9]\d*)\s+original\s+DRAFT;\s*BASE\s+Foodex_V2\.?$/i);
    const inlinePr = p135OriginalPr || p135Pr || legacyOriginalPr;
    const explicitPr = (fields.PR || fields.ORIGINAL_PR || '').match(/^#?([1-9]\d*)(?:\b|$)/);
    const branch = historicMissingBranch ? historicMissingBranch[1] : rawBranch
      .replace(/;\s*(?:BASE|TARGET):.*$/i, '')
      .replace(/;\s*PR\s+#[1-9]\d*\s+original\s+DRAFT;\s*BASE\s+Foodex_V2\.?$/i, '')
      .replace(/\s+\|\s*BASE:\s*Foodex_V2(?:\s+ONLY)?$/i, '')
      .trim()
      .replace(/\s+\((?:EXISTING|preserve original|PRESERVE|ORIGINAL; KEEP|ORIGINAL|created, no code commit; preserve for successor|planned; base Foodex_V2 ONLY|EXISTING; preserve|preserved|ORIGINAL; PRESERVE|ORIGINAL, KEEP|ORIGINAL, PRESERVE|EXISTING ORIGINAL ONLY|PRESERVE ORIGINAL|ORIGINAL; NO NEW BRANCH)\)$/i, '')
      .replace(/\s+\|\s+FINAL_HEAD:\s+[a-f0-9]{40}$/i, '')
      .replace(/;\s*ORIGINAL_PR:\s*#[1-9]\d* OPEN\/DRAFT -> Foodex_V2 ONLY; latest verified source HEAD [a-f0-9]{40}\. Previous worker WAITING_CI\/EDITING_RELEASED\. No peer-active file overlap, 26 other OPEN PRs inspected\.$/i, '')
      .replace(/;\s*PR #[1-9]\d* OPEN\/DRAFT → Foodex_V2 ONLY$/i, '')
      .replace(/;\s*EXACT_START_HEAD:\s*[a-f0-9]{40}$/i, '')
      .replace(/\s+\|\s+START_HEAD:\s+[a-f0-9]{40}$/i, '')
      .replace(/\s+\|\s+BASE Foodex_V2 ONLY$/i, '')
      // Issue #2844 has a verified historical destination annotation in
      // BRANCH. Only a whole canonical issue-scoped ref plus this exact
      // display suffix may be normalized; all other prose stays fail-closed.
      .replace(/^((?:feat|feature|fix|test|chore|ci|docs|refactor|worker)\/[1-9]\d*-[A-Za-z0-9._-]+) -> Foodex_V2 ONLY$/, '$1');
    // Inlined PR identities are provenance constraints, not decoration.
    // Never turn conflicting explicit and annotated PR references into a pass.
    const claimedPr = explicitPr ? Number(explicitPr[1]) : null;
    const annotatedPr = historicMissingBranch ? Number(historicMissingBranch[2])
      : inlinePr ? Number(inlinePr[1]) : null;
    const conflictingIds = claimedPr && annotatedPr && claimedPr !== annotatedPr;
    if (branch || explicitPr || inlinePr) refs.push({
      branch: conflictingIds ? rawBranch : branch,
      pr: claimedPr || annotatedPr,
      state: fields.STATE || '',
    });
  }
  return refs;
}

function stateForPR(pr) {
  if (pr?.merged || pr?.merged_at) return 'MERGED';
  if (pr?.state === 'closed') return 'CLOSED_UNMERGED';
  if (pr?.state === 'open') return 'OPEN';
  return 'UNKNOWN';
}

// A CLOSED_UNMERGED PR is immutable audit evidence, but not a live worker
// when its branch has been deleted. Never let it replace an older open
// original, nor waive the live-branch or historic-provenance checks.
function reconcileClosedDuplicateHistory({ branches, prs, refs, checkpoint }) {
  const actual = prs.filter(pr => pr.base?.ref === 'Foodex_V2');
  const active = actual.filter(pr => stateForPR(pr) === 'OPEN');
  const closed = actual.filter(pr => stateForPR(pr) === 'CLOSED_UNMERGED');
  if (active.length !== 1 || closed.length === 0 ||
      active.length + closed.length !== actual.length) return null;
  const original = active[0];
  const head = original.head?.ref;
  if (typeof head !== 'string' || !head ||
      typeof original.head?.sha !== 'string' || !original.head.sha ||
      !Number.isSafeInteger(original.number) || original.number <= 0 ||
      branches.length !== 1 || branches[0] !== head) return null;

  const historicalHeads = new Set();
  const knownPrs = new Set([original.number]);
  for (const pr of closed) {
    const oldHead = pr.head?.ref;
    // A later closed duplicate may be ignored only after its remote ref
    // disappears. An older closed original cannot be silently replaced.
    if (!Number.isSafeInteger(pr.number) || pr.number <= original.number ||
        typeof oldHead !== 'string' || !oldHead || oldHead === head ||
        branches.includes(oldHead) || historicalHeads.has(oldHead)) return null;
    knownPrs.add(pr.number);
    historicalHeads.add(oldHead);
  }
  const knownHeads = new Set([head, ...historicalHeads]);
  if (refs.some(ref => (ref.branch && !knownHeads.has(ref.branch)) ||
      (ref.pr && !knownPrs.has(ref.pr)))) return null;
  if (checkpoint?.branch && !knownHeads.has(checkpoint.branch)) return null;
  return { action: 'RESUME_ORIGINAL_PR', branch: head, pr: original.number,
    head: original.head.sha, reason: 'older-open-original-after-closed-duplicate-cleanup' };
}

function decide({ issue, branches, prs, refs, checkpoint }) {
  if (!issue || issue.state !== 'open' || !/^V2(?:\s|[:|\[-])/i.test(issue.title || '')) {
    return { action: 'BLOCK_NOT_OPEN_V2', reason: 'not-active-v2-issue' };
  }
  const labels = (issue.labels || []).map(v => typeof v === 'string' ? v : v.name);
  if (labels.some(l => l.startsWith('gate:'))) {
    return { action: 'BLOCK_HUMAN_GATE', reason: 'explicit-approval-or-environment-gate' };
  }
  const reconciled = reconcileClosedDuplicateHistory({ branches, prs, refs, checkpoint });
  if (reconciled) return reconciled;
  const originals = new Set([...branches, ...refs.map(r => r.branch).filter(Boolean),
    ...(checkpoint?.branch ? [checkpoint.branch] : []),
    ...prs.map(pr => pr.head?.ref).filter(Boolean)]);
  const actual = prs.filter(pr => pr.base?.ref === 'Foodex_V2');
  if (new Set(actual.map(pr => pr.head?.ref)).size > 1 || originals.size > 1) {
    return { action: 'BLOCK_CONFLICTING_ORIGINALS', reason: 'multiple-issue-branches-or-prs' };
  }
  if (actual.length > 1) {
    return { action: 'BLOCK_DUPLICATE_PRS', reason: 'more-than-one-original-pr' };
  }
  const pr = actual[0];
  const original = [...originals][0] || null;
  // An archived inline PR identity still constrains the original, even
  // when its display-only branch suffix was normalized.
  if (pr && refs.some(ref => ref.pr && ref.pr !== pr.number)) {
    return { action: 'BLOCK_CONFLICTING_ORIGINALS', reason: 'historical-pr-id-conflict' };
  }
  if (pr) {
    const state = stateForPR(pr);
    if (state === 'OPEN') {
      // A missing canonical head SHA is not a safe restart point. Never
      // convert incomplete PR metadata into permission to resume or write.
      if (typeof pr.head?.ref !== 'string' || !pr.head.ref ||
          typeof pr.head?.sha !== 'string' || !pr.head.sha) {
        return { action: 'BLOCK_UNKNOWN_PR_HEAD', reason: 'missing-authoritative-pr-ref-or-sha' };
      }
      return { action: 'RESUME_ORIGINAL_PR', branch: pr.head.ref,
        pr: pr.number, head: pr.head.sha, reason: 'existing-pr-must-be-resumed' };
    }
    if (state === 'MERGED') {
      // Without the authoritative merge SHA the completed work cannot be
      // reconciled to V2 history or certified by a newer integration run.
      if (typeof pr.merge_commit_sha !== 'string' || !pr.merge_commit_sha) {
        return { action: 'BLOCK_UNKNOWN_MERGE_SHA',
          reason: 'merged-original-has-no-authoritative-merge-commit' };
      }
      return { action: 'VERIFY_INTEGRATION_ACCEPTANCE',
        branch: original, pr: pr.number, head: pr.merge_commit_sha,
        reason: 'original-pr-already-merged-no-new-pr' };
    }
    if (state === 'CLOSED_UNMERGED') return { action: 'BLOCK_CLOSED_ORIGINAL_PR',
      branch: original, pr: pr.number, reason: 'owner-reconciliation-required' };
    return { action: 'BLOCK_UNKNOWN_PR_STATE', reason: 'not-authoritative' };
  }
  // An existing original branch is not permission to take over an active
  // worker lease. Resolve the lease/watchdog first, even when no PR exists.
  if (labels.some(l => l === 'worker:active' || l === 'worker:waiting-ci')) {
    return { action: 'BLOCK_CURRENT_LEASE', branch: original,
      reason: 'active-worker-owns-issue' };
  }
  if (original) {
    // A misleading worker:ready label is not proof that an active owner
    // released the original branch. Treat a latest WORKING/ACTIVE claim as
    // an ambiguity requiring lease reconciliation, never as takeover consent.
    const latestClaim = refs.length ? refs[refs.length - 1] : null;
    if (latestClaim && /^(?:WORKING|ACTIVE)$/i.test(latestClaim.state) &&
        latestClaim.branch === original) {
      return { action: 'BLOCK_CLAIM_LEASE_CONFLICT', branch: original,
        reason: 'latest-worker-claim-not-reconciled' };
    }
    if (branches.includes(original)) return {
      action: 'RESUME_ORIGINAL_BRANCH', branch: original, reason: 'existing-work-no-new-branch'
    };
    return { action: 'BLOCK_MISSING_ORIGINAL_BRANCH', branch: original,
      reason: 'claimed-history-exists-but-ref-is-missing' };
  }
  return { action: 'CANDIDATE_NEW_BRANCH', reason: 'revalidate-atomically-before-claim' };
}

async function buildPlan({ github, owner, repo, issueNumber }) {
  if (!Number.isSafeInteger(issueNumber) || issueNumber < 1) throw Error('invalid-issue');
  const issue = (await github.rest.issues.get({ owner, repo, issue_number: issueNumber })).data;
  if (!issue || issue.pull_request) throw Error('not-an-issue');
  const comments = await pages(args => github.rest.issues.listComments(args),
    { owner, repo, issue_number: issueNumber }, 'comments');
  const checkpoint = readCheckpoint(comments, issueNumber);
  const refs = claimReferences(comments);
  // Query exact issue reference across *all states*, including merged PRs.
  const q = 'repo:' + owner + '/' + repo + ' is:pr in:title,body "#' + issueNumber + '"';
  const candidates = await pages(args => github.rest.search.issuesAndPullRequests(args),
    { q }, 'items');
  const foundBranches = await pages(args => github.rest.repos.listBranches(args),
    { owner, repo }, 'branches');
  const branchPattern = new RegExp('^(?:feat|feature|fix|test|chore|ci|docs|refactor|worker)/'
    + issueNumber + '-');
  const branches = foundBranches.map(b => b.name).filter(b => branchPattern.test(b));
  const prIds = new Set(candidates.map(i => i.number));
  for (const ref of refs) if (ref.pr) prIds.add(ref.pr);
  for (const b of branches) {
    const linked = await pages(args => github.rest.pulls.list(args),
      { owner, repo, base: 'Foodex_V2', state: 'all', head: owner + ':' + b }, 'pulls');
    for (const pr of linked) prIds.add(pr.number);
  }
  const prs = [];
  for (const number of [...prIds].sort((a,b)=>a-b)) {
    const pr = (await github.rest.pulls.get({ owner, repo, pull_number: number })).data;
    // Same branch name in a fork is not the canonical repository branch.
    // Only the original FOOD repository may own a resumable V2 issue PR.
    const headRepo = pr.head?.repo?.full_name;
    if (typeof headRepo !== 'string' ||
        headRepo.toLowerCase() !== (owner + '/' + repo).toLowerCase()) {
      // Missing repository identity may indicate a deleted fork; never
      // attribute that PR to the authoritative issue branch.
      continue;
    }
    // Title/body issue link or matching original issue branch is required.
    if (pr.base?.ref !== 'Foodex_V2') continue;
    // GitHub search returns incidental mentions of this issue in parent and
    // dependency lists. Only title ownership, an explicit PR closing/ref line,
    // an issue-scoped branch or the original claim can establish provenance.
    const titleOwnsIssue = new RegExp('^\\s*(?:V2\\s*[:|\\-]?\\s*)?#' + issueNumber + '(?:\\b|$)', 'i')
      .test(pr.title || '');
    const bodyOwnsIssue = new RegExp(
      '^\\s*(?:refs?|closes?|fix(?:es)?|resolves?)\\s*:?\\s*#' + issueNumber + '(?:\\b|$)', 'im'
    ).test(pr.body || '');
    const hasBranch = branchPattern.test(pr.head?.ref || '') ||
      branches.includes(pr.head?.ref);
    // A claim comment is an untrusted checkpoint, not PR ownership proof.
    // Require authoritative issue provenance on the PR or its branch.
    if (titleOwnsIssue || bodyOwnsIssue || hasBranch) prs.push(pr);
  }
  const result = decide({ issue, branches, prs, refs, checkpoint });
  const plan = { schema: 1, issue: issueNumber, base: 'Foodex_V2',
    action: result.action, reason: result.reason,
    original_branch: result.branch || null, original_pr: result.pr || null,
    original_head: result.head || null, actual_branches: branches,
    original_prs: prs.map(pr => ({ number: pr.number, branch: pr.head?.ref,
      state: stateForPR(pr), head: pr.head?.sha, merge_sha: pr.merge_commit_sha })),
    checkpoint: checkpoint ? { id: checkpoint.comment_id, recorded_head: checkpoint.head || null,
      done: checkpoint.done, remaining: checkpoint.remaining,
      next: checkpoint.next || null,
      matches_original_head: Boolean(result.head && checkpoint.head === result.head) } : null,
    warning: 'READ_ONLY: recheck live ownership, PR head and required exact-head CI before any write or merge.' };
  if (result.action === 'VERIFY_INTEGRATION_ACCEPTANCE') {
    const integration = await github.rest.repos.getBranch({ owner, repo, branch: 'Foodex_V2' });
    const sha = integration.data.commit?.sha || '';
    if (!sha) throw Error('missing-v2-integration-head');
    // A green run on the latest branch cannot certify an original PR if
    // its merge commit is no longer reachable (e.g. after a branch rewrite).
    const ancestry = await github.rest.repos.compareCommits({
      owner, repo, base: result.head, head: sha,
    });
    const ancestryStatus = ancestry?.data?.status;
    if (!['identical', 'ahead'].includes(ancestryStatus)) {
      plan.integration_head = sha;
      plan.integration_merge_reachable = false;
      plan.integration_acceptance = { status: 'blocked', conclusion: null, head: sha };
      plan.integration_accepted = false;
      plan.warning += ' Original PR merge commit is not an ancestor of the current V2 head.';
      return plan;
    }
    plan.integration_merge_reachable = true;
    const runs = await pages(args => github.rest.actions.listWorkflowRunsForRepo(args),
      // Both push and authorized workflow_dispatch acceptance runs are valid
      // when they certify the exact current integration SHA.
      { owner, repo, branch: 'Foodex_V2' }, 'workflow_runs');
    const relevant = runs.filter(r => r.name === 'FOODEX V2 Integrated Backend Acceptance'
      && r.head_sha === sha).sort((a,b) => b.id-a.id);
    plan.integration_head = sha;
    plan.integration_acceptance = relevant.length
      ? { status: relevant[0].status, conclusion: relevant[0].conclusion,
        run_id: relevant[0].id, head: relevant[0].head_sha }
      : { status: 'missing', conclusion: null, head: sha };
    plan.integration_accepted = plan.integration_acceptance.status === 'completed'
      && plan.integration_acceptance.conclusion === 'success'
      && plan.integration_acceptance.head === sha;
  }
  return plan;
}

module.exports = { MARK, pages, readCheckpoint, claimReferences, decide, buildPlan, stateForPR };
