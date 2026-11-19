import assert from 'node:assert/strict';
import test from 'node:test';

import { nextStep } from './submitMessage.js';

test('names the reviewer the server routed the item to', () => {
  assert.equal(nextStep('Expense submitted to the Project Leader.'), 'It is waiting for the Project Leader.');
  assert.equal(
    nextStep('Expense submitted. It was recorded by the Project Leader, so it goes straight to Finance/Admin.'),
    'It was recorded by the Project Leader, so it goes straight to Finance/Admin.',
  );
  assert.equal(nextStep('Payroll submitted. It goes to the Program Leader for approval.'), 'It goes to the Program Leader for approval.');
});

test('never claims "another Project Leader", and copes with no message', () => {
  assert.equal(nextStep(undefined), 'It is on its way to the next reviewer.');
  assert.doesNotMatch(nextStep('Purchase request submitted. It was made by the Project Leader, so it skips the Project Leader approval.'), /another/i);
});
