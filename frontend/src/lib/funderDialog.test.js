import test from 'node:test';
import assert from 'node:assert/strict';
import { canClose, funderDialogReducer, funderSummary, initialFunderDialog, saveFunder } from './funderDialog.js';

const MOST = { id: 7, name: 'Ministry of Science and Technology - BARMM', short_name: 'MOST', project_count: 0 };

/** A tiny store around the reducer, recording every state it passes through. */
function store(state = initialFunderDialog) {
  const seen = [state];
  return {
    get state() { return seen[seen.length - 1]; },
    seen,
    dispatch(action) { seen.push(funderDialogReducer(seen[seen.length - 1], action)); },
  };
}

const describe = (failure) => ({ error: failure.message, serverErrors: failure.fields ?? {} });

function fill(s, name, acronym) {
  s.dispatch({ type: 'field', key: 'name', value: name });
  s.dispatch({ type: 'field', key: 'acronym', value: acronym });
}

test('after a successful add the form clears, buttons are enabled and the dialog can close', async () => {
  const s = store();
  s.dispatch({ type: 'start', funder: null });
  fill(s, '  Ministry of Science and Technology - BARMM ', ' MOST ');
  const calls = [];
  const saving = saveFunder({
    state: s.state,
    dispatch: s.dispatch,
    write: async (...args) => { calls.push(args); assert.equal(s.state.busy, true); assert.equal(canClose(s.state), false); return {}; },
    describe,
  });
  assert.equal(await saving, true);

  assert.deepEqual(calls, [['post', 'new', { name: 'Ministry of Science and Technology - BARMM', acronym: 'MOST' }]]);
  assert.equal(s.state.busy, false);
  assert.equal(canClose(s.state), true);
  assert.equal(s.state.editing, null);
  assert.deepEqual(s.state.form, { name: '', acronym: '' });
  assert.equal(s.state.notice, 'Funder added.');
  assert.equal(s.state.error, '');

  // Another funder can be added straight away.
  s.dispatch({ type: 'start', funder: null });
  assert.equal(s.state.editing, 'new');
  assert.deepEqual(s.state.form, { name: '', acronym: '' });
});

test('after a successful edit the edit row closes and buttons are enabled', async () => {
  const s = store();
  s.dispatch({ type: 'start', funder: MOST });
  assert.equal(s.state.editing, 7);
  assert.deepEqual(s.state.form, { name: MOST.name, acronym: 'MOST' });
  s.dispatch({ type: 'field', key: 'acronym', value: 'MOST-BARMM' });

  const calls = [];
  const saved = await saveFunder({
    state: s.state,
    dispatch: s.dispatch,
    write: async (...args) => { calls.push(args); return { message: 'Funder saved.' }; },
    describe,
  });

  assert.equal(saved, true);
  assert.deepEqual(calls, [['patch', 7, { name: MOST.name, acronym: 'MOST-BARMM' }]]);
  assert.equal(s.state.busy, false);
  assert.equal(canClose(s.state), true);
  assert.equal(s.state.editing, null);
  assert.equal(s.state.notice, 'Funder saved.');
});

test('after a failed save the form stays with the error, buttons are enabled and the dialog can close', async () => {
  const s = store();
  s.dispatch({ type: 'start', funder: null });
  fill(s, 'Ministry of Science and Technology - BARMM', 'MOST');

  const failure = Object.assign(new Error('The short name is already used.'), { fields: { acronym: 'The short name is already used.' } });
  const saved = await saveFunder({
    state: s.state,
    dispatch: s.dispatch,
    write: async () => { throw failure; },
    describe,
  });

  assert.equal(saved, false);
  assert.equal(s.state.busy, false);
  assert.equal(canClose(s.state), true);
  assert.equal(s.state.editing, 'new');
  assert.deepEqual(s.state.form, { name: 'Ministry of Science and Technology - BARMM', acronym: 'MOST' });
  assert.equal(s.state.error, 'The short name is already used.');
  assert.deepEqual(s.state.serverErrors, { acronym: 'The short name is already used.' });
  assert.equal(s.state.notice, '');

  // Cancel closes the form and clears the error.
  s.dispatch({ type: 'cancel' });
  assert.equal(s.state.editing, null);
  assert.equal(s.state.error, '');
});

test('a failed save with no message falls back to the generic text', async () => {
  const s = store();
  s.dispatch({ type: 'start', funder: null });
  fill(s, 'A', 'B');
  await saveFunder({ state: s.state, dispatch: s.dispatch, write: async () => { throw new Error(''); }, describe });
  assert.equal(s.state.error, 'Could not save. Please try again.');
  assert.equal(s.state.busy, false);
});

test('a failure that cannot be described still re-enables the buttons', async () => {
  const s = store();
  s.dispatch({ type: 'start', funder: MOST });
  const saved = await saveFunder({
    state: s.state,
    dispatch: s.dispatch,
    write: async () => { throw undefined; },
    describe: (failure) => ({ error: failure.message }), // throws on undefined
  });
  assert.equal(saved, false);
  assert.equal(s.state.busy, false);
  assert.equal(canClose(s.state), true);
  assert.equal(s.state.error, 'Could not save. Please try again.');
  assert.equal(s.state.editing, 7); // the edit row stays open with the error
});

test('an invalid form is not sent and never becomes busy', async () => {
  const s = store();
  s.dispatch({ type: 'start', funder: null });
  let called = false;
  const saved = await saveFunder({ state: s.state, dispatch: s.dispatch, write: async () => { called = true; }, describe });
  assert.equal(saved, false);
  assert.equal(called, false);
  assert.ok(s.seen.every((state) => !state.busy));
  assert.ok(s.state.local.name && s.state.local.acronym);
});

test('the list row separates the short name and the full name', () => {
  assert.deepEqual(funderSummary(MOST), { short: 'MOST', detail: 'Ministry of Science and Technology - BARMM · 0 projects' });
  assert.equal(`${funderSummary(MOST).short} · ${funderSummary(MOST).detail}`, 'MOST · Ministry of Science and Technology - BARMM · 0 projects');
  assert.deepEqual(funderSummary({ name: 'DOST', short_name: '', project_count: 1 }), { short: 'DOST', detail: '1 project' });
});
