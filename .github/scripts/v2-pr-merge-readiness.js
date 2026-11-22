'use strict';

const freezePolicy = require('./v2-pr-ci-freeze.js');
const RED = new Set(['failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure']);
const REQUIRED = ['required-ci-gate', 'Repository Policy', 'V2 target and safety contract'];

function latestByName(checks) {
  const map = new Map();
  for (const check of checks || []) {
    if (!check || typeof check.name !== 'string') continue;
    const old = map.get(check.name);
    if (!old || (Number(check.id) || 0) > (Number(old.id) || 0)) map.set(check.name, check);
  }
  return map;
}

function decision({ pr, expectedHead, expectedBase, integrationHead, checks, reviews = [], freeze, sourceComplete,
  technicalReview, issueVerified, issueLabels = [] }) {
  if (pr?.base?.ref !== 'Foodex_V2') return { action: 'NOT_V2', reason: 'wrong-base' };
  if (!pr || pr.state !== 'open') return { action: 'CLOSED', reason: 'no-open-pr' };
  const sha = pr.head?.sha || '';
  if (sha !== expectedHead || pr.base?.sha !== expectedBase)
    return { action: 'STALE_HEAD', reason: 'head-or-base-advanced' };
  if (!freeze || freeze.ok !== true) return { action: 'FIX_FREEZE', reason: freeze?.state || 'no-freeze-evidence' };
  if (!issueVerified) return { action: 'ISSUE_UNVERIFIED', reason: 'missing-open-original-issue' };
  const status = latestByName(checks);
  for (const name of REQUIRED) {
    const check = status.get(name);
    if (!check) return { action: 'WAIT_CI', reason: name + '-missing' };
    if (check.head_sha && check.head_sha !== sha)
      return { action: 'STALE_HEAD', reason: name + '-different-sha' };
    if (RED.has(check.conclusion)) return { action: 'FIX_CI', reason: name + '-' + check.conclusion };
    if (check.status !== 'completed' || check.conclusion !== 'success')
      return { action: 'WAIT_CI', reason: name + '-' + (check.conclusion || 'running') };
  }
  // A successful check at the event base cannot certify a moving integration branch.
  if (!integrationHead || integrationHead !== expectedBase)
    return { action: 'BASE_ADVANCED_REVALIDATE', reason: 'v2-tip-differs-from-tested-base' };
  // The terminal required-ci-gate already aggregates ALL applicable CI jobs.
  if (!sourceComplete) return { action: 'SOURCE_INCOMPLETE', reason: 'missing-source-complete-evidence' };
  if (!technicalReview) return { action: 'TECHNICAL_REVIEW_REQUIRED', reason: 'missing-head-bound-review' };
  if (pr.draft) return { action: 'MARK_READY_FOR_REVIEW', reason: 'green-draft' };
  if (pr.mergeable === false || pr.mergeable_state === 'dirty')
    return { action: 'RESOLVE_CONFLICTS', reason: 'merge-conflict' };
  if (pr.mergeable === null || !['clean', 'blocked', 'unstable'].includes(pr.mergeable_state))
    return { action: 'WAIT_MERGEABILITY', reason: 'unknown-mergeability' };
  const labels = [...(pr.labels || []), ...issueLabels].map(v => typeof v === 'string' ? v : v.name);
  if (labels.some(x => x?.startsWith('gate:')))
    return { action: 'REVIEW_REQUIRED', reason: 'explicit-human-gate' };
  if ((pr.requested_reviewers || []).length || (pr.requested_teams || []).length)
    return { action: 'REVIEW_REQUIRED', reason: 'outstanding-requested-review' };
  const latestReviews = new Map();
  for (const review of reviews) {
    const login = review?.user?.login;
    if (login) latestReviews.set(login, review.state);
  }
  if ([...latestReviews.values()].includes('CHANGES_REQUESTED'))
    return { action: 'REVIEW_REQUIRED', reason: 'review-changes-requested' };
  if (pr.mergeable_state !== 'clean')
    return { action: 'REVIEW_REQUIRED', reason: 'github-merge-gate-' + pr.mergeable_state };
  // Advice only. GitHub review protection, source authority and human approvals
  // are NOT waived; this script never executes merge, approval or issue mutation.
  return { action: 'READY_FOR_MERGE_REVIEW', reason: 'verified-green-recheck-human-gates' };
}

function exactTechnicalReview(body, head) {
  if (typeof body !== 'string' || !/^[a-f0-9]{40}$/i.test(head || '')) return false;
  return body.split(/\r?\n/).some(line => {
    const match = line.match(/^\s*TECHNICAL_REVIEW:\s*PASS\s*\|\s*HEAD:\s*([a-f0-9]{40})\s*$/i);
    return Boolean(match && match[1].toLowerCase() === head.toLowerCase());
  });
}

async function report({ github, context, core }) {
  const { owner, repo } = context.repo;
  const event = context.payload.pull_request;
  if (!event || event.base?.ref !== 'Foodex_V2') return;
  const number = event.number;
  const resp = await github.rest.pulls.get({ owner, repo, pull_number: number });
  const pr = resp.data;
  const checks = await freezePolicy.listPages(args => github.rest.checks.listForRef(args),
    { owner, repo, ref: pr.head.sha }, 'check_runs');
  const reviews = await freezePolicy.listPages(args => github.rest.pulls.listReviews(args),
    { owner, repo, pull_number: number }, 'reviews');
  const freeze = await freezePolicy.resolve(github, { owner, repo, prNumber: number, currentHead: pr.head.sha });
  const integration = await github.rest.repos.getBranch({ owner, repo, branch: 'Foodex_V2' });
  const integrationHead = integration.data?.commit?.sha || '';
  const issueMatch = String(pr.head?.ref || '').match(/^(?:feat|feature|fix|test|chore|ci|docs|refactor|worker)\/([1-9][0-9]*)-/);
  const issueNumber = issueMatch ? Number(issueMatch[1]) : null;
  const issue = issueNumber ? (await github.rest.issues.get({ owner, repo, issue_number: issueNumber })).data : null;
  const issueVerified = Boolean(issue && issue.state === 'open' && !issue.pull_request);
  const issueLabels = issue?.labels || [];
  const body = String(pr.body || '');
  const sourceComplete = /(?:^|\n)SOURCE_COMPLETE:\s*YES\b/im.test(body);
  const technicalReview = exactTechnicalReview(body, pr.head.sha);
  const result = decision({ pr, expectedHead: event.head.sha, expectedBase: event.base.sha,
    integrationHead, checks, reviews, freeze, sourceComplete, technicalReview,
    issueVerified, issueLabels });
  const reportData = { ...result, pr: number, expected_head: event.head.sha,
    live_head: pr.head.sha, base: pr.base?.sha, live_integration_base: integrationHead,
    issue_number: issueNumber, freeze: freeze.state, read_only: true };
  core.info('V2 PR Merge Readiness: ' + JSON.stringify(reportData));
  await core.summary.addHeading('FOODEX V2 merge readiness')
    .addCodeBlock(JSON.stringify(reportData, null, 2), 'json')
    .addRaw('The report is advisory: never substitutes for source completion, independent review, exact-head Required CI or protected merge. Drafts are NOT auto-promoted. No PR/issue/comment mutations.', true)
    .write();
  if (!['NOT_V2', 'CLOSED', 'READY_FOR_MERGE_REVIEW'].includes(result.action))
    core.notice('V2 PR #' + number + ': ' + result.action + ' — ' + result.reason);
}

module.exports = { RED, REQUIRED, latestByName, exactTechnicalReview, decision, report };
