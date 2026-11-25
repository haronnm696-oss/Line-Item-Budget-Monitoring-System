import assert from 'node:assert/strict';
import test from 'node:test';
import { waitedText } from './waited.js';

test('waitedText counts whole days and handles empty or bad input', () => {
  const now = Date.parse('2026-11-25T08:00:00Z');
  assert.equal(waitedText('2026-11-25T01:00:00Z', now), 'waiting since today');
  assert.equal(waitedText('2026-11-24T07:00:00Z', now), 'waiting 1 day');
  assert.equal(waitedText('2026-11-20T08:00:00Z', now), 'waiting 5 days');
  assert.equal(waitedText('2026-11-26T08:00:00Z', now), 'waiting since today');
  assert.equal(waitedText(null, now), '');
  assert.equal(waitedText('nope', now), '');
});
