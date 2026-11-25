import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { ACCOUNT_ROLE_OPTIONS, ALL_ACCOUNT_ROLE_OPTIONS, assignableRoleOptions } from './roles.js';

// No JSX renderer is set up for node:test, so the dropdowns are checked through their sources.
const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const addUser = source('../components/program/ProgramAddUserModal.jsx');
const review = source('../components/program/RegistrationReviewPanel.jsx');
const manage = source('../components/program/ProgramUserManage.jsx');

test('the account role options never include Program Leader', () => {
  const slugs = ACCOUNT_ROLE_OPTIONS.map((option) => option.slug);
  assert.ok(!slugs.includes('program-leader'));
  assert.deepEqual(slugs, ['project-leader', 'research-assistant', 'finance-admin', 'researcher']);
  // The directory filter still lists every role.
  assert.ok(ALL_ACCOUNT_ROLE_OPTIONS.some((option) => option.slug === 'program-leader'));
});

test('a role list from the server is filtered before it reaches a dropdown', () => {
  const fromServer = [
    { slug: 'program-leader', label: 'Program Leader' },
    { slug: 'researcher', label: 'Researcher' },
  ];
  assert.deepEqual(assignableRoleOptions(fromServer), [{ slug: 'researcher', label: 'Researcher' }]);
  assert.deepEqual(assignableRoleOptions(undefined), []);
});

test('Add user, registration review and Change account role use the list without Program Leader', () => {
  assert.doesNotMatch(addUser, /'program-leader'/);
  assert.match(addUser, /ACCOUNT_ROLE_OPTIONS\.map/);

  assert.match(review, /assignableRoleOptions\(accountRoles\?\.length \? accountRoles : ACCOUNT_ROLE_OPTIONS\)/);

  const dialog = manage.slice(manage.indexOf('export function ChangeRoleDialog'), manage.indexOf('export function StatusDialog'));
  assert.match(dialog, /ACCOUNT_ROLE_OPTIONS\.map/);
  assert.doesNotMatch(dialog, /ALL_ACCOUNT_ROLE_OPTIONS\.map/);
  // Shown only as the current role of a Program Leader, and never selectable.
  assert.match(dialog, /<option value="program-leader" disabled>Program Leader \(current\)<\/option>/);
});
