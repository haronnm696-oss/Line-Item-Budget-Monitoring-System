import assert from 'node:assert/strict';
import test from 'node:test';

import { formatDate, formatDateTime, formatInManila, formatShortDateTime } from './datetime.js';

// Run with a non-Philippine zone to prove the result does not depend on the
// viewer's computer: TZ=America/Los_Angeles node --test src/lib/datetime.test.js

test('a UTC timestamp is shown in Philippine time (UTC+8)', () => {
  const text = formatDateTime('2026-11-14T02:30:00+00:00');
  assert.match(text, /Nov 14, 2026/);
  assert.match(text, /10:30/);
  assert.match(text, /AM/i);
});

test('a UTC timestamp late in the day moves to the next Philippine date', () => {
  assert.equal(formatDate('2026-11-14T17:00:00+00:00'), 'Nov 15, 2026');
});

test('calendar dates are never shifted', () => {
  assert.equal(formatDate('2026-11-10'), 'Nov 10, 2026');
  assert.equal(formatInManila('2026-03', { month: 'long', year: 'numeric' }), 'March 2026');
});

test('the short list time leaves out the current year, keeps an older one', () => {
  const now = new Date('2026-11-26T03:00:00Z');
  const plain = (text) => text.replace(/\s/g, ' ');
  assert.equal(plain(formatShortDateTime('2026-11-26T02:55:00+00:00', '—', now)), 'Nov 26, 10:55 AM');
  assert.match(plain(formatShortDateTime('2025-11-26T02:55:00+00:00', '—', now)), /^Nov 26, 2025, 10:55 AM$/);
  assert.equal(formatShortDateTime(null), '—');
});

test('empty or invalid values show the fallback', () => {
  assert.equal(formatDateTime(null), '—');
  assert.equal(formatDate('not a date'), '—');
  assert.equal(formatDate('', 'n/a'), 'n/a');
});
