'use strict';

// V2 opt-in CI Freeze: worker-owned immutable SHA comments, never bypass a green
// exact-head gate by pushing again. No write permissions and no auto-merge.
const FREEZE = '<!-- foodex-ci-freeze:v1 -->';
const FIX = '<!-- foodex-ci-fix:v1 -->';
const UNFREEZE = '<!-- foodex-ci-unfreeze:v1 -->';
const TRUSTED = new Set(['OWNER', 'MEMBER', 'COLLABORATOR']);
const SHA = /^[a-f0-9]{40}$/i;
const RED = new Set(['failure', 'timed_out', 'action_required', 'startup_failure']);

function events(comments) {
  const result = [];
  for (const comment of comments || []) {
    const body = String(comment.body || '');
    const marks = [[FREEZE, 'FREEZE'], [FIX, 'FIX'], [UNFREEZE, 'UNFREEZE']]
      .filter(([mark]) => body.includes(mark));
    if (!marks.length) continue;
    // Untrusted commenters cannot grant an exception or arm/clear a freeze.
    if (!TRUSTED.has(String(comment.author_association || '').toUpperCase())) continue;
    if (marks.length !== 1) throw Error('multiple-ci-freeze-markers');
    const [mark, kind] = marks[0];
    const text = body.slice(body.indexOf(mark) + mark.length);
    const fields = {};
    for (const line of text.split(/\r?\n/)) {
      const pair = line.match(/^\s*([A-Z_]+):\s*(.*?)\s*$/);
      if (!pair) continue;
      if (Object.prototype.hasOwnProperty.call(fields, pair[1])) throw Error('duplicate-freeze-field');
      fields[pair[1]] = pair[2];
    }
    result.push({ kind, fields, id: Number(comment.id) || 0,
      created: String(comment.created_at || '') });
  }
  return result.sort((a, b) => {
    const time = String(a.created).localeCompare(String(b.created));
    return time || a.id - b.id;
  });
}

async function validate({ currentHead, comments, getChecks }) {
  if (!SHA.test(currentHead || '')) return { ok: false, state: 'INVALID_HEAD' };
  let frozen = '';
  let ever = false;
  let fixes = 0;
  try {
    for (const entry of events(comments)) {
      ever = true;
      const f = entry.fields;
      if (entry.kind === 'FREEZE') {
        if (!SHA.test(f.HEAD || '') || (frozen && f.HEAD !== frozen))
          return { ok: false, state: 'INVALID_FREEZE' };
        frozen = f.HEAD;
      } else if (entry.kind === 'UNFREEZE') {
        if (!frozen || f.HEAD !== frozen || (f.REASON || '').trim().length < 12)
          return { ok: false, state: 'INVALID_UNFREEZE' };
        frozen = '';
      } else {
        if (!frozen || f.FROM !== frozen || !SHA.test(f.TO || '') ||
            !f.FAILING_CHECK || f.FROM === f.TO || typeof getChecks !== 'function')
          return { ok: false, state: 'INVALID_FIX' };
        const checks = await getChecks(f.FROM);
        if (!Array.isArray(checks) || !checks.some(check =>
          check && check.name === f.FAILING_CHECK && RED.has(check.conclusion) &&
          check.head_sha === f.FROM))
          return { ok: false, state: 'NO_VERIFIED_RED_CI' };
        frozen = f.TO;
        fixes += 1;
      }
    }
  } catch (err) {
    return { ok: false, state: 'INVALID_FREEZE_EVIDENCE', detail: String(err.message) };
  }
  if (frozen && frozen !== currentHead)
    return { ok: false, state: 'FROZEN_HEAD_CHANGED', frozen };
  return { ok: true, state: frozen ? 'FROZEN' : ever ? 'UNFROZEN' : 'NOT_ARMED',
    frozen: frozen || null, fixes };
}

async function listPages(fetch, args, collection) {
  const all = [];
  for (let page = 1; page <= 10; page += 1) {
    const response = await fetch({ ...args, per_page: 100, page });
    const data = Array.isArray(response?.data) ? response.data : response?.data?.[collection];
    if (!Array.isArray(data)) throw Error('invalid-ci-freeze-api-' + collection);
    all.push(...data);
    if (data.length < 100) {
      if (response?.data?.total_count > all.length) throw Error('incomplete-ci-freeze-api-' + collection);
      return all;
    }
  }
  throw Error('ci-freeze-pagination-limit');
}

async function resolve(github, { owner, repo, prNumber, currentHead }) {
  const comments = await listPages(args => github.rest.issues.listComments(args),
    { owner, repo, issue_number: prNumber }, 'comments');
  return validate({ currentHead, comments, getChecks: sha =>
    listPages(args => github.rest.checks.listForRef(args),
      { owner, repo, ref: sha }, 'check_runs') });
}

async function guardGithub({ github, context, core }) {
  if (context.payload.pull_request?.base?.ref !== 'Foodex_V2') return;
  const { owner, repo } = context.repo;
  const prNumber = context.payload.pull_request.number;
  const current = await github.rest.pulls.get({ owner, repo, pull_number: prNumber });
  const pr = current.data;
  if (pr.head?.repo?.full_name?.toLowerCase() !== (owner + '/' + repo).toLowerCase() ||
      pr.head?.sha !== context.payload.pull_request.head.sha ||
      pr.base?.ref !== 'Foodex_V2') {
    core.setFailed('PR head/base changed or belongs to untrusted fork; recheck exact SHA');
    return;
  }
  const freeze = await resolve(github, { owner, repo, prNumber, currentHead: pr.head.sha });
  core.info('PR CI Freeze: ' + JSON.stringify(freeze));
  if (!freeze.ok) {
    core.setFailed('V2 final CI Freeze: ' + freeze.state
      + '. Use exact SHA marker, proven red CI fix, or trusted logged unfreeze. Never bypass required checks.');
  }
}

module.exports = { FREEZE, FIX, UNFREEZE, events, validate, listPages, resolve, guardGithub };
