/**
 * The optional Form B computation of a line item, mirroring the server
 * (backend/app/Support/LineItemComputation.php):
 *
 *   quantity x rate [per month|quarter] x periods = amount
 *   1 x 8,800/mo x 12 = 105,600
 *   "One (1) Project Leader @ ₱8,800.00/mo. x 12 mos."
 *
 * A computation is present when quantity and rate are both filled; periods
 * defaults to 1 and the rate period is optional.
 */

/** "Paid every" options. One time is stored as no rate period (''). */
export const RATE_PERIODS = [
  { value: 'month', label: 'Month' },
  { value: 'quarter', label: 'Quarter' },
  { value: '', label: 'One time' },
];

/** Label of the periods field, following "Paid every". */
export function periodsLabel(ratePeriod) {
  if (ratePeriod === 'month') return 'For how many months';
  if (ratePeriod === 'quarter') return 'For how many quarters';
  return 'How many times';
}

const PERIOD_WORDS = {
  month: ['/mo.', 'mo.', 'mos.'],
  quarter: ['/qtr.', 'qtr.', 'qtrs.'],
};

const filled = (value) => value !== null && value !== undefined && String(value).trim() !== '';

export function hasComputation(row) {
  return filled(row?.quantity) && filled(row?.rate);
}

/** quantity x rate x periods (rounded to centavos), or null without a computation. */
export function computedAmount(row) {
  if (!hasComputation(row)) return null;
  const quantity = Math.trunc(Number(row.quantity));
  const rate = Number(row.rate);
  const periods = filled(row.periods) ? Math.trunc(Number(row.periods)) : 1;
  if (!Number.isFinite(quantity) || !Number.isFinite(rate) || !Number.isFinite(periods)) return null;
  return Math.round(quantity * rate * periods * 100) / 100;
}

const ONES = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
  'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

/** English words for a whole number (0 – 999,999,999). */
export function numberWords(n) {
  if (n < 20) return ONES[n];
  if (n < 100) return TENS[Math.floor(n / 10)] + (n % 10 ? `-${ONES[n % 10]}` : '');
  if (n < 1000) return `${ONES[Math.floor(n / 100)]} hundred${n % 100 ? ` ${numberWords(n % 100)}` : ''}`;
  for (const [size, name] of [[1000000, 'million'], [1000, 'thousand']]) {
    if (n >= size) return `${numberWords(Math.floor(n / size))} ${name}${n % size ? ` ${numberWords(n % size)}` : ''}`;
  }
  return String(n);
}

const money = (value) => Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** "One (1) Project Leader @ ₱8,800.00/mo. x 12 mos.", or null. */
export function describeComputation(row) {
  if (!hasComputation(row)) return null;
  const quantity = Math.trunc(Number(row.quantity));
  const unit = String(row.unit_description ?? '').trim();
  const words = PERIOD_WORDS[row.rate_period] ?? ['', '', ''];
  const word = numberWords(quantity);
  let text = `${word.charAt(0).toUpperCase()}${word.slice(1)} (${quantity})${unit ? ` ${unit}` : ''} @ ₱${money(row.rate)}${words[0]}`;
  if (filled(row.periods)) {
    const periods = Math.trunc(Number(row.periods));
    const unitWord = row.rate_period ? ` ${periods === 1 ? words[1] : words[2]}` : '';
    text += ` x ${periods}${unitWord}`;
  }
  return text;
}

/** "Project Leader" -> "Project Leaders", "Battery" -> "Batteries"; "Staff" stays. */
export function pluralize(word) {
  if (!word || /(s|staff|personnel|equipment|software|support)$/i.test(word)) return word;
  if (/(x|z|ch|sh)$/i.test(word)) return `${word}es`;
  if (/[^aeiou]y$/i.test(word)) return `${word.slice(0, -1)}ies`;
  return `${word}s`;
}

/**
 * The plain on-screen preview (not the Form B wording):
 *   "1 Project Leader x ₱8,800.00 per month x 12 months = ₱105,600.00"
 *   "2 Laptops x ₱40,000.00 = ₱80,000.00"
 */
export function previewComputation(row) {
  const amount = computedAmount(row);
  if (amount == null) return null;
  const quantity = Math.trunc(Number(row.quantity));
  const unit = String(row.unit_description ?? '').trim();
  const who = unit ? ` ${quantity === 1 ? unit : pluralize(unit)}` : '';
  let text = `${quantity}${who} x ₱${money(row.rate)}`;
  const periods = filled(row.periods) ? Math.trunc(Number(row.periods)) : null;
  if (row.rate_period === 'month' || row.rate_period === 'quarter') {
    const count = periods ?? 1;
    text += ` per ${row.rate_period} x ${count} ${row.rate_period}${count === 1 ? '' : 's'}`;
  } else if (periods != null && periods !== 1) {
    text += ` x ${periods} times`;
  }
  return `${text} = ₱${money(amount)}`;
}
