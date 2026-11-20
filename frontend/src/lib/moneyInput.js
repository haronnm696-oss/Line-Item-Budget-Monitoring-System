/**
 * One parser for every peso field (total budget, MOA grant, line items,
 * releases, honoraria, expenses, purchase requests, payroll). People type
 * "3,178,376.00" or "₱ 2,000,000": a leading ₱, commas and spaces between
 * groups of three digits are accepted and stripped; at most two decimals.
 * The server (App\Support\MoneyInput) normalises the same way.
 */

const PLAIN = /^\d+(\.\d+)?$/;
// Thousands separated by a comma or a space, every group after the first exactly three digits.
const GROUPED = /^\d{1,3}(?:[, ]\d{3})+(\.\d+)?$/;

export const MONEY_FORMAT_ERROR = 'Enter an amount in pesos, for example 3,178,376.00.';
export const MONEY_DECIMALS_ERROR = 'Enter a number with at most two decimals.';
export const MONEY_NEGATIVE_ERROR = 'The amount cannot be negative.';

/** Strip ₱ and the separators; null when the text is not a well-formed amount. */
function digits(text) {
  let value = String(text ?? '').trim().replace(/^₱\s*/, '').trim();
  if (!PLAIN.test(value) && !GROUPED.test(value)) return null;
  value = value.replace(/[, ]/g, '');
  return value;
}

/**
 * { blank, value, text, error }: value is the number (null when blank or
 * invalid), text the plain form sent to the API ("3178376.00" -> "3178376.00").
 */
export function parseMoney(input) {
  const raw = String(input ?? '').trim();
  if (raw === '' || raw === '₱') return { blank: true, value: null, text: '', error: null };
  if (/^-/.test(raw.replace(/^₱\s*/, ''))) return { blank: false, value: null, text: raw, error: MONEY_NEGATIVE_ERROR };
  const plain = digits(raw);
  if (plain === null) return { blank: false, value: null, text: raw, error: MONEY_FORMAT_ERROR };
  if (/\.\d{3,}$/.test(plain)) return { blank: false, value: null, text: raw, error: MONEY_DECIMALS_ERROR };
  return { blank: false, value: Number(plain), text: plain, error: null };
}

/** The amount as a number; NaN when blank or invalid (so `> 0` checks fail as with Number('x')). */
export function moneyNumber(input) {
  const parsed = parseMoney(input);
  return parsed.value ?? NaN;
}

/** Error text for a money field, or null. `required` (default) treats blank as an error. */
export function moneyError(input, { positive = false, required = true } = {}) {
  const parsed = parseMoney(input);
  if (parsed.blank) return required ? 'Enter an amount.' : null;
  if (parsed.error) return parsed.error;
  if (positive && !(parsed.value > 0)) return 'The amount must be more than zero.';
  return null;
}

/** "3178376" -> "3,178,376.00" when the field loses focus; anything not valid stays as typed. */
export function formatMoneyText(input) {
  const parsed = parseMoney(input);
  if (parsed.blank || parsed.error) return String(input ?? '');
  return parsed.value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** A stored amount (number or "1500.50") as the text a money field starts with: "1,500.50"; '' for null. */
export function moneyText(value) {
  if (value === null || value === undefined || value === '') return '';
  return formatMoneyText(String(value));
}
