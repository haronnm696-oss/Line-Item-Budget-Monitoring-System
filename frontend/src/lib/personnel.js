import { MONEY_NEGATIVE_ERROR, moneyText, parseMoney } from './moneyInput.js';
import { DATE_RANGE_ERROR, inDateRange } from './dateRange.js';

// Project personnel (the team appointed by Special Order, paid PS honoraria
// through payroll). The server (PersonnelController / PersonnelService)
// checks the same rules; these give early, clear messages.
//
// The bank account number never comes back from the server: screens only
// get `bank_account_masked` ("****9170").

const ACCOUNT = /^[0-9][0-9 -]*[0-9]$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

/** Form strings for a record (null = a new one). The bank account always starts blank. */
export function personnelForm(record) {
  const text = (value) => (value === null || value === undefined ? '' : String(value));
  return {
    user_id: text(record?.user_id),
    position: text(record?.position),
    lib_category_id: text(record?.lib_category_id),
    special_order_no: text(record?.special_order_no),
    effective_from: text(record?.effective_from),
    effective_to: text(record?.effective_to),
    monthly_honorarium: moneyText(record?.monthly_honorarium),
    bank_account: '',
    remove_bank_account: false,
  };
}

/**
 * Checks the form. period = { start, end } (the project period).
 * Returns { errors, body }: body is the request (bank_account only when it
 * is set or removed; left out means "keep").
 */
export function validatePersonnel(form, period, { creating }) {
  const errors = {};
  const value = (key) => String(form[key] ?? '').trim();

  if (creating && !value('user_id')) errors.user_id = 'Choose a project member.';
  if (!value('position')) errors.position = 'Enter the position as written in Form B.';
  if (!value('lib_category_id')) errors.lib_category_id = 'Choose the PS line item.';
  if (!value('special_order_no')) errors.special_order_no = 'Enter the Special Order number.';

  const from = value('effective_from');
  const to = value('effective_to');
  const inside = (date) => (!period?.start || date >= period.start) && (!period?.end || date <= period.end);
  if (!DATE.test(from)) errors.effective_from = inDateRange(from) ? 'Enter the start date.' : DATE_RANGE_ERROR;
  else if (!inDateRange(from)) errors.effective_from = DATE_RANGE_ERROR;
  else if (!inside(from)) errors.effective_from = 'The start date must be within the project period.';
  if (!DATE.test(to)) errors.effective_to = inDateRange(to) ? 'Enter the end date.' : DATE_RANGE_ERROR;
  else if (!inDateRange(to)) errors.effective_to = DATE_RANGE_ERROR;
  else if (!inside(to)) errors.effective_to = 'The end date must be within the project period.';
  else if (DATE.test(from) && to < from) errors.effective_to = 'The end date must be on or after the start date.';

  // A peso field: "7,500.00" and "₱ 7,500" are accepted (lib/moneyInput).
  const honorarium = parseMoney(value('monthly_honorarium'));
  if (honorarium.blank) errors.monthly_honorarium = 'Enter the monthly honorarium.';
  else if (honorarium.error === MONEY_NEGATIVE_ERROR) errors.monthly_honorarium = 'The monthly honorarium cannot be negative.';
  else if (honorarium.error) errors.monthly_honorarium = honorarium.error;

  const account = value('bank_account');
  if (account && !ACCOUNT.test(account)) errors.bank_account = 'Use digits only (spaces and dashes are allowed).';
  else if (account.length > 34) errors.bank_account = 'Use 34 characters or fewer.';

  const body = {
    position: value('position'),
    lib_category_id: Number(value('lib_category_id')),
    special_order_no: value('special_order_no'),
    effective_from: from,
    effective_to: to,
    monthly_honorarium: honorarium.value,
  };
  if (creating) body.user_id = Number(value('user_id'));
  if (account) body.bank_account = account;
  else if (form.remove_bank_account) body.bank_account = null;

  return { errors, body };
}

/** "Jan 1, 2026 – Dec 31, 2026" with a date formatter. */
export function periodText(from, to, formatDate) {
  if (!from && !to) return '—';
  return `${from ? formatDate(from) : '…'} – ${to ? formatDate(to) : '…'}`;
}
