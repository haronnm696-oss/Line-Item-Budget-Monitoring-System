import { test } from 'node:test';
import assert from 'node:assert/strict';
import { computedAmount, describeComputation, numberWords, periodsLabel, previewComputation } from './lineItemComputation.js';

test('computes quantity x rate x periods (the Form B rows)', () => {
  assert.equal(computedAmount({ quantity: 1, rate: 8800, rate_period: 'month', periods: 12 }), 105600);
  assert.equal(computedAmount({ quantity: 1, rate: 7500, periods: 12 }), 90000);
  assert.equal(computedAmount({ quantity: 2, rate: 6000, periods: 12 }), 144000);
  assert.equal(computedAmount({ quantity: 2, rate: 1500, rate_period: 'quarter', periods: 4 }), 12000);
  assert.equal(computedAmount({ quantity: 1, rate: 56070, periods: 5 }), 280350);
  assert.equal(computedAmount({ quantity: 1, rate: 33130, periods: 5 }), 165650);
  assert.equal(computedAmount({ quantity: 1, rate: 16950, periods: 3 }), 50850);
});

test('periods default to 1; no computation without quantity and rate', () => {
  assert.equal(computedAmount({ quantity: '3', rate: '10.50' }), 31.5);
  assert.equal(computedAmount({ quantity: '', rate: 100 }), null);
  assert.equal(computedAmount({ quantity: 2 }), null);
});

test('describes the computation like Form B', () => {
  assert.equal(
    describeComputation({ quantity: 1, unit_description: 'Project Leader', rate: 8800, rate_period: 'month', periods: 12 }),
    'One (1) Project Leader @ ₱8,800.00/mo. x 12 mos.',
  );
  assert.equal(
    describeComputation({ quantity: 2, unit_description: 'M&E Staff', rate: 1500, rate_period: 'quarter', periods: 4 }),
    'Two (2) M&E Staff @ ₱1,500.00/qtr. x 4 qtrs.',
  );
  assert.equal(describeComputation({ quantity: 1, unit_description: 'lot', rate: 56070, periods: 5 }), 'One (1) lot @ ₱56,070.00 x 5');
  assert.equal(describeComputation({ quantity: 1, rate: 100, rate_period: 'month', periods: 1 }), 'One (1) @ ₱100.00/mo. x 1 mo.');
  assert.equal(describeComputation({ quantity: '', rate: 100 }), null);
});

test('number words', () => {
  assert.equal(numberWords(21), 'twenty-one');
  assert.equal(numberWords(105600), 'one hundred five thousand six hundred');
});

test('plain preview sentence: per month', () => {
  assert.equal(
    previewComputation({ quantity: '1', unit_description: 'Project Leader', rate: '8800', rate_period: 'month', periods: '12' }),
    '1 Project Leader x ₱8,800.00 per month x 12 months = ₱105,600.00',
  );
  assert.equal(
    previewComputation({ quantity: '3', unit_description: 'Project Leader', rate: '100', rate_period: 'month', periods: '' }),
    '3 Project Leaders x ₱100.00 per month x 1 month = ₱300.00',
  );
});

test('plain preview sentence: per quarter', () => {
  assert.equal(
    previewComputation({ quantity: '2', unit_description: 'M&E Staff', rate: '1500', rate_period: 'quarter', periods: '4' }),
    '2 M&E Staff x ₱1,500.00 per quarter x 4 quarters = ₱12,000.00',
  );
});

test('plain preview sentence: one time', () => {
  assert.equal(
    previewComputation({ quantity: '2', unit_description: 'Laptop', rate: '40000', rate_period: '', periods: '' }),
    '2 Laptops x ₱40,000.00 = ₱80,000.00',
  );
  assert.equal(previewComputation({ quantity: '1', unit_description: 'lot', rate: '56070', rate_period: '', periods: '5' }), '1 lot x ₱56,070.00 x 5 times = ₱280,350.00');
  assert.equal(previewComputation({ quantity: '', rate: '100' }), null);
});

test('periods label follows "Paid every"', () => {
  assert.equal(periodsLabel('month'), 'For how many months');
  assert.equal(periodsLabel('quarter'), 'For how many quarters');
});
