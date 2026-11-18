export function peso(amount) {
  return '₱' + Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Short peso label for chart axes and compact cards. */
export function compactPeso(amount) {
  const n = Number(amount) || 0;
  const abs = Math.abs(n);
  if (abs >= 1_000_000) return `₱${(n / 1_000_000).toFixed(1)}M`;
  if (abs >= 1_000) return `₱${Math.round(n / 1_000)}k`;
  return peso(n);
}

/**
 * One decimal everywhere so 42.8% never drifts to 43%. A share above zero
 * that would round to 0.0% shows "<0.1%", and one just under 100% that
 * would round to 100.0% shows "99.9%+", so a small amount never reads as
 * nothing (or everything).
 */
export function formatPct(value) {
  const n = Number(value) || 0;
  if (n > 0 && n < 0.05) return '<0.1%';
  if (n >= 99.95 && n < 100) return '99.9%+';
  return `${n.toFixed(1)}%`;
}

/**
 * A percent for a CSV cell: always a plain number (no "<0.1", no "%") so
 * spreadsheets can sum and sort. One decimal like the screen, except when
 * that would hide a real share (0.0 or 100.0): then up to 6 decimals, e.g.
 * 0.04. "<0.1%" / "99.9%+" are for screens and PDFs only (formatPct).
 */
export function csvPct(value) {
  const n = Number(value) || 0;
  const rounded = Math.round(n * 10) / 10;
  const hidden = (rounded === 0 && n !== 0) || (rounded === 100 && n !== 100);
  const text = (hidden ? n.toFixed(6) : rounded.toFixed(1)).replace(/\.?0+$/, '');
  return text === '-0' || text === '' ? '0' : text;
}

/** Signed percentage, e.g. -38.8% or +4.2%. */
export function signedPct(value) {
  const n = Number(value) || 0;
  return `${n > 0 ? '+' : ''}${n.toFixed(1)}%`;
}

/**
 * Spending below half of a line item is under-spending (a delivery risk),
 * not a win. Zero is deliberately a warning too.
 */
export function utilizationTone(value) {
  const n = Number(value) || 0;
  if (n > 100) return 'over';
  if (n < 50) return 'warn';
  return 'ok';
}

/** Time-vs-budget variance; large negative gaps are risk, not success. */
export function varianceTone(value, threshold = 5) {
  const n = Number(value) || 0;
  if (n > threshold) return 'over';
  if (n < -threshold) return 'warn';
  return 'ok';
}
