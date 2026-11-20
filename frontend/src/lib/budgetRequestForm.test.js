import test from 'node:test';
import assert from 'node:assert/strict';
import { NO_RELEASES_TEXT, documentsAfter, initialForm, pendingTrancheIds, releaseBlocksSend, releaseField, requestFields, statusLabel } from './budgetRequestForm.js';

test('statuses read Returned / Withdrawn; a legacy rejected row reads Returned', () => {
  assert.equal(statusLabel('returned'), 'Returned');
  assert.equal(statusLabel('withdrawn'), 'Withdrawn');
  assert.equal(statusLabel('rejected'), 'Returned');
  assert.equal(statusLabel('pending'), 'Pending');
});

test('edit and resubmit starts from the returned request', () => {
  const form = initialForm({ type: 'fund_release', project_tranche_id: 4, amount: 250000, title: 'Release 2', justification: 'Fieldwork.' });
  assert.deepEqual(form, { type: 'fund_release', trancheId: '4', fromId: '', toId: '', fundingSource: 'funder', amount: '250,000.00', title: '', justification: 'Fieldwork.' });

  const realign = initialForm({ type: 'realignment', from_lib_category_id: 1, to_lib_category_id: 2, amount: 1000, justification: null });
  assert.equal(realign.fromId, '1');
  assert.equal(realign.fundingSource, 'funder');
  assert.equal(initialForm({ type: 'realignment', funding_source: 'implementing_agency' }).fundingSource, 'implementing_agency');
  assert.equal(realign.toId, '2');
  assert.equal(realign.justification, ''); // legacy rows may have none

  assert.equal(initialForm({ type: 'liquidation', title: 'October receipts', amount: 900 }).title, 'October receipts');
});

test('a new request starts from ?new= and ?tranche=', () => {
  assert.deepEqual(initialForm(null, { type: 'fund_release', trancheId: 7 }), {
    type: 'fund_release', trancheId: '7', fromId: '', toId: '', fundingSource: 'funder', amount: '', title: '', justification: '',
  });
});

test('request body per type', () => {
  const base = { trancheId: '4', fromId: '1', toId: '2', amount: '1000', title: ' Oct ', justification: ' Why ' };
  assert.deepEqual(requestFields({ ...base, type: 'fund_release' }), { justification: 'Why', project_tranche_id: 4, document_type: 'other' });
  assert.deepEqual(requestFields({ ...base, type: 'realignment' }), {
    justification: 'Why', from_lib_category_id: 1, to_lib_category_id: 2, amount: 1000, funding_source: 'funder', document_type: 'other',
  });
  assert.deepEqual(requestFields({ ...base, type: 'liquidation' }), {
    justification: 'Why', amount: 1000, title: 'Oct', project_tranche_id: '4', document_type: 'receipt',
  });
});

test('the request being edited does not block its own tranche', () => {
  const rows = [
    { id: 1, type: 'fund_release', status: 'pending', project_tranche_id: 4 },
    { id: 2, type: 'fund_release', status: 'returned', project_tranche_id: 5 },
    { id: 3, type: 'fund_release', status: 'withdrawn', project_tranche_id: 6 },
  ];
  assert.deepEqual([...pendingTrancheIds(rows)], ['4']);
  assert.deepEqual([...pendingTrancheIds(rows, 1)], []);
});

test('documents left after removals and new files', () => {
  const docs = [{ id: 1 }, { id: 2 }];
  assert.equal(documentsAfter(docs, [1, 2], []), 0);
  assert.equal(documentsAfter(docs, [1], [{}]), 2);
  assert.equal(documentsAfter(null, [], [{}]), 1);
});

test('release field: no releases scheduled asks for the Program Leader and blocks fund release and liquidation', () => {
  const field = releaseField([]);
  assert.equal(field.state, 'none');
  assert.equal(field.placeholder, NO_RELEASES_TEXT);
  assert.equal(field.placeholder, 'No releases scheduled yet. Ask the Program Leader to add them.');
  assert.equal(releaseBlocksSend('fund_release', field), true);
  assert.equal(releaseBlocksSend('liquidation', field), true);
  assert.equal(releaseBlocksSend('realignment', field), false);
  // A missing list (payload not loaded) is "none" too, never "All released".
  assert.equal(releaseField(undefined).state, 'none');
});

test('release field: some planned releases offer the unreleased ones', () => {
  const field = releaseField([
    { id: 1, name: 'Release 1', is_released: true },
    { id: 2, name: 'Release 2', is_released: false },
  ]);
  assert.equal(field.state, 'some');
  assert.equal(field.placeholder, 'Choose a release');
  assert.deepEqual(field.unreleased.map((t) => t.id), [2]);
  assert.equal(field.releases.length, 2);
  assert.equal(releaseBlocksSend('fund_release', field), false);
  assert.equal(releaseBlocksSend('liquidation', field), false);
});

test('release field: "All released" only when releases exist and every one is released', () => {
  const field = releaseField([
    { id: 1, is_released: true },
    { id: 2, is_released: true },
  ]);
  assert.equal(field.state, 'all-released');
  assert.equal(field.placeholder, 'All released');
  assert.equal(field.unreleased.length, 0);
  assert.equal(releaseBlocksSend('fund_release', field), false);
  assert.equal(releaseBlocksSend('liquidation', field), false);
});

test('release field: loading and failed lists say so, never "All released" or "none"', () => {
  assert.equal(releaseField([], { loading: true }).placeholder, 'Loading…');
  const failed = releaseField([], { failed: true });
  assert.equal(failed.state, 'error');
  assert.equal(releaseBlocksSend('fund_release', failed), false);
});
