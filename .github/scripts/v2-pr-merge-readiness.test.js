'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { decision, REQUIRED, exactTechnicalReview } = require('./v2-pr-merge-readiness.js');
const SHA = 'f'.repeat(40), BASE = 'e'.repeat(40), PREV = 'd'.repeat(40);
const pr = {number:4267,state:'open',draft:false,base:{ref:'Foodex_V2',sha:BASE},
  head:{sha:SHA},mergeable:true,mergeable_state:'clean',labels:[]};
const green = REQUIRED.map((name,id)=>({id:id+1,name,head_sha:SHA,status:'completed',conclusion:'success'}));
const data = {pr,expectedHead:SHA,expectedBase:BASE,integrationHead:BASE,checks:green,
  freeze:{ok:true,state:'FROZEN'},sourceComplete:true,technicalReview:true,
  issueVerified:true,issueLabels:[]};
function action(change){return decision({...data,...change}).action;}
test('only full exact SHA Required CI and policy success yield merge review',()=>{
  assert.equal(action(), 'READY_FOR_MERGE_REVIEW');
  assert.equal(action({checks:green.filter(x=>x.name!=='required-ci-gate')}),'WAIT_CI');
  assert.equal(action({checks:green.map(x=>x.name==='required-ci-gate'?{...x,status:'in_progress',conclusion:null}:x)}),'WAIT_CI');
  assert.equal(action({checks:green.map(x=>x.name==='required-ci-gate'?{...x,conclusion:'failure'}:x)}),'FIX_CI');
  assert.equal(action({checks:green.map(x=>x.name==='Repository Policy'?{...x,head_sha:PREV}:x)}),'STALE_HEAD');
});
test('head/base changes, unknown mergeability and conflicts are fail closed',()=>{
  assert.equal(action({expectedHead:PREV}), 'STALE_HEAD');
  assert.equal(action({expectedBase:PREV}), 'STALE_HEAD');
  assert.equal(action({pr:{...pr,mergeable:null,mergeable_state:'unknown'}}),'WAIT_MERGEABILITY');
  assert.equal(action({pr:{...pr,mergeable:false,mergeable_state:'dirty'}}),'RESOLVE_CONFLICTS');
  assert.equal(action({pr:{...pr,base:{ref:'main',sha:BASE}}}),'NOT_V2');
});
test('source completion, technical review, draft and real human approval stay explicit',()=>{
  assert.equal(action({sourceComplete:false}),'SOURCE_INCOMPLETE');
  assert.equal(action({technicalReview:false}),'TECHNICAL_REVIEW_REQUIRED');
  assert.equal(action({pr:{...pr,draft:true}}),'MARK_READY_FOR_REVIEW');
  assert.equal(action({pr:{...pr,labels:[{name:'gate:approval'}]}}),'REVIEW_REQUIRED');
  assert.equal(action({pr:{...pr,mergeable_state:'blocked'}}),'REVIEW_REQUIRED');
  assert.equal(action({reviews:[{user:{login:'reviewer'},state:'CHANGES_REQUESTED'}]}),'REVIEW_REQUIRED');
  assert.equal(action({freeze:{ok:false,state:'FROZEN_HEAD_CHANGED'}}),'FIX_FREEZE');
});

test('head-bound technical review must be on the same exact line; old approvals cannot bleed into new commits',()=>{
  const old = 'a'.repeat(40), now = 'b'.repeat(40);
  assert.equal(exactTechnicalReview('TECHNICAL_REVIEW: PASS | HEAD: ' + now, now), true);
  assert.equal(exactTechnicalReview('TECHNICAL_REVIEW: PASS | HEAD: ' + old + '\nNew head ' + now, now), false);
  assert.equal(exactTechnicalReview('TECHNICAL_REVIEW: PASS | HEAD: ' + now.slice(0, 12), now), false);
  assert.equal(exactTechnicalReview('TECHNICAL_REVIEW: PENDING | HEAD: ' + now, now), false);
  assert.equal(exactTechnicalReview('TECHNICAL_REVIEW: PASS | HEAD: ' + old, now), false);
  assert.equal(exactTechnicalReview('TECHNICAL_REVIEW: PASS | HEAD: ' + now + ' extra', now), false);
});

test('live Foodex_V2 tip and owning issue must be independently verified',()=>{
  assert.equal(action({integrationHead:PREV}), 'BASE_ADVANCED_REVALIDATE');
  assert.equal(action({integrationHead:null}), 'BASE_ADVANCED_REVALIDATE');
  assert.equal(action({issueVerified:false}), 'ISSUE_UNVERIFIED');
  assert.equal(action({issueLabels:[{name:'gate:approval'}]}), 'REVIEW_REQUIRED');
  assert.equal(action({pr:{...pr,requested_reviewers:[{login:'independent-reviewer'}]}}), 'REVIEW_REQUIRED');
  assert.equal(action({pr:{...pr,requested_teams:[{slug:'ci-review'}]}}), 'REVIEW_REQUIRED');
});

test('self-running monitor is not mistaken for missing human review, but any other pending/red check blocks',()=>{
  const self={id:99,name:'V2 merge readiness monitor',head_sha:SHA,
    status:'in_progress',conclusion:null};
  const unstable={...pr,mergeable:true,mergeable_state:'unstable'};
  assert.equal(action({pr:unstable,checks:[...green,self]}),'READY_FOR_MERGE_REVIEW');
  assert.equal(action({pr:unstable,checks:green}),'WAIT_MERGEABILITY');
  assert.equal(action({pr:unstable,checks:[...green,{...self,status:'completed',conclusion:'success'}]}),'WAIT_MERGEABILITY');
  const pending={id:11,name:'Independent required review check',head_sha:SHA,status:'in_progress',conclusion:null};
  assert.equal(action({pr:unstable,checks:[...green,self,pending]}),'WAIT_CI');
  assert.equal(action({pr:unstable,checks:[...green,self,{...pending,status:'completed',conclusion:'failure'}]}),'FIX_CI');
  assert.equal(action({pr:unstable,checks:[...green,self,{...pending,head_sha:PREV,status:'completed',conclusion:'success'}]}),'STALE_HEAD');
  assert.equal(action({pr:{...unstable,requested_reviewers:[{login:'reviewer'}]},checks:[...green,self]}),'REVIEW_REQUIRED');
  assert.equal(action({pr:{...unstable,labels:[{name:'gate:approval'}]},checks:[...green,self]}),'REVIEW_REQUIRED');
});
