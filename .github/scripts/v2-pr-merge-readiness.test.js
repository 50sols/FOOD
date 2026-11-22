'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { decision, REQUIRED } = require('./v2-pr-merge-readiness.js');
const SHA = 'f'.repeat(40), BASE = 'e'.repeat(40), PREV = 'd'.repeat(40);
const pr = {number:4267,state:'open',draft:false,base:{ref:'Foodex_V2',sha:BASE},
  head:{sha:SHA},mergeable:true,mergeable_state:'clean',labels:[]};
const green = REQUIRED.map((name,id)=>({id:id+1,name,head_sha:SHA,status:'completed',conclusion:'success'}));
const data = {pr,expectedHead:SHA,expectedBase:BASE,checks:green,freeze:{ok:true,state:'FROZEN'},
  sourceComplete:true,technicalReview:true};
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
