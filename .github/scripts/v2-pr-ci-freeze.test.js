'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { FREEZE, FIX, UNFREEZE, events, validate, listPages } = require('./v2-pr-ci-freeze.js');
const A = 'a'.repeat(40), B = 'b'.repeat(40), C = 'c'.repeat(40);
function comment(kind, fields, id = 1, association = 'OWNER') {
  return { id, created_at: '2026-11-22T21:00:0' + id + 'Z', author_association: association,
    body: kind + '\n' + Object.entries(fields).map(([key,value]) => key + ': ' + value).join('\n') };
}
const lock = comment(FREEZE, { HEAD: A });
test('no marker permits draft iteration; matching frozen SHA permits final CI', async () => {
  assert.equal((await validate({currentHead:A,comments:[]})).state,'NOT_ARMED');
  assert.equal((await validate({currentHead:A,comments:[lock]})).state,'FROZEN');
  assert.equal((await validate({currentHead:B,comments:[lock]})).state,'FROZEN_HEAD_CHANGED');
});
test('trusted logged unfreeze releases edits, but wrong old head and untrusted author do not', async () => {
  const unfreeze = comment(UNFREEZE, { HEAD:A, REASON:'Planned reviewer-requested correction' }, 2);
  assert.equal((await validate({currentHead:B,comments:[lock,unfreeze]})).state,'UNFROZEN');
  assert.equal((await validate({currentHead:B,comments:[lock,comment(UNFREEZE,{HEAD:B,REASON:'planned review edit'},2)]})).ok,false);
  assert.equal((await validate({currentHead:B,comments:[lock,comment(UNFREEZE,{HEAD:A,REASON:'planned review edit'},2,'NONE')]})).ok,false);
});
test('CI fix requires a real red exact-head check, cannot accept cancelled/skipped or made-up failures', async () => {
  const fix = comment(FIX,{FROM:A,TO:B,FAILING_CHECK:'required-ci-gate'},2);
  const verify = conclusion => validate({currentHead:B,comments:[lock,fix],getChecks: async()=>[
    {name:'required-ci-gate',head_sha:A,conclusion}]});
  assert.equal((await verify('failure')).state,'FROZEN');
  assert.equal((await verify('cancelled')).state,'NO_VERIFIED_RED_CI');
  assert.equal((await verify('success')).ok,false);
  assert.equal((await verify(null)).ok,false);
  assert.equal((await validate({currentHead:C,comments:[lock,fix],getChecks:async()=>[
    {name:'required-ci-gate',head_sha:A,conclusion:'failure'}]})).state,'FROZEN_HEAD_CHANGED');
  assert.equal((await validate({currentHead:B,comments:[lock,fix],getChecks:async()=>[
    {name:'required-ci-gate',head_sha:C,conclusion:'failure'}]})).ok,false);
});
test('freeze fix rejects failed check lacking exact original head provenance', async () => {
  const fix = comment(FIX,{FROM:A,TO:B,FAILING_CHECK:'required-ci-gate'},2);
  const evidence = checks => validate({currentHead:B,comments:[lock,fix],getChecks:async()=>checks});
  for (const head_sha of [undefined, null, '', C, 'not-a-sha']) {
    const check = {name:'required-ci-gate',conclusion:'failure'};
    if (head_sha !== undefined) check.head_sha = head_sha;
    assert.equal((await evidence([check])).state, 'NO_VERIFIED_RED_CI',
      'missing, invalid or mismatched check HEAD must never grant a freeze exception');
  }
  assert.equal((await evidence([{name:'required-ci-gate',head_sha:A,conclusion:'failure'}])).state,'FROZEN');
  assert.equal((await evidence([{name:'required-ci-gate',head_sha:A,conclusion:'cancelled'}])).state,'NO_VERIFIED_RED_CI');
  assert.equal((await evidence([null])).ok,false);
});

test('freeze cannot be overridden by a new SHA without unfreeze or red exact-head correction', async () => {
  const second = comment(FREEZE,{HEAD:B},2);
  assert.equal((await validate({currentHead:B,comments:[lock,second]})).state,'INVALID_FREEZE');
  assert.equal((await validate({currentHead:B,comments:[lock,comment(FIX,{FROM:B,TO:B,FAILING_CHECK:'required-ci-gate'},2)]})).ok,false);
});
test('malformed trusted evidence fails closed; untrusted marker cannot silently grant permissions', async () => {
  const broken=comment(FREEZE,{HEAD:'nope'},2);
  assert.equal((await validate({currentHead:A,comments:[broken]})).ok,false);
  assert.equal(events([comment(UNFREEZE,{HEAD:A,REASON:'untrusted edit'},2,'FIRST_TIME_CONTRIBUTOR')]).length,0);
  assert.equal((await validate({currentHead:B,comments:[lock,
    comment(FIX,{FROM:A,TO:B,FAILING_CHECK:'required-ci-gate'},2,'NONE')]})).state,'FROZEN_HEAD_CHANGED');
  const dup={...lock,body: FREEZE+'\nHEAD: '+A+'\nHEAD: '+B};
  assert.equal((await validate({currentHead:A,comments:[dup]})).ok,false);
});
test('page truncation, incomplete and invalid GitHub payload never grants readiness', async () => {
  await assert.rejects(listPages(async()=>({data: Array(100).fill({id:1})}), {}, 'comments'), /pagination-limit/);
  await assert.rejects(listPages(async()=>({data:{total_count:2,comments:[{id:1}]}}), {}, 'comments'), /incomplete/);
  await assert.rejects(listPages(async()=>({data:{bad:true}}),{},'comments'),/invalid/);
});
