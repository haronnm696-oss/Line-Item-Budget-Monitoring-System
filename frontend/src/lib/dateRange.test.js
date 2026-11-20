import test from 'node:test';
import assert from 'node:assert/strict';
import { DATE_BOUNDS, DATE_RANGE_ERROR, MONTH_BOUNDS, dateRangeError, inDateRange } from './dateRange.js';
import { periodErrors } from './projectAgreement.js';
import { validatePersonnel } from './personnel.js';

test('inputs carry min 2000-01-01 and max 2100-12-31', () => {
  assert.deepEqual(DATE_BOUNDS, { min: '2000-01-01', max: '2100-12-31' });
  assert.deepEqual(MONTH_BOUNDS, { min: '2000-01', max: '2100-12' });
});

test('dates from 2000 to 2100 pass; six-digit and out-of-range years fail', () => {
  for (const ok of ['', '2000-01-01', '2026-12-15', '2100-12-31', '2026-11']) assert.equal(inDateRange(ok), true, ok);
  for (const bad of ['222222-12-15', '1999-12-31', '2101-01-01', '0222-12-15', '99999-01']) {
    assert.equal(inDateRange(bad), false, bad);
    assert.equal(dateRangeError(bad), 'Enter a date between 2000 and 2100.');
  }
});

test('the project period keeps end-after-start and adds the range', () => {
  assert.deepEqual(periodErrors('2026-01-01', '2026-12-31'), {});
  assert.equal(periodErrors('222222-12-15', '2026-12-31').start_date, DATE_RANGE_ERROR);
  assert.equal(periodErrors('2026-01-01', '222222-12-15').end_date, DATE_RANGE_ERROR);
  assert.equal(periodErrors('2026-06-01', '2026-01-01').end_date, 'The end date must be after the start date.');
});

test('personnel dates out of range get the range message, not "enter the date"', () => {
  const form = {
    user_id: '1', position: 'Project Staff', lib_category_id: '2', special_order_no: 'SO 1',
    effective_from: '222222-01-01', effective_to: '2026-12-31', monthly_honorarium: '7,500.00', bank_account: '',
  };
  const { errors, body } = validatePersonnel(form, { start: null, end: null }, { creating: true });
  assert.equal(errors.effective_from, DATE_RANGE_ERROR);
  assert.equal(body.monthly_honorarium, 7500);
});
