import test from 'node:test';
import assert from 'node:assert/strict';
import { holdsRole, myProjects, notAssignedText, projectRoleFor } from './myProjects.js';

const list = [
  // Two rows on one project (legacy data): both roles count.
  { id: 9, code: 'RP-2026-009', my_role: 'researcher', my_roles: ['researcher', 'project-leader'] },
  { id: 1, code: 'MOST-RD-2026-001', my_role: 'research-assistant', my_roles: ['research-assistant'] },
  { id: 2, code: 'OLD', my_role: 'project-leader' }, // older API without my_roles
];

test('role codes map to project role slugs; the Program Leader sees all', () => {
  assert.equal(projectRoleFor('PL'), 'project-leader');
  assert.equal(projectRoleFor('FIN'), 'finance-admin');
  assert.equal(projectRoleFor('RA'), 'research-assistant');
  assert.equal(projectRoleFor('RES'), 'researcher');
  assert.equal(projectRoleFor('PRG'), null);
});

test('a project counts for every role the user holds there', () => {
  assert.deepEqual(myProjects(list, 'project-leader').map((p) => p.id), [9, 2]);
  assert.deepEqual(myProjects(list, 'researcher').map((p) => p.id), [9]);
  assert.deepEqual(myProjects(list, 'finance-admin'), []);
  assert.equal(myProjects(list, null).length, 3);
  assert.equal(holdsRole(list[1], 'project-leader'), false);
});

test('not-assigned wording', () => {
  assert.equal(notAssignedText('project-leader'), 'You are not assigned as leader of a project yet. Ask the Program Leader to assign you.');
  assert.match(notAssignedText('research-assistant'), /^You are not assigned as Research Assistant of a project yet\./);
});
