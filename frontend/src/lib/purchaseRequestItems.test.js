import test from 'node:test';
import assert from 'node:assert/strict';
import {
  UNITS, emptyItem, itemCost, itemErrors, itemsPayload, itemsToForm, itemsTotal, linkOptions, totalsByLine,
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

test('link options: one per request and open line item', () => {
  const requests = [{
    id: 9, purpose: 'Lab set-up', supplier: 'ABC', funding_source: 'funder',
    items: [
      { lib_category_id: 1, line_item: 'Equipment', estimated_cost: 80000, funding_source: 'funder', expense_linked: true },
      { lib_category_id: 2, line_item: 'Supplies', estimated_cost: 12000, funding_source: 'funder' },
      { lib_category_id: 2, line_item: 'Supplies', estimated_cost: 500.5, funding_source: 'funder' },
    ],
  }, { id: 10, description: 'Old', lib_category_id: 3, line_item: 'Travel', estimated_amount: 4500, funding_source: 'implementing_agency' }];
  const options = linkOptions(requests);
  assert.deepEqual(options.map((o) => o.key), ['9:2', '10:3']);
  assert.equal(options[0].estimated_amount, 12500.5);
  assert.equal(options[0].description, 'Lab set-up');
  assert.equal(options[0].several_lines, true);
  assert.equal(options[1].funding_source, 'implementing_agency');
  assert.equal(options[1].several_lines, false);
});
