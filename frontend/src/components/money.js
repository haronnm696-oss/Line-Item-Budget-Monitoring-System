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

/** One decimal everywhere so 42.8% never drifts to 43%. */
export function formatPct(value) {
  return `${(Number(value) || 0).toFixed(1)}%`;
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
