import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
  ONE_PROJECT_HELP,
  canAddProjectRow,
  registrationProjectChoices,
  rowsForRole,
  singleProjectRole,
} from './registrationAssignments.js';

let key = 100;
const makeRow = () => ({ key: key++, project_id: '' });
const row = (projectId) => ({ key: key++, project_id: String(projectId) });

test('"+ Add project" is hidden for a Project Leader only', () => {
  assert.equal(canAddProjectRow('project-leader'), false);
  for (const role of ['', 'research-assistant', 'researcher', 'finance-admin']) {
    assert.equal(canAddProjectRow(role), true, role);
  }
  assert.equal(singleProjectRole('project-leader'), true);
  assert.match(ONE_PROJECT_HELP, /Project Leader leads one project/);
});

test('switching to Project Leader keeps only the first project and says so', () => {
  const rows = [row(1), row(2), row(3)];
  const result = rowsForRole(rows, 'project-leader', makeRow, 'research-assistant');
  assert.deepEqual(result.rows.map((item) => item.project_id), ['1']);
  assert.equal(result.trimmed, true);
});

test('a Project Leader always gets one slot, and no note when nothing was dropped', () => {
  const empty = rowsForRole([], 'project-leader', makeRow);
  assert.equal(empty.rows.length, 1);
  assert.equal(empty.rows[0].project_id, '');
  assert.equal(empty.trimmed, false);
  assert.equal(rowsForRole([row(1), makeRow()], 'project-leader', makeRow).trimmed, false);
});

test('leaving Project Leader drops the empty slot; other roles keep their rows', () => {
  assert.deepEqual(rowsForRole([makeRow()], 'researcher', makeRow, 'project-leader').rows, []);
  const rows = [row(1), makeRow()];
  assert.equal(rowsForRole(rows, 'researcher', makeRow, 'research-assistant').rows, rows);
});

test('for a Project Leader, projects that already have one are disabled with the reason', () => {
  const projects = [
    { id: 1, title: 'Free', code: 'A', leader: null },
    { id: 2, title: 'Taken', code: 'B', leader: { id: 9, name: 'Ana Cruz' } },
  ];
  const leaderChoices = registrationProjectChoices(projects, 'project-leader');
  assert.deepEqual(leaderChoices.map((project) => project.disabled), [false, true]);
  assert.match(leaderChoices[1].reason, /Already led by Ana Cruz\. A project has one Project Leader\./);
  assert.deepEqual(registrationProjectChoices(projects, 'research-assistant').map((project) => project.disabled), [false, false]);
});

test('the drawer gates "+ Add project" and the Remove button on the role', () => {
  const panel = readFileSync(new URL('../components/program/RegistrationReviewPanel.jsx', import.meta.url), 'utf8');
  // The button is rendered only inside the canAddProjectRow(role) block.
  assert.match(panel, /\{canAddProjectRow\(role\) && \(\s*<div>\s*<button[^>]*onClick=\{addRow\}>\s*\+ Add project/);
  assert.equal(panel.match(/onClick=\{addRow\}/g).length, 1);
  assert.match(panel, /\{!singleProject && <button[\s\S]*?Remove project/);
  assert.match(panel, /onChange=\{\(event\) => changeRole\(event\.target\.value\)\}/);
  assert.match(panel, /disabled=\{project\.disabled\}/);
});
