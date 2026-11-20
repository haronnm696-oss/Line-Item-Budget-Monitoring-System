import assert from 'node:assert/strict';
import test from 'node:test';

import { ASSIGN_REQUIRED, NO_PROJECTS_LEFT, assignFormReducer, assignRoleText, assignSubmission, canAssignTo, initialAssignForm, projectChoices } from './assignForm.js';

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

const projects = [
  { id: 1, title: 'Urban Mobility', code: 'UM', status: 'Ongoing', leader: { id: 40, name: 'Norhaya Ali' } },
  { id: 2, title: 'Goat Value Chain', code: 'GV', status: 'Planning', leader: null },
  { id: 3, title: 'Old Study', code: 'OS', status: 'Completed', leader: null },
];

test('the options leave out projects the person is already on', () => {
  const assistant = { id: 7, role_slug: 'research-assistant', project_ids: [2] };
  const choices = projectChoices(projects, assistant);
  assert.deepEqual(choices.map((project) => project.id), [1]);
  // Not a Project Leader: a project with a leader is open to them.
  assert.equal(choices[0].disabled, false);
  assert.equal(choices[0].reason, '');
});

test('for a Project Leader, a project that already has a leader is greyed out with the reason', () => {
  const leader = { id: 41, role_slug: 'project-leader', project_ids: [] };
  const [taken, open] = projectChoices(projects, leader);
  assert.equal(taken.disabled, true);
  assert.equal(taken.reason, 'Already led by Norhaya Ali. A project has one Project Leader.');
  assert.equal(open.disabled, false);
  const users = [{ ...leader, role_label: 'Project Leader' }];
  assert.equal(assignSubmission({ userId: '41', projectId: '1' }, users, projects).error, taken.reason);
  assert.deepEqual(assignSubmission({ userId: '41', projectId: '2' }, users, projects), { payload: { user_id: 41, project_id: 2 } });
});

test('a project the person leads is left out, not greyed out', () => {
  const choices = projectChoices(projects, { id: 40, role_slug: 'project-leader', project_ids: [1] });
  assert.deepEqual(choices.map((project) => project.id), [2]);
});

test('with no project left, the dialog says so and a submit is refused', () => {
  const person = { id: 7, role_slug: 'researcher', role_label: 'Researcher', project_ids: [1, 2] };
  assert.deepEqual(projectChoices(projects, person), []);
  // Add member: the one project of the page, and the person is already on it.
  assert.equal(assignSubmission({ userId: '7', projectId: '1' }, [person], [projects[0]]).error, NO_PROJECTS_LEFT);
  assert.equal(NO_PROJECTS_LEFT, 'No other projects to add.');
});

test('with no person chosen, every active project is listed', () => {
  assert.deepEqual(projectChoices(projects, null).map((project) => [project.id, project.disabled]), [[1, false], [2, false]]);
});

test('only Ongoing and Planning projects take new members', () => {
  assert.equal(canAssignTo({ status: 'Ongoing' }), true);
  assert.equal(canAssignTo({ status: 'Planning' }), true);
  assert.equal(canAssignTo({ status: 'Completed' }), false);
  assert.equal(canAssignTo(null), false);
});
