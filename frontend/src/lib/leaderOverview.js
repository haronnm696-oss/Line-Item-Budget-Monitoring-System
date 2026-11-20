// Project Leader Overview wording that depends on the project's setup.

export const NO_LINE_ITEMS_TEXT = "The Program Leader hasn't set up the line-item budget yet.";

const BURN_PILL = {
  ok: ['healthy', '✓'],
  warn: ['watch', '⚠'],
  over: ['risk', '✕'],
  neutral: ['neutral', '•'],
};

/**
 * The "Spending against time" alert. With no line items there is no budget
 * to spend against, so it gives no verdict (no Under-spending / At risk):
 * { setUp: false, message }. Otherwise the burn rate with its pill:
 * { setUp: true, label, tone, icon, spentPct, elapsedPct, message }.
 * `line_item_count` missing (older payload) counts as set up.
 */
export function spendingAlert(alerts) {
  if (Number(alerts?.line_item_count) === 0) {
    return { setUp: false, message: NO_LINE_ITEMS_TEXT };
  }
  const burn = alerts?.burn_rate ?? {};
  const [tone, icon] = BURN_PILL[burn.tone] ?? BURN_PILL.neutral;
  return {
    setUp: true,
    label: burn.status_label,
    tone,
    icon,
    spentPct: Number(burn.spent_pct) || 0,
    elapsedPct: Number(burn.elapsed_pct) || 0,
    message: burn.message,
  };
}
