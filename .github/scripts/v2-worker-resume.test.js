'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { pages, readCheckpoint, claimReferences, decide, buildPlan } = require('./v2-worker-resume');

const issue = { number: 4151, state: 'open', title: 'V2 | safe worker resume', labels: [] };
const base = { ref: 'Foodex_V2' };
const pr = (state='open', branch='ci/4151-resume', number=4152) => ({
  number, state, merged: state === 'merged', merged_at: state === 'merged' ? '2026-11-22' : null,
  merge_commit_sha: state === 'merged' ? 'integration-sha' : null,
  head: { ref: branch, sha: 'head-sha' }, base,
  title: 'V2 #4151 — test', body: 'Closes #4151'
});
test('no original allows only CANDIDATE, never creates anything', () => {
  assert.equal(decide({ issue, branches:[], prs:[], refs:[], checkpoint:null }).action,
    'CANDIDATE_NEW_BRANCH');
});
test('open original PR must be resumed on its exact SHA', () => {
  const d=decide({issue,branches:['ci/4151-resume'],prs:[pr()],refs:[],checkpoint:null});
  assert.deepEqual([d.action,d.pr,d.head],['RESUME_ORIGINAL_PR',4152,'head-sha']);
});
test('open original with missing head metadata blocks resumption instead of guessing', () => {
  for (const incomplete of [
    { ...pr(), head: null },
    { ...pr(), head: { ref: 'ci/4151-resume' } },
    { ...pr(), head: { ref: '', sha: 'head-sha' } },
    { ...pr(), head: { ref: 'ci/4151-resume', sha: '' } },
  ]) {
    const x = decide({ issue, branches: ['ci/4151-resume'], prs: [incomplete],
      refs: [], checkpoint: null });
    assert.equal(x.action, 'BLOCK_UNKNOWN_PR_HEAD');
  }
});

test('merged original PR cannot be re-created even when issue is open', () => {
  const d=decide({issue,branches:[],prs:[pr('merged')],refs:[],checkpoint:null});
  assert.deepEqual([d.action,d.head],['VERIFY_INTEGRATION_ACCEPTANCE','integration-sha']);
});
test('merged original without authoritative merge SHA cannot be certified', () => {
  const missingMergeSha = { ...pr('merged'), merge_commit_sha: null };
  const d=decide({issue,branches:[],prs:[missingMergeSha],refs:[],checkpoint:null});
  assert.equal(d.action,'BLOCK_UNKNOWN_MERGE_SHA');
  assert.equal(d.reason,'merged-original-has-no-authoritative-merge-commit');
});

test('closed unmerged original needs reconciliation, not replacement branch', () => {
  assert.equal(decide({issue,branches:[],prs:[pr('closed')],refs:[],checkpoint:null}).action,
    'BLOCK_CLOSED_ORIGINAL_PR');
});
test('closed later duplicate with deleted remote ref preserves older open original', () => {
  const original=pr('open', 'fix/4151-original', 4152);
  const duplicate=pr('closed', 'fix/4151-duplicate', 4153);
  const refs=[
    {branch: 'fix/4151-duplicate', pr: 4153, state: 'ACTIVE'},
    {branch: 'fix/4151-original', pr: 4152, state: 'WAITING_CI'},
  ];
  const d=decide({issue,branches:['fix/4151-original'],
    prs:[original,duplicate],refs,checkpoint:null});
  assert.deepEqual([d.action,d.pr,d.branch,d.head],
    ['RESUME_ORIGINAL_PR',4152,'fix/4151-original','head-sha']);
});

