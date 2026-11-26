// Payroll register (GET /api/projects/:id/payroll-register): verified
// payroll batches only, one block per pay period, one row per person with
// the computation "(8,800 x 3 months) = 26,400.00", and period totals.
// Project honoraria have no deductions and no allowances (net = gross);
// batches verified before that rule stay as recorded, and only then a
// "Recorded deductions" column is shown. Shared by Reports (PDF preview)
// and the Research Assistant's Payroll page. Kept free of JSX so it can be
// tested; the CSV is built by the backend.

import { escapeHtml } from './printForm.js';
import {
  projectInfo, projectSignatories, reportAmount, reportDocumentHtml, reportGeneratedText, reportNoteHtml,
} from './reportTemplate.js';

/** Reports page period -> API ?period=. */
export const REGISTER_PERIOD = {
  'All dates': 'all',
  'Current year': 'year',
  'Last 90 days': '90d',
};

/**
 * Flat rows for a table: each person, then a "Period total" row per pay
 * period, then the grand total. `kind` is 'person' | 'month' | 'grand'.
 */
export function registerRows(register) {
  const months = Array.isArray(register?.months) ? register.months : [];
  const rows = [];
  months.forEach((month) => {
    (month.rows ?? []).forEach((row) => rows.push({ kind: 'person', month: month.month_label, ...row }));
    rows.push({ kind: 'month', month: month.month_label, name: 'Period total', position: '', computation: '', ...month.totals });
  });
  if (months.length > 0) rows.push({ kind: 'grand', month: 'Grand total', name: '', position: '', computation: '', ...register.totals });
  return rows;
}

/** Column definitions. "Recorded deductions" only when an older verified batch has some. */
export function registerColumns(register) {
  return [
    { key: 'month', label: 'Pay period' },
    { key: 'name', label: 'Name' },
    { key: 'position', label: 'Position' },
    { key: 'computation', label: 'Computation' },
    { key: 'gross', label: 'Gross pay', money: true },
    ...(register?.has_recorded_deductions ? [{ key: 'recorded_deductions', label: 'Recorded deductions', money: true }] : []),
    { key: 'net', label: 'Net pay', money: true },
  ];
}

/** A cell's raw value (number for money columns). */
export function registerCell(row, column) {
  const value = row[column.key];
  return column.money ? Number(value) || 0 : (value ?? '');
}

/**
 * The register table for a printed PDF, in the report template's table
 * style (escape: HTML escaper, money: amount formatter without the peso
 * sign; money column headers carry "(₱)"). Period totals are bold, the
 * grand total is the TOTAL row.
 */
export function registerTableHtml(register, escape, money = reportAmount) {
  const columns = registerColumns(register);
  const rows = registerRows(register);
  if (rows.length === 0) {
    return '<p class="rt-note">No verified payroll for this period. Draft, waiting and returned batches are not included.</p>';
  }
  const head = columns.map((c) => `<th${c.money ? ' class="r"' : ''}>${escape(c.money ? `${c.label} (₱)` : c.label)}</th>`).join('');
  const body = rows.map((row) => {
    const kind = { month: ' class="rt-subtotal"', grand: ' class="rt-total"' }[row.kind] ?? '';
    const cells = columns.map((c) => {
      const value = row.kind === 'grand' && c.key === 'month' ? 'TOTAL' : registerCell(row, c);
      return c.money ? `<td class="r">${money(value)}</td>` : `<td>${escape(value)}</td>`;
    }).join('');
    return `<tr${kind}>${cells}</tr>`;
  }).join('');
  return `<table class="rt-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
}

export const REGISTER_NOTE = 'Verified payroll batches only. Monthly honorarium x months effective; project honoraria have no deductions and no allowances.';

/**
 * The printed register in the official report layout (Reports page and the
 * Research Assistant's Payroll page). `project`: the project detail
 * (title, code, leader, agencies; falls back to the register's project);
 * `signatories`: the project detail's signatories; `preparedBy`: { name, role }.
 */
export function registerDocumentHtml(register, { project, signatories, preparedBy, generatedAt = new Date(), escape = escapeHtml, assetBase } = {}) {
  const periodLabel = register?.period_label ?? '';
  const info = project ?? register?.project ?? {};
  return reportDocumentHtml({
    title: 'Payroll Register',
    docTitle: `Payroll register · ${periodLabel}`,
    filters: `Period: ${periodLabel}`,
    info: projectInfo(info, reportGeneratedText(generatedAt)),
    body: registerTableHtml(register, escape, reportAmount) + reportNoteHtml(REGISTER_NOTE, escape),
    signatories: projectSignatories({ preparedBy, financeAdmin: signatories?.finance_admin, projectLeader: signatories?.project_leader }),
    landscape: true,
    generatedAt,
    ...(assetBase !== undefined ? { assetBase } : {}),
  }, escape);
}
