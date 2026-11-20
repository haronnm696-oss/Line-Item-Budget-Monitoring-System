import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

// No JSX renderer is set up for node:test, so these check the component sources.
const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');

const approvals = source('./ProgramApprovals.jsx');
const panel = source('../../components/program/ApprovalPanel.jsx');
const users = source('./ProgramUsers.jsx');

test('decided approval cards keep "View details" but no audit trail link', () => {
  const row = approvals.slice(approvals.indexOf('function DecidedRow'), approvals.indexOf('function ListSkeleton'));
  assert.match(row, />View details</);
  assert.doesNotMatch(row, /View in audit trail/);
  assert.doesNotMatch(approvals, /View in audit trail/);
});

test('the approval detail panel links to the audit trail for the project', () => {
  assert.match(panel, /View in audit trail/);
  assert.match(panel, /to=\{`\/program-audit\?project=\$\{approval\.project\.id\}`\}/);
});

test('Users and roles header has "Add user" and no top "Assign to project" button', () => {
  const head = users.slice(users.indexOf('program-head-actions'), users.indexOf('<LeaderConflicts'));
  assert.match(head, />\s*Add user\s*</);
  assert.doesNotMatch(head, /Assign to project/);
});

const manage = source('../../components/program/ProgramUserManage.jsx');

test('the Manage menu has "Edit project assignments" and no "Assign to project" item', () => {
  const menu = manage.slice(manage.indexOf('export function ManageMenu'), manage.indexOf('function Dialog('));
  assert.match(menu, /Edit project assignments/);
  assert.doesNotMatch(menu, /Assign to project/);
  assert.doesNotMatch(menu, /onAssign\b/);
  // Opens with no assignments yet, so a first project can be added.
  assert.match(menu, /canEditAssignments = hasAssignments \|\| canAssign/);
});

test('"Edit project assignments" adds a project through the shared assign dialog and service', () => {
  const dialog = manage.slice(manage.indexOf('export function AssignmentsDialog'));
  assert.match(dialog, /Add to a project/);
  assert.match(dialog, /onClick=\{onAdd\}/);
  assert.match(users, /onAdd=\{\(\) => \{[\s\S]*?setAssignDefaultUserId\(String\(dialog\.user\.id\)\)[\s\S]*?setAssignOpen\(true\)/);
  assert.match(users, /<ProgramAssignUserModal/);
  assert.match(users, /await assignToProject\(payload\)/);
  // Removing still goes through the same dialog.
  assert.match(users, /onRemove=\{\(projectId, reason\) => removeProjectAssignment/);
});
