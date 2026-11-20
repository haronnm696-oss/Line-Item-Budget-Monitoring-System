import assert from 'node:assert/strict';
import test from 'node:test';

import { ASSIGN_REQUIRED, assignFormReducer, assignRoleText, assignSubmission, canAssignTo, initialAssignForm } from './assignForm.js';

const users = [{ id: 7, name: 'Papaw Dianalan', role_slug: 'researcher', role_label: 'Researcher' }];

// The production report: user, project (and role) all selected, yet
// "User, project and role are all required." was shown. The message came
// from an earlier failed attempt and the form kept it while closed.
test('the required message from an earlier attempt does not survive a reopen or a change', () => {
  let state = initialAssignForm(7);
  const first = assignSubmission(state, users); // project missing
  assert.equal(first.error, ASSIGN_REQUIRED);
  state = assignFormReducer(state, { type: 'invalid', error: first.error });

  // Reopened for the same person: clean.
  const reopened = assignFormReducer(state, { type: 'open', userId: 7 });
  assert.deepEqual(reopened, { userId: '7', projectId: '', error: '' });

  // Or, still open, the next choice clears it.
  const changed = assignFormReducer(state, { type: 'change', field: 'projectId', value: 3 });
  assert.equal(changed.error, '');
});

test('user and project are enough: the payload carries no role', () => {
  let state = initialAssignForm('');
  state = assignFormReducer(state, { type: 'change', field: 'userId', value: '7' });
  state = assignFormReducer(state, { type: 'change', field: 'projectId', value: 3 });
  assert.deepEqual(assignSubmission(state, users), { payload: { user_id: 7, project_id: 3 } });
  assert.equal(assignRoleText(state, users), 'as Researcher');
});

test('ids match whether they arrive as numbers or strings', () => {
  const state = { userId: '7', projectId: '3', error: '' };
  assert.ok(assignSubmission(state, [{ id: '7', role_label: 'Researcher' }]).payload);
  assert.ok(assignSubmission({ ...state, userId: 7 }, users).payload);
});

test('missing user or project is reported, unknown user too', () => {
  assert.equal(assignSubmission({ userId: '', projectId: '3' }, users).error, ASSIGN_REQUIRED);
  assert.equal(assignSubmission({ userId: '7', projectId: '' }, users).error, ASSIGN_REQUIRED);
  assert.match(assignSubmission({ userId: '99', projectId: '3' }, users).error, /cannot be assigned/);
});

test('Add member on a project page: the project is preselected, the user is chosen', () => {
  let state = initialAssignForm('', 12);
  assert.deepEqual(state, { userId: '', projectId: '12', error: '' });
  assert.equal(assignSubmission(state, users).error, ASSIGN_REQUIRED);
  state = assignFormReducer(state, { type: 'change', field: 'userId', value: 7 });
  assert.deepEqual(assignSubmission(state, users), { payload: { user_id: 7, project_id: 12 } });
  assert.deepEqual(assignFormReducer(state, { type: 'open', projectId: 12 }), { userId: '', projectId: '12', error: '' });
});

test('only Ongoing and Planning projects take new members', () => {
  assert.equal(canAssignTo({ status: 'Ongoing' }), true);
  assert.equal(canAssignTo({ status: 'Planning' }), true);
  assert.equal(canAssignTo({ status: 'Completed' }), false);
  assert.equal(canAssignTo(null), false);
});
