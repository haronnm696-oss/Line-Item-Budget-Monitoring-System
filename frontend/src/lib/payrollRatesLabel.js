// PAYROLL_RATES_LABEL (backend env, e.g. "Demo deduction rates, not
// official"): a warning shown on every Payroll page and printed at the top
// of the Payroll register (PDF here; the backend writes it into the CSV).
// Empty or missing = nothing shown.

/** The label to show, or null when it is not set. */
export function ratesLabel(value) {
  const text = typeof value === 'string' ? value.trim() : '';
  return text || null;
}

/** The register PDF warning block (empty string when not set). `escape` escapes HTML. */
export function ratesLabelHtml(label, escape) {
  const text = ratesLabel(label);
  if (!text) return '';
  return `<div class="rates-label" role="note">⚠ ${escape(text)}</div>`;
}
