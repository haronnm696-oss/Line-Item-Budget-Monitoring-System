import { test } from 'node:test';
import assert from 'node:assert/strict';
import { COUNTERPART, FUNDER, effectiveSource, hasCounterpart, sourceAvailable, sourceLabels, sourceName } from './fundingSource.js';

const withCounterpart = {
  id: 1, available: 4500, has_counterpart: true,
  sources: { funder: { allocated: 5000, available: 3500 }, implementing_agency: { allocated: 1000, available: 1000 } },
};
const funderOnly = {
  id: 2, available: 800, has_counterpart: false,
  sources: { funder: { allocated: 800, available: 800 }, implementing_agency: { allocated: 0, available: 0 } },
};

test('"Funded by" is offered only on a line item with a counterpart', () => {
  assert.equal(hasCounterpart(withCounterpart), true);
  assert.equal(hasCounterpart(funderOnly), false);
  assert.equal(hasCounterpart({ implementing_agency_amount: 25 }), true);
  assert.equal(hasCounterpart(null), false);
});

test('the funder is the default and the only choice without a counterpart', () => {
  assert.equal(effectiveSource(withCounterpart, COUNTERPART), COUNTERPART);
  assert.equal(effectiveSource(withCounterpart, ''), FUNDER);
  assert.equal(effectiveSource(funderOnly, COUNTERPART), FUNDER);
});

test('available balance follows the chosen source', () => {
  assert.equal(sourceAvailable(withCounterpart, FUNDER), 3500);
  assert.equal(sourceAvailable(withCounterpart, COUNTERPART), 1000);
  assert.equal(sourceAvailable(funderOnly, COUNTERPART), 800);
  assert.equal(sourceAvailable({ available: 12 }, FUNDER), 12);
});

test('labels come from the project funder, never a built-in one', () => {
  assert.deepEqual(sourceLabels({ funder: 'DOST', implementing_agency: 'MSU' }), { funder: 'DOST', implementing_agency: 'MSU' });
  assert.equal(sourceName(FUNDER, { funder: 'DOST', implementing_agency: 'MSU' }), 'DOST');
  assert.equal(sourceName(COUNTERPART, { funder: 'DOST', implementing_agency: 'MSU' }), 'MSU counterpart');
  assert.equal(sourceLabels(null).funder, 'Funder');
  assert.ok(!JSON.stringify(sourceLabels({ funder: 'DOST' })).includes('MOST'));
});
