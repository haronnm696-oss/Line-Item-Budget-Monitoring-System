import test from 'node:test';
import assert from 'node:assert/strict';
import {
  UNITS, defaultTicks, emptyItem, itemCost, itemErrors, itemsPayload, itemsToForm, itemsTotal, linkableRequests, tickRows, tickedLink, totalsByLine,
} from './purchaseRequestItems.js';

const example = [
  { lib_category_id: '1', quantity: '2', unit: 'unit', description: 'Digital microscope', unit_cost: '40,000.00' },
  { lib_category_id: '2', quantity: '2', unit: 'box', description: 'Specimen containers', unit_cost: '₱6,000' },
  { lib_category_id: '3', quantity: '1', unit: 'lot', description: 'Transport of samples', unit_cost: '13500' },
];

test('the MSU example totals 105,500.00 across three line items', () => {
  assert.deepEqual(example.map(itemCost), [80000, 12000, 13500]);
  assert.equal(itemsTotal(example), 105500);
  assert.deepEqual(totalsByLine(example), { 1: 80000, 2: 12000, 3: 13500 });
});

test('cost is computed in centavos and is 0 while a value is not valid', () => {
  assert.equal(itemCost({ quantity: '3', unit_cost: '33.33' }), 99.99);
  assert.equal(itemsTotal([{ quantity: '3', unit_cost: '0.10' }, { quantity: '1', unit_cost: '0.20' }]), 0.5);
  assert.equal(itemCost({ quantity: '1.5', unit_cost: '10' }), 0);
  assert.equal(itemCost({ quantity: '2', unit_cost: '1,23.4' }), 0);
});

test('items on the same line item add up', () => {
  const rows = [
    { lib_category_id: '7', quantity: '1', unit_cost: '30,000' },
    { lib_category_id: '7', quantity: '1', unit_cost: '30,000' },
    { lib_category_id: '', quantity: '1', unit_cost: '5' },
  ];
  assert.deepEqual(totalsByLine(rows), { 7: 60000 });
});

test('each row is validated', () => {
  const [errors] = itemErrors([{ lib_category_id: '', quantity: '0', unit: 'kilo', description: ' ', unit_cost: '' }]);
  assert.deepEqual(Object.keys(errors).sort(), ['description', 'lib_category_id', 'quantity', 'unit', 'unit_cost']);
  assert.equal(errors.unit_cost, 'Enter a unit cost greater than zero.');
  assert.deepEqual(itemErrors(example), [{}, {}, {}]);
});

test('payload and form rows convert both ways', () => {
  assert.deepEqual(itemsPayload(example)[0], { lib_category_id: 1, quantity: 2, unit: 'unit', description: 'Digital microscope', unit_cost: 40000 });
  assert.deepEqual(itemsToForm([{ lib_category_id: 4, quantity: 3, unit: 'lot', description: 'Glassware', unit_cost: 1500 }]), [
    { lib_category_id: '4', quantity: '3', unit: 'lot', description: 'Glassware', unit_cost: '1,500.00' },
  ]);
  assert.deepEqual(itemsToForm([]), [emptyItem()]);
  assert.ok(UNITS.includes(emptyItem().unit));
});

const approved = {
  id: 9, purpose: 'Lab set-up', supplier: 'ABC', funding_source: 'funder',
  items: [
    { id: 1, lib_category_id: 1, line_item: 'Equipment', estimated_cost: 80000, funding_source: 'funder', status: 'paid', status_label: 'Paid', expense_reference: 'OR-1' },
    { id: 2, lib_category_id: 1, line_item: 'Equipment', estimated_cost: 20000, funding_source: 'funder', status: 'open' },
    { id: 3, lib_category_id: 2, line_item: 'Supplies', estimated_cost: 12000, funding_source: 'funder', status: 'open' },
    { id: 4, lib_category_id: 2, line_item: 'Supplies', estimated_cost: 500.5, funding_source: 'funder', status: 'open' },
  ],
};

test('only requests with an open item can be linked', () => {
  const done = { id: 10, items: [{ id: 5, lib_category_id: 1, status: 'linked' }] };
  assert.deepEqual(linkableRequests([approved, done]).map((r) => r.id), [9]);
});

test('ticking: other line items are disabled, linked and paid items show their status', () => {
  const rows = tickRows(approved, [3]);
  assert.deepEqual(rows.map((r) => [r.item.id, r.checked, r.disabled]), [[1, false, true], [2, false, true], [3, true, false], [4, false, false]]);
  assert.equal(rows[0].note, 'Paid · OR-1');
  assert.equal(rows[1].note, 'Record a separate expense for Equipment');
  // Nothing ticked: every open item can be ticked.
  assert.deepEqual(tickRows(approved, []).map((r) => r.disabled), [true, false, false, false]);
  // Open items on two line items: nothing ticked first; on one line item: all of them.
  assert.deepEqual(defaultTicks(approved), []);
  assert.deepEqual(defaultTicks({ items: approved.items.slice(0, 2) }), [2]);
});

test('the ticked items prefill the line item and the amount (their sum, the cap)', () => {
  const link = tickedLink(approved, [3, 4]);
  assert.equal(link.lib_category_id, 2);
  assert.equal(link.estimated_amount, 12500.5);
  assert.deepEqual(link.item_ids, [3, 4]);
  assert.equal(link.supplier, 'ABC');
  assert.equal(link.description, 'Lab set-up');
  assert.equal(tickedLink(approved, []), null);
  assert.equal(tickedLink(approved, [1]), null); // paid items never count
  // An older request without stored items links as a whole.
  const old = tickedLink({ id: 11, description: 'Old', lib_category_id: 3, estimated_amount: 4500, funding_source: 'implementing_agency' }, []);
  assert.deepEqual([old.item_ids, old.estimated_amount, old.funding_source], [null, 4500, 'implementing_agency']);
});
