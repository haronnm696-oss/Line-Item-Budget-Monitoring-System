// Payroll register (GET /api/projects/:id/payroll-register): verified
// payroll batches only, one block per pay period, one row per person with
// the computation "(8,800 x 3 months) = 26,400.00", and period totals.
// Project honoraria have no deductions and no allowances (net = gross);
// batches verified before that rule stay as recorded, and only then a
// "Recorded deductions" column is shown. Shared by Reports (PDF preview)
// and the Research Assistant's Payroll page. Kept free of JSX so it can be
// tested; the CSV is built by the backend.

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

/** The register table for a printed PDF (escape: HTML escaper, money: peso formatter). */
export function registerTableHtml(register, escape, money) {
  const columns = registerColumns(register);
  const rows = registerRows(register);
  if (rows.length === 0) {
    return '<p>No verified payroll for this period. Draft, waiting and returned batches are not included.</p>';
  }
  const head = columns.map((c) => `<th${c.money ? ' class="r"' : ''}>${escape(c.label)}</th>`).join('');
  const body = rows.map((row) => {
    const style = row.kind === 'person' ? '' : ` style="font-weight:700;background:#${row.kind === 'grand' ? 'E4E0D7' : 'F7F6F2'}"`;
    const cells = columns.map((c) => {
      const value = registerCell(row, c);
      return c.money ? `<td class="r">${money(value)}</td>` : `<td>${escape(value)}</td>`;
    }).join('');
    return `<tr${style}>${cells}</tr>`;
  }).join('');
  return `<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
}

/** A standalone printable register (Research Assistant's Payroll page). */
export function registerDocumentHtml(register, { escape, money, generated }) {
  const project = register?.project ?? {};
  const title = `Payroll register · ${register?.period_label ?? ''}`;
  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${escape(title)}</title>
  <style>
    @page { size: A4 landscape; margin: 1.2cm; }
    body { font-family: 'Public Sans', system-ui, sans-serif; font-size: 10.5px; color: #221D1A; line-height: 1.4; }
    h1 { font-size: 16px; color: #5E1B25; margin: 0 0 4px; }
    .meta { font-size: 10px; color: #6E655E; margin-bottom: 12px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #F7F6F2; color: #6E655E; font-weight: 600; text-align: left; padding: 5px 6px; border: 1px solid #CFC9BC; }
    td { padding: 4px 6px; border: 1px solid #E4E0D7; }
    .r { text-align: right; }
    .footer { margin-top: 16px; font-size: 9px; color: #6E655E; }
  </style>
</head>
<body>
  <h1>${escape(title)}</h1>
  <div class="meta">${escape(project.title ?? '')} (${escape(project.code ?? '')}) · Generated ${escape(generated ?? '')}</div>
  ${registerTableHtml(register, escape, money)}
  <div class="footer">Verified payroll batches only. Monthly honorarium x months effective; project honoraria have no deductions and no allowances. Payroll is not part of LIB spent. Mindanao State University–Main Campus · LIB Monitoring System.</div>
</body>
</html>`;
}
