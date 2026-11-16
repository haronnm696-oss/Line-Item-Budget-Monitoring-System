import assert from 'node:assert/strict';
import test from 'node:test';

import { parseOpen, returnedLine, stagePill } from './assistantStatus.js';

test('every stage has a text label and an icon (never colour alone)', () => {
  for (const status of ['draft', 'submitted', 'endorsed', 'returned', 'verified', 'fulfilled', 'cancelled']) {
    const pill = stagePill(status);
    assert.ok(pill.label.length > 0, status);
    assert.ok(pill.icon.length > 0, status);
  }
  assert.equal(stagePill('submitted', 'purchase_request').label, 'Waiting for Project Leader');
  assert.equal(stagePill('endorsed').label, 'Approved');
});

test('a returned record names the reviewer and the reason', () => {
  assert.equal(
    returnedLine({ workflow_status: 'returned', returned_by: { name: 'Dr. A' }, return_reason: 'Unclear receipt.' }),
    'Dr. A: “Unclear receipt.”',
  );
  assert.equal(returnedLine({ workflow_status: 'submitted' }), null);
});

test('?open= keys parse to a record, anything else to null', () => {
  assert.deepEqual(parseOpen('purchase_request-14'), { type: 'purchase_request', id: 14 });
  assert.equal(parseOpen('approval-2'), null);
  assert.equal(parseOpen(null), null);
});