test('closed-duplicate reconciliation fails closed on live, missing and ambiguous ownership', () => {
  const original=pr('open','fix/4151-original',4152);
  const duplicate=pr('closed','fix/4151-duplicate',4153);
  const safe={issue,branches:['fix/4151-original'],
    prs:[original,duplicate],refs:[],checkpoint:null};
  const reject=[
    {...safe,branches:['fix/4151-original','fix/4151-duplicate']},
    {...safe,branches:[]},
    {...safe,branches:['fix/4151-other']},
    {...safe,prs:[original,pr('open','fix/4151-duplicate',4153)]},
    {...safe,prs:[original,pr('merged','fix/4151-duplicate',4153)]},
    {...safe,prs:[pr('closed','fix/4151-duplicate',4151),original]},
    {...safe,refs:[{branch:'fix/4151-unrelated',pr:null,state:'ACTIVE'}]},
    {...safe,refs:[{branch:'fix/4151-original',pr:9999,state:'ACTIVE'}]},
    {...safe,checkpoint:{branch:'fix/4151-unrelated'}},
    {...safe,prs:[{...original,head:{ref:original.head.ref,sha:''}},duplicate]},
    {...safe,prs:[original,{...duplicate,head:{ref:original.head.ref,sha:'old'}}]},
  ];
  for (const input of reject) {
    assert.notEqual(decide(input).action,'RESUME_ORIGINAL_PR',
      'conflicting or unverifiable original must remain blocked');
  }
});

test('orphan branch and stale claim force safe reuse or block', () => {
  assert.equal(decide({issue,branches:['ci/4151-resume'],prs:[],refs:[],checkpoint:null}).action,
    'RESUME_ORIGINAL_BRANCH');
  assert.equal(decide({issue,branches:[],prs:[],refs:[{branch:'ci/4151-resume'}],checkpoint:null}).action,
    'BLOCK_MISSING_ORIGINAL_BRANCH');
});
test('active or waiting CI lease blocks takeover of existing original branch without PR', () => {
  for (const label of ['worker:active', 'worker:waiting-ci']) {
    const d=decide({issue:{...issue,labels:[{name:label}]},
      branches:['ci/4151-resume'],prs:[],
      refs:[{branch:'ci/4151-resume'}],checkpoint:null});
    assert.equal(d.action,'BLOCK_CURRENT_LEASE');
    assert.equal(d.branch,'ci/4151-resume');
  }
  const available=decide({issue,branches:['ci/4151-resume'],
    prs:[],refs:[],checkpoint:null});
  assert.equal(available.action,'RESUME_ORIGINAL_BRANCH');
});

test('ready label cannot override latest unreconciled WORKING branch claim', () => {
  const claim={branch:'ci/4151-resume',pr:null,state:'WORKING'};
  const data={issue:{...issue,labels:[{name:'worker:ready'}]},
    branches:['ci/4151-resume'],prs:[],refs:[claim],checkpoint:null};
  const result=decide(data);
  assert.equal(result.action,'BLOCK_CLAIM_LEASE_CONFLICT');
  assert.equal(result.branch,'ci/4151-resume');
  assert.equal(result.reason,'latest-worker-claim-not-reconciled');

  // A later authoritative handoff claim can release the ambiguity.
  assert.equal(decide({...data,refs:[claim,{...claim,state:'HANDOFF_READY'}]}).action,
    'RESUME_ORIGINAL_BRANCH');
});

