import { formatPct, peso } from '../components/money.js';

/**
 * Rows of the Overview "Projects" card: one per project, largest approved
 * budget first. Equal budgets fall back to the title so the order is stable.
 */
export function projectCardRows(projects) {
  const list = Array.isArray(projects) ? projects : [];
  return [...list].sort((a, b) => (
    (Number(b?.budget) || 0) - (Number(a?.budget) || 0)
    || String(a?.title ?? '').localeCompare(String(b?.title ?? ''))
  ));
}

/** "5 projects · ₱X allocated · ₱Y spent (Z%) · ₱R remaining" (Projects page). */
export function projectsSummaryLine(summary) {
  if (!summary) return '';
  const count = Number(summary.projects) || 0;
  return [
    `${count} project${count === 1 ? '' : 's'}`,
    `${peso(summary.allocated ?? 0)} allocated`,
    `${peso(summary.spent ?? 0)} spent (${formatPct(summary.spent_pct)})`,
    `${peso(summary.remaining ?? 0)} remaining`,
  ].join(' · ');
}
