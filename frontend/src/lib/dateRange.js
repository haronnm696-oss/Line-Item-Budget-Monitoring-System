/**
 * Every date input is limited to 2000–2100 (min/max on the input, the same
 * check here and on the server: App\Rules\DateInRange). Browsers let people
 * type six-digit years such as 222222-12-15 otherwise.
 */

export const DATE_MIN = '2000-01-01';
export const DATE_MAX = '2100-12-31';
export const MONTH_MIN = '2000-01';
export const MONTH_MAX = '2100-12';
export const DATE_RANGE_ERROR = 'Enter a date between 2000 and 2100.';

/** Attributes for <input type="date">. */
export const DATE_BOUNDS = { min: DATE_MIN, max: DATE_MAX };
/** Attributes for <input type="month">. */
export const MONTH_BOUNDS = { min: MONTH_MIN, max: MONTH_MAX };

/** True for '' (nothing entered) or a YYYY-MM-DD / YYYY-MM value with a year from 2000 to 2100. */
export function inDateRange(value) {
  const text = String(value ?? '').trim();
  if (text === '') return true;
  const match = /^(\d+)-\d{2}(-\d{2})?$/.exec(text);
  if (!match) return false;
  const year = Number(match[1]);
  return match[1].length === 4 && year >= 2000 && year <= 2100;
}

/** DATE_RANGE_ERROR for a filled value outside 2000–2100, else null. */
export function dateRangeError(value) {
  return inDateRange(value) ? null : DATE_RANGE_ERROR;
}
