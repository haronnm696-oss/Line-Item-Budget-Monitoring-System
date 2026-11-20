import test from 'node:test';
import assert from 'node:assert/strict';
import {
  conflictFor, conflictWarning, endDateActive, isActiveProject, leaderChoices, pickLeaderProject, splitLeaderProjects,
} from './leaderRule.js';
import { pickProject } from './myProjects.js';

const mine = (row) => ({ my_roles: ['project-leader'], ...row });
const ongoing = mine({ id: 1, code: 'A', status_key: 'ongoing', start_date: '2026-01-01', end_date: '2026-12-31' });
const newer = mine({ id: 2, code: 'B', status_key: 'planning', start_date: '2026-12-01', end_date: '2027-12-31' });
const older = mine({ id: 3, code: 'C', status_key: 'ongoing', start_date: '2025-06-01', end_date: '2027-01-31' });
const done1 = mine({ id: 4, code: 'D', status_key: 'completed', start_date: '2024-01-01', end_date: '2025-01-31' });
const done2 = mine({ id: 5, code: 'E', status_key: 'completed', start_date: '2023-01-01', end_date: '2025-06-30' });

test('completed projects are not active; planning and ongoing are', () => {
  assert.equal(isActiveProject(done1), false);
  assert.equal(isActiveProject(ongoing), true);
  assert.equal(isActiveProject(newer), true);
  assert.equal(isActiveProject({ status: 'Completed' }), false);
});

test('a leader with one active project sees it, with the completed ones as past projects (latest first)', () => {
  const pick = pickLeaderProject([done1, ongoing, done2]);
  assert.equal(pick.project.id, 1);
  assert.deepEqual(pick.projects.map((p) => p.id), [1]);
  assert.deepEqual(pick.others, []);
  assert.deepEqual(pick.past.map((p) => p.id), [5, 4]);
  assert.equal(conflictWarning(pick.others), null);
});

test('older data with several active projects: the most recently started, a warning listing the others', () => {
  const pick = pickLeaderProject([older, ongoing, newer, done1]);
  assert.equal(pick.project.id, 2);
  assert.deepEqual(pick.others.map((p) => p.id), [1, 3]);
  assert.match(conflictWarning(pick.others), /^You lead 3 active projects\./);
  // The ?project= one, when it is one of them (links in the warning).
  assert.equal(pickLeaderProject([older, ongoing, newer], '3').project.id, 3);
  // A past project is never the one shown.
  assert.equal(pickLeaderProject([ongoing, done1], '4').project.id, 1);
});

test('only completed projects: no active project, past projects still listed', () => {
  const pick = pickLeaderProject([done1]);
  assert.equal(pick.project, null);
  assert.equal(pick.past.length, 1);
  assert.deepEqual(splitLeaderProjects(undefined), { active: [], past: [] });
});

test('pickProject applies the rule for the project-leader role only', () => {
  const asLeader = pickProject([older, ongoing, newer], null, 'project-leader');
  assert.equal(asLeader.project.id, 2);
  const asResearcher = pickProject([older, ongoing].map((p) => ({ ...p, my_roles: ['researcher'] })), null, 'researcher');
  assert.equal(asResearcher.project.id, 3, 'other roles keep the first project');
  assert.deepEqual(asResearcher.others, []);
});

test('leader dropdown: free people first, the others greyed with the project they lead', () => {
  const leaders = [
    { id: 10, name: 'Busy', leads: [{ id: 1, code: 'WF-001', title: 'Workflow' }] },
    { id: 11, name: 'Free', leads: [] },
  ];
  const choices = leaderChoices(leaders, { projectId: 99 });
  assert.deepEqual(choices.map((c) => c.name), ['Free', 'Busy']);
  assert.equal(choices[1].disabled, true);
  assert.equal(choices[1].label, 'Busy — leads WF-001');
  assert.equal(choices[0].disabled, false);

  // Leading the project being edited does not make them busy.
  assert.equal(leaderChoices(leaders, { projectId: 1 }).find((c) => c.id === 10).disabled, false);
  // The current choice stays selectable (older data).
  assert.equal(leaderChoices(leaders, { projectId: 99, selectedId: '10' }).find((c) => c.id === 10).disabled, false);
  // A completed project may take anyone.
  assert.equal(leaderChoices(leaders, { projectId: 99, projectActive: false }).every((c) => !c.disabled), true);
});

test('end date decides whether the edited project is active', () => {
  assert.equal(endDateActive('2026-11-20', '2026-11-20'), true);
  assert.equal(endDateActive('2026-11-19', '2026-11-20'), false);
  assert.equal(endDateActive('', '2026-11-20'), true);
});

test('the Users and roles row finds its own conflict', () => {
  const conflicts = [{ user: { id: 7, name: 'X' }, count: 3, message: 'Leads 3 active projects; reassign all but one', projects: [] }];
  assert.equal(conflictFor(conflicts, '7').count, 3);
  assert.equal(conflictFor(conflicts, 8), null);
  assert.equal(conflictFor(undefined, 7), null);
});
