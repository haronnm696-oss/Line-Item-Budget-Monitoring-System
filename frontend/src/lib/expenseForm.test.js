import test from 'node:test';
import assert from 'node:assert/strict';
import {
  HONORARIA_NOTE, defaultLineItemId, expenseLineItems, groupLineItems, honorariaLineItems, lineItemAvailable, prefillFromRequest,
  rememberLineItemId, rememberedLineItemId, singleLineItemId,
} from './expenseForm.js';

const items = [
  { id: 1, type: 'MOOE', name: 'Supplies', allocated: 1000, spent: 200, pending: 100, available: 700 },
  { id: 2, type: 'PS', name: 'Honoraria', allocated: 500, spent: 0, pending: 0, available: 500 },
  { id: 3, type: 'MOOE', name: 'Travel', allocated: 300, spent: 50, pending: 25 },
];

test('line items are grouped under PS then MOOE, keeping order', () => {
  const groups = groupLineItems(items);
  assert.deepEqual(groups.map((g) => g.type), ['PS', 'MOOE']);
  assert.deepEqual(groups[0].items.map((i) => i.id), [2]);
  assert.deepEqual(groups[1].items.map((i) => i.id), [1, 3]);
});

test('empty groups are left out', () => {
  assert.deepEqual(groupLineItems([items[0]]).map((g) => g.type), ['MOOE']);
  assert.deepEqual(groupLineItems([]), []);
  assert.deepEqual(groupLineItems(undefined), []);
});

test('available is allocated - spent - pending', () => {
  assert.equal(lineItemAvailable(items[0]), 700);
  assert.equal(lineItemAvailable(items[2]), 225);
});

test('a single line item is selected automatically', () => {
  assert.equal(singleLineItemId([items[1]]), '2');
  assert.equal(singleLineItemId(items), '');
  assert.equal(singleLineItemId([]), '');
  assert.equal(defaultLineItemId([items[1]], '1'), '2');
  assert.equal(defaultLineItemId(items, '3'), '3');
  assert.equal(defaultLineItemId(items, ''), '');
});

test('the last line item is remembered per project and ignored when gone', () => {
  const data = new Map();
  const storage = { getItem: (k) => data.get(k) ?? null, setItem: (k, v) => data.set(k, v) };
  rememberLineItemId(storage, 7, 10, 3);
  assert.equal(rememberedLineItemId(storage, 7, 10, items), '3');
  assert.equal(rememberedLineItemId(storage, 7, 11, items), '');
  assert.equal(rememberedLineItemId(storage, 7, 10, [items[0]]), '');
});

test('failing storage never throws', () => {
  const broken = { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('full'); } };
  assert.doesNotThrow(() => rememberLineItemId(broken, 1, 1, 2));
  assert.equal(rememberedLineItemId(broken, 1, 1, items), '');
  assert.equal(rememberedLineItemId(null, 1, 1, items), '');
});

const blank = { lib_category_id: '', description: '', amount: '', expense_date: '2026-11-17', payee: '', reference_no: '', purchase_request_id: '' };
const pr = { id: 5, lib_category_id: 1, description: 'Bond paper', estimated_amount: 1250.5, supplier: 'ABC Store' };
const pr2 = { id: 6, lib_category_id: 3, description: 'Bus fare', estimated_amount: 300 };

test('linking a purchase request fills line item, amount and payee', () => {
  const next = prefillFromRequest(blank, pr);
  assert.equal(next.purchase_request_id, '5');
  assert.equal(next.lib_category_id, '1');
  assert.equal(next.amount, '1,250.50');
  assert.equal(next.payee, 'ABC Store');
  assert.equal(next.description, 'Bond paper');
  assert.equal(next.expense_date, '2026-11-17');
});

test('values the user typed are kept; the line item always follows the request', () => {
  const typed = { ...blank, lib_category_id: '3', amount: '999', payee: 'Mine' };
  const next = prefillFromRequest(typed, pr);
  assert.equal(next.lib_category_id, '1');
  assert.equal(next.amount, '999');
  assert.equal(next.payee, 'Mine');
});

test('switching requests replaces the previous prefill; unlinking clears it', () => {
  const first = prefillFromRequest(blank, pr);
  const second = prefillFromRequest(first, pr2, pr);
  assert.equal(second.lib_category_id, '3');
  assert.equal(second.amount, '300.00');
  assert.equal(second.payee, '');
  assert.equal(second.description, 'Bus fare');
  const none = prefillFromRequest(second, null, pr2);
  assert.equal(none.purchase_request_id, '');
  assert.equal(none.amount, '');
  assert.equal(none.lib_category_id, '3');
});

test('a request without a supplier leaves the payee as it was', () => {
  const legacy = { id: 7, lib_category_id: 1, description: 'Ink', estimated_amount: 80, supplier: null };
  assert.equal(prefillFromRequest(blank, legacy).payee, '');
  assert.equal(prefillFromRequest({ ...blank, payee: 'Typed' }, legacy).payee, 'Typed');
});

test('honoraria lines (personnel linked) are not offered for expenses', () => {
  const withHonoraria = [
    ...items,
    { id: 4, type: 'PS', name: 'Honoraria of project staff', honoraria: true, available: 90000 },
  ];
  assert.deepEqual(expenseLineItems(withHonoraria).map((item) => item.id), [1, 2, 3]);
  assert.deepEqual(honorariaLineItems(withHonoraria).map((item) => item.id), [4]);
  // The grouped options no longer list it, and with one line left it is not defaulted to.
  assert.ok(!groupLineItems(expenseLineItems(withHonoraria)).flatMap((g) => g.items).some((item) => item.honoraria));
  assert.equal(defaultLineItemId(expenseLineItems([withHonoraria[3], items[0]])), '1');
  assert.equal(HONORARIA_NOTE, 'Honoraria are paid through Payroll');
  assert.deepEqual(expenseLineItems(null), []);
});
