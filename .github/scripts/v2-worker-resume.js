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
    const branch = (fields.BRANCH || fields.ORIGINAL_BRANCH || '')
      .replace(/;\s*(?:BASE|TARGET):.*$/i, '').trim();
    const pr = (fields.PR || fields.ORIGINAL_PR || '').match(/^#?([1-9]\d*)(?:\b|$)/);
    if (branch || pr) refs.push({ branch, pr: pr ? Number(pr[1]) : null, state: fields.STATE || '' });
  }
  return refs;
}

function stateForPR(pr) {
  if (pr?.merged || pr?.merged_at) return 'MERGED';
  if (pr?.state === 'closed') return 'CLOSED_UNMERGED';
  if (pr?.state === 'open') return 'OPEN';
  return 'UNKNOWN';
}

function decide({ issue, branches, prs, refs, checkpoint }) {
  if (!issue || issue.state !== 'open' || !/^V2(?:\s|[:|\[-])/i.test(issue.title || '')) {
    return { action: 'BLOCK_NOT_OPEN_V2', reason: 'not-active-v2-issue' };
  }
  const labels = (issue.labels || []).map(v => typeof v === 'string' ? v : v.name);
  if (labels.some(l => l.startsWith('gate:'))) {
    return { action: 'BLOCK_HUMAN_GATE', reason: 'explicit-approval-or-environment-gate' };
  }
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
    if (state === 'MERGED') return { action: 'VERIFY_INTEGRATION_ACCEPTANCE',
      branch: original, pr: pr.number, head: pr.merge_commit_sha || null,
      reason: 'original-pr-already-merged-no-new-pr' };
    if (state === 'CLOSED_UNMERGED') return { action: 'BLOCK_CLOSED_ORIGINAL_PR',
      branch: original, pr: pr.number, reason: 'owner-reconciliation-required' };
    return { action: 'BLOCK_UNKNOWN_PR_STATE', reason: 'not-authoritative' };
  }
  if (original) {
    if (branches.includes(original)) return {
      action: 'RESUME_ORIGINAL_BRANCH', branch: original, reason: 'existing-work-no-new-branch'
    };
    return { action: 'BLOCK_MISSING_ORIGINAL_BRANCH', branch: original,
      reason: 'claimed-history-exists-but-ref-is-missing' };
  }
  if (labels.some(l => l === 'worker:active' || l === 'worker:waiting-ci')) {
    return { action: 'BLOCK_CURRENT_LEASE', reason: 'active-worker-owns-issue' };
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
  const branchPattern = new RegExp('^(?:feat|fix|test|chore|ci|docs|refactor|worker)/'
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
    if (titleOwnsIssue || bodyOwnsIssue || hasBranch ||
        refs.some(r => r.pr === number)) prs.push(pr);
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
