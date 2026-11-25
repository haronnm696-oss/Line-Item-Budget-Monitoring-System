import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { UNSUPPORTED_FILTER, LIST_LOAD_ERROR, approvalListError, typeOptions } from './approvalFilters.js';

// The `types` list GET /api/program/approvals returns (ApprovalRequest::FILTER_TYPES).
const SERVER_TYPES = [
  { value: 'fund-release', label: 'Fund release' },
  { value: 'realignment', label: 'Realignment' },
  { value: 'liquidation', label: 'Liquidation' },
  { value: 'payroll', label: 'Payroll' },
  { value: 'expense', label: 'Expense verification' },
];

test('the Type options are "All types" plus exactly the shared list, in order', () => {
  assert.deepEqual(typeOptions(SERVER_TYPES), [
    ['all', 'All types'],
    ...SERVER_TYPES.map(({ value, label }) => [value, label]),
  ]);
});

test('the fixture above is the backend list (ApprovalRequest::FILTER_TYPES)', (t) => {
  const model = fileURLToPath(new URL('../../../backend/app/Models/ApprovalRequest.php', import.meta.url));
  if (!existsSync(model)) {
    t.skip('backend not checked out next to the frontend');
    return;
  }
  const block = readFileSync(model, 'utf8').match(/const FILTER_TYPES = \[([\s\S]*?)\n\s*\];/);
  assert.ok(block, 'FILTER_TYPES not found in ApprovalRequest.php');
  const backend = [...block[1].matchAll(/'([^']+)' => \['type' => '[^']+', 'label' => '([^']+)'\]/g)]
    .map(([, value, label]) => ({ value, label }));
  assert.deepEqual(backend, SERVER_TYPES);
});

test('every option the select offers is a value the server accepts', () => {
  const accepted = new Set(['all', ...SERVER_TYPES.map((type) => type.value)]);
  for (const [value] of typeOptions(SERVER_TYPES, 'expense')) {
    assert.ok(accepted.has(value), value);
  }
});

test('a selected value the list does not hold stays visible as an option', () => {
  assert.deepEqual(typeOptions(SERVER_TYPES, 'budget-change').at(-1), ['budget-change', 'budget-change']);
  // Before the first response: only "All types" plus the selection.
  assert.deepEqual(typeOptions(undefined, 'expense'), [['all', 'All types'], ['expense', 'expense']]);
  assert.deepEqual(typeOptions(undefined), [['all', 'All types']]);
});

test('a 422 says the filter is not supported and gives the field message', () => {
  const error = {
    response: {
      status: 422,
      data: { message: 'The given data was invalid.', errors: { type: ['The selected type is invalid.'] } },
    },
  };
  assert.equal(approvalListError(error), `${UNSUPPORTED_FILTER} The selected type is invalid.`);
  assert.equal(approvalListError({ response: { status: 422, data: {} } }), UNSUPPORTED_FILTER);
});

test('other failures never show the generic validation text or server errors', () => {
  assert.equal(approvalListError({ response: { status: 500, data: { message: 'Server Error' } } }), LIST_LOAD_ERROR);
  assert.equal(approvalListError(new Error('Network Error')), LIST_LOAD_ERROR);
  assert.equal(approvalListError({ response: { status: 403, data: { message: 'Forbidden.' } } }), 'Forbidden.');
});