test('multiple branches, duplicate PRs, human gates and leases fail closed', () => {
  assert.equal(decide({issue,branches:['ci/4151-a','ci/4151-b'],prs:[],refs:[],checkpoint:null}).action,
    'BLOCK_CONFLICTING_ORIGINALS');
  assert.equal(decide({issue,branches:[],prs:[pr(),pr('open','ci/4151-resume',4153)],refs:[],checkpoint:null}).action,
    'BLOCK_DUPLICATE_PRS');
  assert.equal(decide({issue:{...issue,labels:[{name:'gate:approval'}]},branches:[],prs:[],refs:[]}).action,
    'BLOCK_HUMAN_GATE');
  assert.equal(decide({issue:{...issue,labels:[{name:'worker:active'}]},branches:[],prs:[],refs:[]}).action,
    'BLOCK_CURRENT_LEASE');
});
test('durable checkpoints never change remote PR truth', () => {
  const body = '<!-- foodex-worker-checkpoint:v1 -->\n'
    + JSON.stringify({schema:1,issue:4151,branch:'ci/4151-resume',
      head:'obsolete-head',done:['tests'],remaining:['deploy'],next:'CI'});
  const checkpoint=readCheckpoint([{id:10,body}],4151);
  assert.equal(checkpoint.comment_id,10);
  assert.deepEqual(checkpoint.remaining,['deploy']);
  assert.equal(readCheckpoint([{id:10,body}],999),null);
  assert.equal(readCheckpoint([{id:11,body:'<!-- foodex-worker-checkpoint:v1 -->\n{broken'}],4151),null);
});
test('issue claim references survive newer status checkpoints', () => {
  const c=claimReferences([{body:'<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\n'
    + 'OWNER: test\nBRANCH: ci/4151-resume; BASE: Foodex_V2 ONLY\nPR: #4152 (DRAFT)'}]);
  assert.equal(c[0].branch,'ci/4151-resume');
  assert.equal(c[0].pr,4152);
});
test('historical annotated BRANCH claims reuse the one authoritative original PR', () => {
  const original='fix/2746-v2-updater-host-readiness-ui';
  const claims=claimReferences([
    {body:'<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\n'
      + 'BRANCH: '+original+' (EXISTING)\nPR: #3968'},
    {body:'<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\n'
      + 'ORIGINAL_BRANCH: '+original+' (preserve original)\nORIGINAL_PR: #3968'},
    {body:'<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\n'
      + 'BRANCH: '+original+'; TARGET: Foodex_V2 ONLY\nPR: #3968'},
  ]);
  assert.deepEqual(claims.map(ref => ref.branch),[original,original,original]);
  const result=decide({
    issue:{number:2746,state:'open',title:'V2 | signed update gate',labels:[]},
    branches:[original],
    prs:[{number:3968,state:'open',head:{ref:original,sha:'exact-head'},
      base:{ref:'Foodex_V2'}}],
    refs:claims,checkpoint:null,
  });
  assert.deepEqual([result.action,result.pr,result.head],
    ['RESUME_ORIGINAL_PR',3968,'exact-head']);
});

test('P111 past missing-branch sentence resolves only the real original issue branch and PR', () => {
  const original = 'test/2845-v2-erp-entitlement-maker-checker-boundaries';
  const sha = 'ad4dbc638ec181e2d82bf7cafbcd65e112bbe7b4';
  const narrative = 'original ' + original + ' absent; historical commit ' + sha
    + ' still fetchable. Restore exact original branch name at original historical commit, '
    + 'reopen original PR #3206 to Foodex_V2, keep Draft.';
  const comment = value => ({body:'<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\nBRANCH: '+value});
  const refs = claimReferences([comment(narrative)]);
  assert.deepEqual(refs, [{branch:original,pr:3206,state:'HANDOFF_READY'}]);
  const scope = {issue:{number:2845,state:'open',title:'V2 | ERP P111',labels:[]},
    branches:[original],prs:[{number:3206,state:'open',
      head:{ref:original,sha:'current-authoritative-pr-sha'},base:{ref:'Foodex_V2'}}],
    checkpoint:null};
  const result = decide({...scope,refs});
  assert.deepEqual([result.action,result.pr,result.head],
    ['RESUME_ORIGINAL_PR',3206,'current-authoritative-pr-sha']);

  // Never launder a different PR, old branch, malformed hash, or extra prose.
  for (const altered of [
    narrative.replace('PR #3206','PR #3207'),
    narrative.replace(original,'test/2845-fake-original'),
    narrative.replace(sha,'not-a-sha'),
    narrative + ' UNKNOWN',
    narrative.replace('keep Draft.','keep Draft. Overridden'),
  ]) {
    assert.equal(decide({...scope,refs:claimReferences([comment(altered)])}).action,
      'BLOCK_CONFLICTING_ORIGINALS',altered);
  }
  const conflictingExplicitPr = claimReferences([{
    body:comment(narrative).body+'\nPR: #3207'
  }]);
  assert.equal(decide({...scope,refs:conflictingExplicitPr}).action,
    'BLOCK_CONFLICTING_ORIGINALS');
  assert.equal(decide({...scope,refs,branches:[original,'test/2845-other-live']}).action,
    'BLOCK_CONFLICTING_ORIGINALS');
  assert.equal(decide({...scope,refs,prs:[...scope.prs,{...scope.prs[0],number:3207}]}).action,
    'BLOCK_DUPLICATE_PRS');
  assert.equal(decide({...scope,refs,issue:{...scope.issue,labels:[{name:'gate:approval'}]}}).action,
    'BLOCK_HUMAN_GATE');
});

