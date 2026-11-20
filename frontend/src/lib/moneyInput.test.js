import test from 'node:test';
import assert from 'node:assert/strict';
import { formatMoneyText, moneyError, moneyNumber, moneyText, parseMoney } from './moneyInput.js';

test('commas, spaces and a leading peso sign are accepted', () => {
  assert.equal(moneyNumber('3,178,376.00'), 3178376);
  assert.equal(parseMoney('3,178,376.00').text, '3178376.00');
  assert.equal(moneyNumber('₱ 2,000,000'), 2000000);
  assert.equal(moneyNumber('₱2,000,000.50'), 2000000.5);
  assert.equal(moneyNumber('2 000 000'), 2000000);
  assert.equal(moneyNumber('  1500.5  '), 1500.5);
  assert.equal(moneyNumber('1500'), 1500);
  assert.equal(moneyNumber('0'), 0);
  assert.equal(moneyError('3,178,376.00', { positive: true }), null);
});

test('badly grouped commas, three decimals and letters are rejected', () => {
  assert.equal(parseMoney('1,23.456').value, null);
  assert.match(moneyError('1,23.456'), /pesos/);
  assert.match(moneyError('1,23'), /pesos/);
  assert.match(moneyError('12,3456'), /pesos/);
  assert.match(moneyError('1500.555'), /two decimals/);
  assert.match(moneyError('3,178,376.005'), /two decimals/);
  assert.match(moneyError('abc'), /pesos/);
  assert.match(moneyError('15OO'), /pesos/);
  assert.match(moneyError('PHP 100'), /pesos/);
  assert.equal(moneyError('-5'), 'The amount cannot be negative.');
  assert.equal(moneyError('₱ -5'), 'The amount cannot be negative.');
  assert.ok(Number.isNaN(moneyNumber('abc')));
});

test('blank, required and positive', () => {
  assert.equal(parseMoney('').blank, true);
  assert.equal(parseMoney('₱').blank, true);
  assert.equal(moneyError(''), 'Enter an amount.');
  assert.equal(moneyError('', { required: false }), null);
  assert.equal(moneyError('0', { positive: true }), 'The amount must be more than zero.');
  assert.ok(Number.isNaN(moneyNumber('')));
});

test('the field shows the value with commas when it loses focus', () => {
  assert.equal(formatMoneyText('3178376'), '3,178,376.00');
  assert.equal(formatMoneyText('₱ 2,000,000'), '2,000,000.00');
  assert.equal(formatMoneyText('1500.5'), '1,500.50');
  // Invalid text stays as typed so it can be fixed.
  assert.equal(formatMoneyText('1,23.456'), '1,23.456');
  assert.equal(formatMoneyText(''), '');
  assert.equal(moneyText(1500.5), '1,500.50');
  assert.equal(moneyText('490000.00'), '490,000.00');
  assert.equal(moneyText(null), '');
});
