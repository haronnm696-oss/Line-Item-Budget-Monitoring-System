import assert from 'node:assert/strict';
import test from 'node:test';
import {
  canSwitchProject, choiceKey, chooseProject, readProjectChoice, requestedProject, searchWithProject, showProjectSwitch, withProject,
  writeProjectChoice,
} from './projectChoice.js';
import { pickProject } from './myProjects.js';

function memoryStore() {
  const data = new Map();
  return { getItem: (key) => (data.has(key) ? data.get(key) : null), setItem: (key, value) => data.set(key, String(value)) };
}

const brokenStore = {
  getItem() { throw new Error('SecurityError'); },
  setItem() { throw new Error('QuotaExceededError'); },
};

const ra = (id) => ({ id, title: `Project ${id}`, code: `P-${id}`, my_roles: ['research-assistant'] });

test('"Switch project" shows only with more than one project', () => {
  assert.equal(showProjectSwitch('RA', []), false);
  assert.equal(showProjectSwitch('RA', [ra(1)]), false);
  assert.equal(showProjectSwitch('RA', [ra(1), ra(2)]), true);
  assert.equal(showProjectSwitch('FIN', [ra(1), ra(2)]), true);
  assert.equal(showProjectSwitch('RES', [ra(1), ra(2)]), true);
  assert.equal(showProjectSwitch('RA', null), false);
});

test('the Project Leader (and Program Leader) cannot switch project', () => {
  assert.equal(canSwitchProject('PL'), false);
  assert.equal(showProjectSwitch('PL', [ra(1), ra(2)]), false);
  assert.equal(showProjectSwitch('PRG', [ra(1), ra(2)]), false);
});

test('choosing a project updates this page and carries to the next page', () => {
  const store = memoryStore();
  const projects = [ra(1), ra(2), ra(3)];
  // On Expenses (?project=1&status=returned) the user switches to project 3.
  const search = chooseProject({ userId: 7, role: 'research-assistant', projectId: 3, search: '?project=1&status=returned', store });
  const here = new URLSearchParams(search);
  assert.equal(here.get('project'), '3');
  assert.equal(here.get('status'), 'returned');
  // This page now resolves project 3 from its link...
  assert.equal(pickProject(projects, requestedProject(here.get('project'), null), 'research-assistant').project.id, 3);
  // ...and a page opened with no ?project= (e.g. typed URL) resolves it from memory.
  const remembered = readProjectChoice(7, 'research-assistant', store);
  assert.equal(pickProject(projects, requestedProject(null, remembered), 'research-assistant').project.id, 3);
  // Sidebar links carry it too.
  assert.equal(withProject('/payroll', here.get('project')), '/payroll?project=3');
});

test('the choice carries across pages: remembered per user, and every page resolves it', () => {
  const store = memoryStore();
  const projects = [ra(1), ra(2), ra(3)];
  // Chosen in the top bar on Overview...
  writeProjectChoice(7, 'research-assistant', 2, store);
  // ...then a page opened without ?project= (e.g. Expenses) shows the same project.
  const remembered = readProjectChoice(7, 'research-assistant', store);
  assert.equal(remembered, '2');
  assert.equal(pickProject(projects, requestedProject(null, remembered), 'research-assistant').project.id, 2);
  // Another user on the same browser keeps their own choice.
  assert.equal(readProjectChoice(8, 'research-assistant', store), null);
  assert.equal(pickProject(projects, requestedProject(null, null), 'research-assistant').project.id, 1);
});

test('?project= in the link wins over the remembered choice', () => {
  assert.equal(requestedProject('3', '2'), '3');
  assert.equal(requestedProject(null, '2'), '2');
  assert.equal(requestedProject(null, null), null);
});

test('a remembered project the user no longer has falls back to the first', () => {
  assert.equal(pickProject([ra(1), ra(2)], requestedProject(null, '99'), 'research-assistant').project.id, 1);
});

test('storage failures are safe: reads give null, writes are ignored', () => {
  assert.doesNotThrow(() => writeProjectChoice(7, 'research-assistant', 2, brokenStore));
  assert.equal(readProjectChoice(7, 'research-assistant', brokenStore), null);
  assert.equal(readProjectChoice(null, 'research-assistant', memoryStore()), null);
});

test('keys are per user and role', () => {
  assert.notEqual(choiceKey(7, 'research-assistant'), choiceKey(7, 'finance-admin'));
  assert.notEqual(choiceKey(7, 'researcher'), choiceKey(8, 'researcher'));
});

test('sidebar links keep the project in ?project=', () => {
  assert.equal(withProject('/expenses', 3), '/expenses?project=3');
  assert.equal(withProject('/my-submissions?tab=drafts', 3), '/my-submissions?tab=drafts&project=3');
  assert.equal(withProject('/expenses', null), '/expenses');
});

test('choosing a project keeps filters and drops what belongs to the old project', () => {
  const next = new URLSearchParams(searchWithProject('?project=1&tab=returned&open=expense-4&line_item=9&page=3&expense=4&from=2026-01-01', 2));
  assert.equal(next.get('project'), '2');
  assert.equal(next.get('tab'), 'returned');
  assert.equal(next.get('from'), '2026-01-01');
  for (const key of ['open', 'line_item', 'page', 'expense']) assert.equal(next.has(key), false, key);
});
