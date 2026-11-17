import test from 'node:test';
import assert from 'node:assert/strict';
import { rateText, validatePayRate } from './payRate.js';

const peso = (n) => `P${Number(n).toFixed(2)}`;

test('empty values read "Not set"', () => {
  assert.equal(rateText(null, peso), 'Not set');
  assert.equal(rateText(undefined, peso), 'Not set');
  assert.equal(rateText(0, peso), 'P0.00');
  assert.equal(rateText(30000, peso), 'P30000.00');
});

test('valid amounts with up to two decimals', () => {
  assert.deepEqual(validatePayRate('30000', '2000.5'), { errors: {}, payload: { monthly_rate: 30000, allowance: 2000.5 } });
  assert.deepEqual(validatePayRate('0', ''), { errors: {}, payload: { monthly_rate: 0, allowance: 0 } });
  assert.deepEqual(validatePayRate(' 1.25 ', '0.99'), { errors: {}, payload: { monthly_rate: 1.25, allowance: 0.99 } });
});

test('empty monthly rate clears both (Not set)', () => {
  assert.deepEqual(validatePayRate('', ''), { errors: {}, payload: { monthly_rate: null, allowance: null } });
  assert.equal(validatePayRate('', '100').errors.allowance, 'Set a monthly rate before an allowance.');
});

test('negative, three decimals and text are refused', () => {
  assert.equal(validatePayRate('-1', '').errors.monthly_rate, 'The monthly rate cannot be negative.');
  assert.equal(validatePayRate('1', '-5').errors.allowance, 'The allowance cannot be negative.');
  assert.match(validatePayRate('1.234', '').errors.monthly_rate, /two decimals/);
  assert.match(validatePayRate('abc', '').errors.monthly_rate, /two decimals/);
  assert.match(validatePayRate('1', '1e3').errors.allowance, /two decimals/);
});
