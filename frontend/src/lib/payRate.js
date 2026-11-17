// Pay rates on a project assignment (monthly rate and allowance).
// The server (PayRateController) checks the same rules.

export const PAY_RATE_NOTE = 'Changing a rate does not change submitted or verified batches. Draft and returned batches need Recompute.';

const MONEY = /^\d+(\.\d{1,2})?$/;

/** "₱30,000.00", or "Not set" when the value is empty. */
export function rateText(value, peso) {
  return value === null || value === undefined || value === '' ? 'Not set' : peso(value);
}

/** Text for an input: '' when not set, else the number as typed by the server. */
export function rateInput(value) {
  return value === null || value === undefined ? '' : String(value);
}

/**
 * Checks the two fields. Empty monthly rate = "Not set" (null, allowance
 * cleared too). Returns { payload, errors } with errors keyed by field.
 */
export function validatePayRate(monthlyText, allowanceText) {
  const monthly = String(monthlyText ?? '').trim();
  const allowance = String(allowanceText ?? '').trim();
  const errors = {};

  const check = (text, label, key) => {
    if (text === '') return null;
    if (text.startsWith('-')) { errors[key] = `The ${label} cannot be negative.`; return null; }
    if (!MONEY.test(text)) { errors[key] = `Enter the ${label} as a number with at most two decimals.`; return null; }
    return Number(text);
  };

  const monthlyValue = check(monthly, 'monthly rate', 'monthly_rate');
  const allowanceValue = check(allowance, 'allowance', 'allowance');
  if (monthly === '' && allowance !== '' && !errors.allowance) {
    errors.allowance = 'Set a monthly rate before an allowance.';
  }

  return {
    errors,
    payload: { monthly_rate: monthlyValue, allowance: monthlyValue === null ? null : (allowanceValue ?? 0) },
  };
}