test('historical P110 display notes do not fabricate alternate issue branches', () => {
  const original = 'test/2844-v2-vendor-ledger-ownership-isolation';
  const notes = [
    original + ' (created, no code commit; preserve for successor)',
    original + ' (ORIGINAL)',
    original + ' | FINAL_HEAD: 1c72dfe8f7eab2de9458b57404a290c200a2648a',
    // Real #2844 issue comment 7128797414 appends the V2 target
    // to BRANCH; it is not a second remote ref.
    original + ' -> Foodex_V2 ONLY',
  ];
  const refs = claimReferences(notes.map(branch => ({
    body: '<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\n'
      + 'BRANCH: ' + branch + '\nPR: #3921',
  })));
  assert.deepEqual(refs.map(ref => ref.branch), Array(notes.length).fill(original));
  const shared = {
    issue: { number: 2844, state: 'open', title: 'V2 | ERP P110', labels: [] },
    branches: [original],
    prs: [{
      number: 3921, state: 'open',
      head: { ref: original, sha: 'exact-head' },
      base: { ref: 'Foodex_V2' },
    }],
    refs,
    checkpoint: null,
  };
  const result = decide(shared);
  assert.deepEqual([result.action, result.pr, result.head],
    ['RESUME_ORIGINAL_PR', 3921, 'exact-head']);

  // Similar text must not excuse another branch, malformed SHA, or
  // unapproved suffix. All unknown provenance stays fail-closed.
  for (const decorated of [
    original + ' | FINAL_HEAD: not-a-sha',
    original + ' | FINAL_HEAD: ' + 'a'.repeat(39),
    original + ' (ORIGINAL) extra',
    original + ' (created, no code commit; preserve for successor) trailing',
    'test/2844-unrelated (ORIGINAL)',
    original + ' -> Foodex_V2 ONLY extra',
    original + ' -> main ONLY',
    original + ' -> Foodex_V2 ONLY; unexpected',
    'test/2844-forged-other -> Foodex_V2 ONLY',
  ]) {
    const conflicting = claimReferences([{
      body: '<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\n'
        + 'BRANCH: ' + decorated + '\nPR: #3921',
    }]);
    assert.equal(decide({ ...shared, refs: conflicting }).action,
      'BLOCK_CONFLICTING_ORIGINALS');
  }
  // A valid display suffix never overrides a conflicting historical PR ID.
  const mismatchedPr = claimReferences([{body:
    '<!-- foodex-worker-state:v1 -->\\nSTATE: ACTIVE\\nBRANCH: '
    + original + ' -> Foodex_V2 ONLY\\nPR: #3922',
  }]);
  assert.equal(decide({ ...shared, refs: mismatchedPr }).action,
    'BLOCK_CONFLICTING_ORIGINALS');
});

