/**
 * Rows of the Reports page (Expense Ledger, Monthly summary) and the printed
 * Expense Ledger's verified-only rule. Kept free of JSX so it can be tested.
 */
import { reportPeso } from './reportTemplate.js';

export const REPORT_PERIODS = ['All dates', 'Current year', 'Last 90 days'];
export const REPORT_CATEGORIES = ['PS and MOOE', 'PS only', 'MOOE only'];

/**
 * PS or MOOE from the row's line item type (Form B). Only a row without a
 * line item falls back to its name. "Professional Services" is MOOE.
 */
export function isPsRow(row) {
  if (row.type) return row.type === 'PS';
  return /honoraria|salaries/i.test(row.category || '');
}

/** Rows within the period and category, newest first. */
export function filterReportRows(rows, { period, category }, today = new Date()) {
  let result = [...(rows ?? [])];
  if (period === 'Current year') {
    const yearStart = `${today.getFullYear()}-01-01`;
    result = result.filter((e) => e.date >= yearStart);
  } else if (period === 'Last 90 days') {
    const cutoff = new Date(today.getTime() - 90 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10);
    result = result.filter((e) => e.date >= cutoff);
  }
  if (category === 'PS only') result = result.filter((e) => isPsRow(e));
  else if (category === 'MOOE only') result = result.filter((e) => !isPsRow(e));
  return result.sort((a, b) => new Date(b.date) - new Date(a.date));
}

/** Verified expenses and verified payroll only: what the ledger and "spent" count. */
export function verifiedRows(rows) {
  return (rows ?? []).filter((row) => row.status === 'Verified');
}

/**
 * The printed Expense Ledger's note about records still waiting for
 * verification (`pending`: the project's ledger_pending entries, already
 * filtered like the ledger). Empty when there are none.
 */
export function ledgerPendingNote(pending) {
  const list = pending ?? [];
  if (list.length === 0) return '';
  const sum = list.reduce((total, row) => total + (Number(row.amount) || 0), 0);
  const one = list.length === 1;
  return `Only verified expenses and verified payroll are included. ${list.length} ${one ? 'record' : 'records'} still pending verification (${reportPeso(sum)}) ${one ? 'is' : 'are'} not counted.`;
}
