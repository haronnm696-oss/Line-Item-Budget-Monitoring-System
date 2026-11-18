import assert from 'node:assert/strict';
import test from 'node:test';

import { formatPct } from './money.js';

test('a share above zero that rounds to 0.0% shows "<0.1%"', () => {
  assert.equal(formatPct(0.04), '<0.1%');
  assert.equal(formatPct(0.0001), '<0.1%');
  assert.equal(formatPct('0.03'), '<0.1%');
});

test('a share just under 100% shows "99.9%+" instead of 100.0%', () => {
  assert.equal(formatPct(99.96), '99.9%+');
  assert.equal(formatPct(99.9999), '99.9%+');
});

test('zero, exact 100 and ordinary values keep one decimal', () => {
  assert.equal(formatPct(0), '0.0%');
  assert.equal(formatPct(null), '0.0%');
  assert.equal(formatPct(100), '100.0%');
  assert.equal(formatPct(0.05), '0.1%');
  assert.equal(formatPct(42.84), '42.8%');
  assert.equal(formatPct(99.94), '99.9%');
  assert.equal(formatPct(112.5), '112.5%');
});