test('historical P135 inline metadata reuses its one original PR without hiding conflicting identities', () => {
  const original = 'feat/2858-v2-erp-operational-workflow-evidence';
  const sha = '4abd782507851c52e7de74680de0557141ec208c';
  const annotations = [
    original + '; ORIGINAL_PR: #3065 OPEN/DRAFT -> Foodex_V2 ONLY; latest verified source HEAD '
      + sha + '. Previous worker WAITING_CI/EDITING_RELEASED. No peer-active file overlap, 26 other OPEN PRs inspected.',
    original + '; PR #3065 OPEN/DRAFT → Foodex_V2 ONLY',
    original + '; EXACT_START_HEAD: ' + sha,
    original + '; EXACT_START_HEAD: ' + '9'.repeat(40),
    original + ' | START_HEAD: ' + sha,
    original + ' | BASE Foodex_V2 ONLY',
  ];
  const claims = claimReferences(annotations.map(branch => ({
    body: '<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\n'
      + 'BRANCH: ' + branch,
  })));
  assert.deepEqual(claims.map(ref => ref.branch), Array(annotations.length).fill(original));
  assert.deepEqual(claims.slice(0, 2).map(ref => ref.pr), [3065, 3065]);
  const shared = {
    issue: { number: 2858, state: 'open', title: 'V2 | P135', labels: [] },
    branches: [original],
    prs: [{ number: 3065, state: 'open', head: { ref: original, sha: 'current-head' },
      base: { ref: 'Foodex_V2' } }],
    checkpoint: null,
  };
  const decision = decide({ ...shared, refs: claims });
  assert.deepEqual([decision.action, decision.pr, decision.head],
    ['RESUME_ORIGINAL_PR', 3065, 'current-head']);

  const wrongExplicitId = claimReferences([{body:
    '<!-- foodex-worker-state:v1 -->\nSTATE: ACTIVE\n'
      + 'BRANCH: ' + original + '\nPR: #9999',
  }]);
  assert.equal(decide({ ...shared, refs: wrongExplicitId }).action,
    'BLOCK_CONFLICTING_ORIGINALS',
    'A genuine original branch cannot override a contradictory historical PR ID');

  for (const decorated of [
    original + '; ORIGINAL_PR: #9999 OPEN/DRAFT -> Foodex_V2 ONLY; latest verified source HEAD '
      + sha + '. Previous worker WAITING_CI/EDITING_RELEASED. No peer-active file overlap, 26 other OPEN PRs inspected.',
    original + '; PR #9999 OPEN/DRAFT → Foodex_V2 ONLY',
    original + '; EXACT_START_HEAD: invalid',
    original + ' | START_HEAD: ' + 'b'.repeat(39),
    original + ' | BASE Foodex_V2 ONLY plus permissions',
    original + '; ORIGINAL_PR: #3065 OPEN/DRAFT -> main ONLY',
    'feat/2858-other | BASE Foodex_V2 ONLY',
  ]) {
    const refs = claimReferences([{body:'<!-- foodex-worker-state:v1 -->\n'
      + 'STATE: ACTIVE\nBRANCH: ' + decorated}]);
    assert.equal(decide({ ...shared, refs }).action, 'BLOCK_CONFLICTING_ORIGINALS',
      'Untrusted P135 branch/PR annotation must not be silently accepted: ' + decorated);
  }
});

test('unknown annotations, actual alternate branches and nonmatching claims still block', () => {
  const original='fix/2746-v2-updater-host-readiness-ui';
  const untrusted=[
    original+' (different)',
    original+' (preserve originals)',
    original+' (EXISTING) trailing',
    'fix/2746-other (EXISTING)',
  ];
  const shared={
    issue:{number:2746,state:'open',title:'V2 | signed update gate',labels:[]},
    branches:[original],
    prs:[{number:3968,state:'open',head:{ref:original,sha:'exact-head'},
      base:{ref:'Foodex_V2'}}],
    checkpoint:null,
  };
  for (const branch of untrusted) {
    const refs=claimReferences([{body:'<!-- foodex-worker-state:v1 -->\n'
      + 'STATE: ACTIVE\nBRANCH: '+branch+'\nPR: #3968'}]);
    assert.equal(decide({...shared,refs}).action,'BLOCK_CONFLICTING_ORIGINALS',
      'Unrecognized or conflicting branch claim must fail closed: '+branch);
  }
  assert.equal(decide({...shared,branches:[original,'fix/2746-other'],refs:[]}).action,
    'BLOCK_CONFLICTING_ORIGINALS');
});

