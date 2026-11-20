import test from 'node:test';
import assert from 'node:assert/strict';
import { projectCardRows, projectsSummaryLine } from './programOverview.js';

test('the Projects card lists every project, largest budget first', () => {
  const projects = [
    { id: 1, title: 'Small', budget: '100000.00' },
    { id: 2, title: 'Large', budget: 5000000 },
    { id: 3, title: 'Middle', budget: '750000' },
    { id: 4, title: 'No budget', budget: null },
    { id: 5, title: 'Medium', budget: 750000 },
    { id: 6, title: 'Extra', budget: 1 },
    { id: 7, title: 'Seventh', budget: 2 },
  ];
  const rows = projectCardRows(projects);
  assert.deepEqual(rows.map((p) => p.id), [2, 5, 3, 1, 7, 6, 4]);
  // Not cut to a "top N": every project gets a row.
  assert.equal(rows.length, projects.length);
  // The input is not reordered in place.
  assert.equal(projects[0].id, 1);
});

test('the Projects card handles a missing list', () => {
  assert.deepEqual(projectCardRows(undefined), []);
  assert.deepEqual(projectCardRows(null), []);
});

test('the Projects page summary is one line', () => {
  const line = projectsSummaryLine({ projects: 5, allocated: 3178376, spent: 1000000, spent_pct: 31.46, remaining: 2178376 });
  assert.equal(line, `5 projects · ${'₱3,178,376.00'} allocated · ₱1,000,000.00 spent (31.5%) · ₱2,178,376.00 remaining`);
  assert.match(projectsSummaryLine({ projects: 1, allocated: 0, spent: 0, spent_pct: 0, remaining: 0 }), /^1 project · /);
  assert.equal(projectsSummaryLine(null), '');
});
