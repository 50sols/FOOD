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