test('annotated historic claims resolve to one original without masking true duplicate branches', () => {
  const originals = [
    'ci/4151-resume (PRESERVE)',
    'ci/4151-resume (ORIGINAL; KEEP)',
    'ci/4151-resume | BASE: Foodex_V2 ONLY',
    'ci/4151-resume; PR #4152 original DRAFT; BASE Foodex_V2',
    'ci/4151-resume',
  ];
  const comments = originals.map((branch, id) => ({
    id,
    body: '<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\nBRANCH: ' + branch
      + '\nPR: #4152\n',
  }));
  const refs = claimReferences(comments);
  assert.deepEqual([...new Set(refs.map(x => x.branch))], ['ci/4151-resume']);
  const safe = decide({issue, branches:['ci/4151-resume'], prs:[pr()], refs, checkpoint:null});
  assert.equal(safe.action,'RESUME_ORIGINAL_PR');
  assert.equal(safe.pr,4152);

  const conflicting = claimReferences([
    ...comments,
    {id:99, body:'<!-- foodex-worker-state:v1 -->\nBRANCH: ci/4151-other (PRESERVE)\n'},
  ]);
  assert.equal(decide({issue, branches:['ci/4151-resume'], prs:[pr()],
    refs:conflicting, checkpoint:null}).action,'BLOCK_CONFLICTING_ORIGINALS');
});

test('unrecognized historical claim suffixes never silently become the original branch', () => {
  const canonical='ci/4151-resume';
  const invalid=[
    canonical+' (UNKNOWN)',
    canonical+' (PRESERVE) trailing',
    canonical+'; anything goes',
    canonical+' | BASE: other_branch',
    canonical+' (ORIGINAL; KEEP) extra',
    canonical+'; PR #9999 unrelated; BASE Foodex_V2',
    canonical+'; PR #9999 original DRAFT; BASE Foodex_V2',
  ];
  for (const value of invalid) {
    const refs=claimReferences([{body:'<!-- foodex-worker-state:v1 -->\n'
      + 'STATE: HANDOFF_READY\nBRANCH: '+value+'\nPR: #4152'}]);
    assert.equal(decide({issue, branches:[canonical], prs:[pr()], refs,
      checkpoint:null}).action, 'BLOCK_CONFLICTING_ORIGINALS', value);
  }
});

test('actual G004 original historic branch claims normalize exactly without weakening unknown-claim denial', () => {
  const original='fix/2745-v2-deterministic-setup-composer-lock';
  const suffixes=[
    ' (planned; base Foodex_V2 ONLY)', '',
    ' (PRESERVE)', ' (EXISTING; preserve)', ' (preserved)',
    ' (ORIGINAL)', ' (ORIGINAL; PRESERVE)', ' (ORIGINAL, KEEP)',
    ' (ORIGINAL, PRESERVE)', ' (EXISTING ORIGINAL ONLY)',
    ' (PRESERVE ORIGINAL)', ' (EXISTING)',
    ' (ORIGINAL; NO NEW BRANCH)', ' | BASE: Foodex_V2 ONLY',
    '; PR #3444 original DRAFT; BASE Foodex_V2.',
  ];
  const refs=claimReferences(suffixes.map(suffix=>({
    body:'<!-- foodex-worker-state:v1 -->\nSTATE: HANDOFF_READY\nBRANCH: '
      +original+suffix+'\nPR: #3444',
  })));
  assert.equal(refs.length,suffixes.length);
  assert.deepEqual([...new Set(refs.map(x=>x.branch))],[original]);
  const shared={
    issue:{number:2745,state:'open',title:'V2 | G004',labels:[]},
    branches:[original],
    prs:[{number:3444,state:'open',head:{ref:original,sha:'current-head'},
      base:{ref:'Foodex_V2'}}],checkpoint:null,
  };
  assert.equal(decide({...shared,refs}).action,'RESUME_ORIGINAL_PR');
  for(const bad of [
    original+' (EXISTING; preserve unknown)',
    original+' (planned; base main ONLY)',
    original+' (ORIGINAL; PRESERVE) extra',
    original+'; PR #3444 unrelated; BASE Foodex_V2.',
    'fix/2745-second-branch (ORIGINAL)',
  ]) {
    const forged=claimReferences([{body:'<!-- foodex-worker-state:v1 -->\n'
      +'STATE: ACTIVE\nBRANCH: '+bad+'\nPR: #3444'}]);
    assert.equal(decide({...shared,refs:forged}).action,
      'BLOCK_CONFLICTING_ORIGINALS',bad);
  }
});

