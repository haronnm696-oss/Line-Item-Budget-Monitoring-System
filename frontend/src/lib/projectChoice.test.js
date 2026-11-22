import assert from 'node:assert/strict';
import test from 'node:test';
import {
  choiceKey, hasTopBarPicker, readProjectChoice, requestedProject, searchWithProject, showProjectPicker, withProject, writeProjectChoice,
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

test('the top-bar picker shows only with more than one project', () => {
  assert.equal(showProjectPicker('RA', []), false);
  assert.equal(showProjectPicker('RA', [ra(1)]), false);
  assert.equal(showProjectPicker('RA', [ra(1), ra(2)]), true);
  assert.equal(showProjectPicker('FIN', [ra(1), ra(2)]), true);
  assert.equal(showProjectPicker('RES', [ra(1), ra(2)]), true);
  assert.equal(showProjectPicker('RA', null), false);
});

test('the Project Leader (and Program Leader) get no top-bar picker', () => {
  assert.equal(hasTopBarPicker('PL'), false);
  assert.equal(showProjectPicker('PL', [ra(1), ra(2)]), false);
  assert.equal(showProjectPicker('PRG', [ra(1), ra(2)]), false);
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

test('choosing a project sets ?project=, keeps filters and closes an open record', () => {
  const next = new URLSearchParams(searchWithProject('?project=1&tab=returned&open=expense-4', 2));
  assert.equal(next.get('project'), '2');
  assert.equal(next.get('tab'), 'returned');
  assert.equal(next.has('open'), false);
});
