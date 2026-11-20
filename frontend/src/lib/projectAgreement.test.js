import test from 'node:test';
import assert from 'node:assert/strict';
import { agreementLines, durationInWords, periodErrors } from './projectAgreement.js';

test('the duration reads like Form B', () => {
  // The same cases as ProjectAgreementTest::test_the_duration_is_in_words_like_form_b (server).
  assert.equal(durationInWords('2026-01-01', '2026-12-31'), 'Twelve (12) months');
  assert.equal(durationInWords('2026-01-01', '2026-01-31'), 'One (1) month');
  assert.equal(durationInWords('2026-01-01', '2026-01-02'), 'One (1) month');
  assert.equal(durationInWords('2026-01-01', '2027-06-30'), 'Eighteen (18) months');
  assert.equal(durationInWords('2026-01-15', '2028-01-14'), 'Twenty-four (24) months');
  assert.equal(durationInWords('2026-01-31', '2026-02-27'), 'One (1) month');
  assert.equal(durationInWords('2026-01-01', '2026-02-01'), 'Two (2) months'); // a day past one month
});

test('no duration for a missing or reversed period', () => {
  assert.equal(durationInWords('', '2026-12-31'), '');
  assert.equal(durationInWords('2026-12-31', '2026-01-01'), '');
});

test('the end date must be after the start date', () => {
  assert.deepEqual(periodErrors('2026-01-01', '2026-12-31'), {});
  assert.deepEqual(periodErrors('2026-01-01', '2026-01-01'), { end_date: 'The end date must be after the start date.' });
  assert.deepEqual(periodErrors('2026-03-01', '2026-02-01'), { end_date: 'The end date must be after the start date.' });
  assert.deepEqual(periodErrors('', ''), { start_date: 'Enter the start date.', end_date: 'Enter the end date.' });
});

test('MOA lines show only what is set', () => {
  const fmt = (iso) => `[${iso}]`;
  assert.deepEqual(agreementLines({
    moa_number: 'MOA No. 2026-014', moa_date: '2025-12-10', rd_committee_approved_date: '2025-11-28',
    fund_source: 'MOST-BARMM GAAB 2024 Program Funds', duration: 'Twelve (12) months',
  }, fmt), [
    'MOA MOA No. 2026-014, dated [2025-12-10]',
    "Approved by the funder's R&D committee on [2025-11-28]",
    'Fund source: MOST-BARMM GAAB 2024 Program Funds',
    'Duration: Twelve (12) months',
  ]);
  assert.deepEqual(agreementLines({ duration: 'One (1) month' }, fmt), ['Duration: One (1) month']);
  assert.deepEqual(agreementLines(null, fmt), []);
});