test('incomplete or oversized search pagination fails closed', async () => {
  await assert.rejects(pages(async()=>({data:{items:[],total_count:2}}),{},'items'),/incomplete/);
  await assert.rejects(pages(async()=>({data:{wrong:[]}}),{},'items'),/payload/);
  await assert.rejects(pages(async()=>({data:Array(100).fill({})}),{},'branches',2),/cap/);
});

function harness({mode='merged',acceptance='in_progress',multiple=false,crossReference=false,forkOriginal=false,missingHeadRepo=false,mixedCaseHeadRepo=false,featureBranch=false,claimOnlyUnrelated=false,ancestry='ahead',issueState=issue}={}) {
  const calls=[];
  const thePR = { ...pr(mode, claimOnlyUnrelated ? 'fix/9999-other-task' : 'ci/4151-resume'),
    title: claimOnlyUnrelated ? 'V2 #9999 — unrelated issue' : 'V2 #4151 — test',
    body: claimOnlyUnrelated ? 'Refs #9999; dependent on #4151' : 'Closes #4151',
    head: { ...pr(mode, claimOnlyUnrelated ? 'fix/9999-other-task' : 'ci/4151-resume').head,
      repo: missingHeadRepo ? null : { full_name: forkOriginal ? 'external/FOOD' : mixedCaseHeadRepo ? 'O/food' : 'o/FOOD' } } };
  const unrelatedPR = { ...pr('open', 'fix/9999-unrelated', 4153),
    title: 'V2 #9999 — dependent on #4151', body: 'Parent #4151; follows #4151' };
  const c=[{id:7,body:'<!-- foodex-worker-state:v1 -->\nSTATE: MERGED\n'
    + 'OWNER: worker-a\nBRANCH: ci/4151-resume\nPR: #4152'}];
  const gh={rest:{
    issues:{
      get:async()=>({data:issueState}),
      listComments:async()=>({data:c}),
    },
    search:{issuesAndPullRequests:async()=>({data:{items:[{number:4152},...(crossReference?[{number:4153}]:[])],total_count:crossReference?2:1}})},
    repos:{
      listBranches:async()=>({data:featureBranch?[{name:'feature/4151-original'}]:[]}),
      getBranch:async()=>({data:{commit:{sha:'latest-v2'}}}),
      compareCommits:async()=>({data:{status:ancestry}}),
    },
    pulls:{
      list:async()=>({data:[]}),
      get:async ({pull_number})=>{calls.push('pr:'+pull_number); return {data:pull_number===4153?unrelatedPR:thePR};},
    },
    actions:{
      listWorkflowRunsForRepo:async args=>{calls.push({workflowQuery:args}); return {data:{workflow_runs:[
        {id:1200,name:'FOODEX V2 Integrated Backend Acceptance',
         status:acceptance==='success'?'completed':acceptance,
         conclusion:acceptance==='success'?'success':null,
         head_sha:'latest-v2'},
        ...(multiple?[{id:1199,name:'FOODEX V2 Integrated Backend Acceptance',
          status:'completed',conclusion:'success',head_sha:'older'}]:[]),
      ]}}; },
    },
  }};
  return {gh,calls};
}
test('fork PR with matching branch and issue must not become the original V2 PR', async () => {
  const {gh}=harness({forkOriginal:true});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(result.action,'BLOCK_MISSING_ORIGINAL_BRANCH');
  assert.deepEqual(result.original_prs,[]);
  assert.equal(result.integration_accepted,undefined);
});

