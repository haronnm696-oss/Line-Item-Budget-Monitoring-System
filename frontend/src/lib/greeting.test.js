import { test } from 'node:test';
import assert from 'node:assert/strict';
import { firstNameOf, greetingFor, greetingText, msUntilNextGreeting } from './greeting.js';

// Manila is UTC+8 with no daylight saving, so "HH:MM Manila" = HH-8 UTC.
const manila = (hh, mm = 0, ss = 0, ms = 0) =>
  new Date(Date.UTC(2026, 10, 20, hh - 8, mm, ss, ms));

test('morning runs until 11:59:59 Manila time', () => {
  assert.equal(greetingFor(manila(0)), 'Good morning');
  assert.equal(greetingFor(manila(6, 30)), 'Good morning');
  assert.equal(greetingFor(manila(11, 59, 59, 999)), 'Good morning');
});

test('afternoon runs from 12:00 to 17:59 Manila time', () => {
  assert.equal(greetingFor(manila(12)), 'Good afternoon');
  assert.equal(greetingFor(manila(17, 59, 59)), 'Good afternoon');
});

test('evening starts at 18:00 Manila time and lasts to midnight', () => {
  assert.equal(greetingFor(manila(18)), 'Good evening');
  assert.equal(greetingFor(manila(23, 59)), 'Good evening');
});

test('uses Manila time, not UTC: 03:00 UTC is 11:00 in Manila', () => {
  assert.equal(greetingFor(new Date('2026-11-20T03:00:00Z')), 'Good morning');
  assert.equal(greetingFor(new Date('2026-11-20T04:00:00Z')), 'Good afternoon');
  assert.equal(greetingFor(new Date('2026-11-20T10:00:00Z')), 'Good evening');
  assert.equal(greetingFor(new Date('2026-11-20T16:00:00Z')), 'Good morning');
});

test('first name skips titles', () => {
  assert.equal(firstNameOf('Dr. Aminah Macarambon'), 'Aminah');
  assert.equal(firstNameOf('Engr. Abdul Ali'), 'Abdul');
  assert.equal(firstNameOf('engr Abdul Ali'), 'Abdul');
  assert.equal(firstNameOf('Prof. Dr. Norjannah Haron'), 'Norjannah');
  assert.equal(firstNameOf('Atty. Sittie Ali'), 'Sittie');
  assert.equal(firstNameOf('  Norjannah   Haron '), 'Norjannah');
  assert.equal(firstNameOf('Drew Santos'), 'Drew');
  assert.equal(firstNameOf(''), '');
  assert.equal(firstNameOf(null), '');
});

test('greeting text', () => {
  assert.equal(greetingText('Dr. Aminah Macarambon', manila(9)), 'Good morning, Aminah!');
  assert.equal(greetingText('Abdul Ali', manila(19)), 'Good evening, Abdul!');
  assert.equal(greetingText(undefined, manila(13)), 'Good afternoon!');
});

test('time until the next boundary', () => {
  assert.equal(msUntilNextGreeting(manila(11, 59, 59, 500)), 500);
  assert.equal(msUntilNextGreeting(manila(12)), 6 * 3600000);
  assert.equal(msUntilNextGreeting(manila(23)), 3600000);
});