test('deleted fork without head repository identity fails closed; same repo name is case insensitive', async () => {
  const {gh}=harness({missingHeadRepo:true});
  const missing=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(missing.action,'BLOCK_MISSING_ORIGINAL_BRANCH');
  assert.deepEqual(missing.original_prs,[]);

  const {gh:sameRepo}=harness({mixedCaseHeadRepo:true});
  const accepted=await buildPlan({github:sameRepo,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(accepted.action,'VERIFY_INTEGRATION_ACCEPTANCE');
  assert.deepEqual(accepted.original_prs.map(pr=>pr.number),[4152]);
});

test('historical feature/ issue branches are discovered instead of treating the issue as unclaimed', async () => {
  const {gh}=harness({featureBranch:true});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.deepEqual(result.actual_branches,['feature/4151-original']);
  assert.equal(result.action,'BLOCK_CONFLICTING_ORIGINALS');
});

test('comment-only claim PR id cannot adopt an unrelated V2 PR as original', async () => {
  const {gh}=harness({claimOnlyUnrelated:true});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.deepEqual(result.original_prs,[]);
  // Preserve the historic claim as an ambiguity blocker, not authorization
  // to resume or merge someone else's unrelated pull request.
  assert.equal(result.action,'BLOCK_MISSING_ORIGINAL_BRANCH');
  assert.equal(result.original_branch,'ci/4151-resume');
  assert.equal(result.original_pr,null);
});

test('dependency-only mention is not mistaken for an original PR', async () => {
  const {gh}=harness({crossReference:true});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(result.action,'VERIFY_INTEGRATION_ACCEPTANCE');
  assert.deepEqual(result.original_prs.map(item=>item.number),[4152]);
});

test('actual merged #4117 style case holds on latest integrated CI, not stale PR head', async () => {
  const {gh}=harness();
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(result.action,'VERIFY_INTEGRATION_ACCEPTANCE');
  assert.equal(result.integration_head,'latest-v2');
  assert.equal(result.integration_acceptance.status,'in_progress');
  assert.equal(result.integration_accepted,false);
});
test('operator-dispatched acceptance can certify exact latest SHA without push-only filter', async () => {
  const {gh,calls}=harness({acceptance:'success'});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(result.integration_accepted,true);
  const query=calls.find(item=>item.workflowQuery)?.workflowQuery;
  assert.equal(query.branch,'Foodex_V2');
  assert.equal(query.event,undefined);
});

test('latest full integrated success is valid; older success never substitutes', async () => {
  const {gh}=harness({acceptance:'success',multiple:true});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(result.integration_accepted,true);
  const {gh:second}=harness({acceptance:'in_progress',multiple:true});
  const other=await buildPlan({github:second,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(other.integration_accepted,false);
});
test('integrated acceptance rejects green CI when merge commit is not in current V2 history', async () => {
  for (const ancestry of ['diverged', 'behind']) {
    const {gh}=harness({acceptance:'success',ancestry});
    const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
    assert.equal(result.action,'VERIFY_INTEGRATION_ACCEPTANCE');
    assert.equal(result.integration_merge_reachable,false);
    assert.equal(result.integration_accepted,false);
    assert.equal(result.integration_acceptance.status,'blocked');
  }
  const {gh}=harness({acceptance:'success',ancestry:'identical'});
  const result=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(result.integration_merge_reachable,true);
  assert.equal(result.integration_accepted,true);
});

test('read-only preflight never writes, closed original retains audit', async () => {
  const {gh}=harness({mode:'closed'});
  const x=await buildPlan({github:gh,owner:'o',repo:'FOOD',issueNumber:4151});
  assert.equal(x.action,'BLOCK_CLOSED_ORIGINAL_PR');
  assert.equal(x.original_pr,4152);
});

test('the V2 PR isolation gate calls resume-first before merge, not main', () => {
  const fs = require('node:fs');
  const path = require('node:path');
  const workflow = fs.readFileSync(path.join(__dirname,
    '../workflows/v2-isolation-gate.yml'), 'utf8');
  assert.match(workflow, /Resume-first historical PR gate/);
  assert.match(workflow, /buildPlan\(\{/);
  assert.match(workflow, /plan\.original_pr === context\.payload\.pull_request\.number/);
  assert.match(workflow, /branches:\s*\n\s*- Foodex_V2/);
  assert.doesNotMatch(workflow, /\n\s*- main\b/);
});
