/**
 * The program-wide printed reports (Executive report, Audit log) in the
 * official report layout (reportTemplate.js): "Prepared by" the signed-in
 * user, "Approved by" the Program Leader. Kept free of JSX so it can be tested.
 */
import { formatPct } from '../components/money.js';
import { escapeHtml } from './printForm.js';
import { programSignatories, reportAmount, reportDocumentHtml, reportGeneratedText, reportTableHtml } from './reportTemplate.js';

export const IMPLEMENTING_AGENCY = 'Mindanao State University – Main Campus';

/**
 * Executive report (/program-reports/executive): the Overview payload as is
 * (the same KPI fields and fallbacks as the Overview), all projects sorted
 * by budget, the TOTAL row from the portfolio KPIs.
 */
export function executiveReportHtml({ payload, funderLabel, preparedBy, programLeader, generatedAt = new Date(), assetBase }, e = escapeHtml) {
  const kpis = payload?.kpis ?? {};
  const projects = Array.isArray(payload?.projects) ? payload.projects : [];
  const rows = [...projects].sort((a, b) => (Number(b.budget) || 0) - (Number(a.budget) || 0));
  const budget = Number(kpis.portfolio_budget) || 0;
  const spent = Number(kpis.total_spent ?? kpis.total_disbursed) || 0;
  const remaining = kpis.remaining != null ? Number(kpis.remaining) : budget - spent;
  const pending = Number(kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0) || 0;
  const health = kpis.health ?? {};

  const summary = reportTableHtml({
    columns: [{ label: 'Item' }, { label: 'Value' }],
    rows: [
      ['Total budget (₱)', budget],
      [`Total spent (₱), ${formatPct(kpis.utilization_pct ?? 0)} utilized`, spent],
      ['Remaining balance (₱)', remaining],
      ['Pending approvals', String(pending)],
      ['Project health', `${health.healthy ?? 0} healthy · ${health.watch ?? 0} watch · ${health.at_risk ?? 0} at risk · ${health.closed ?? 0} closed`],
      ['Average receipt compliance', kpis.avg_receipt_compliance != null ? formatPct(kpis.avg_receipt_compliance) : '—'],
    ].map(([label, value]) => ({ cells: [label, { text: typeof value === 'number' ? reportAmount(value) : value, right: true }] })),
  }, e);

  const table = reportTableHtml({
    columns: [
      { label: 'Project' }, { label: 'Code' }, { label: 'Funder' }, { label: 'Project Leader' },
      { label: 'Approved Budget', amount: true }, { label: 'Spent', amount: true },
      { label: 'Remaining', amount: true }, { label: 'Utilization (%)' }, { label: 'Health' },
    ],
    rows: [
      ...rows.map((row) => ({
        cells: [row.title, row.code, row.funder || '—', row.project_leader || '—',
          row.budget ?? 0, row.spent ?? 0, row.remaining ?? 0, formatPct(row.spent_pct ?? 0), row.health ?? '—'],
      })),
      ...(rows.length ? [{ kind: 'total', cells: [{ span: 4, text: 'TOTAL' }, null, null, null, budget, spent, remaining, formatPct(kpis.utilization_pct ?? 0), ''] }] : []),
    ],
    empty: 'No projects match this funding source.',
  }, e);

  return reportDocumentHtml({
    title: 'Executive Report',
    filters: `Funding source: ${funderLabel}`,
    info: [
      ['Coverage', 'All projects'],
      ['Funding Agency', funderLabel],
      ['Implementing Agency', IMPLEMENTING_AGENCY],
      ['Date Generated', reportGeneratedText(generatedAt)],
    ],
    body: `<div class="rt-section-title">Summary</div>${summary}<div class="rt-section-title">All projects</div>${table}`,
    signatories: programSignatories({ preparedBy, programLeader }),
    generatedAt,
    ...(assetBase !== undefined ? { assetBase } : {}),
  }, e);
}

/** Audit log print: the filtered entries the page shows. */
export function auditReportHtml({ entries, actionLabel, filters, preparedBy, programLeader, generatedAt = new Date(), assetBase }, e = escapeHtml) {
  const rows = (entries ?? []).map((a) => ({
    cells: [a.ts, a.user, actionLabel(a.action), a.record, a.recordType, a.change, a.ip],
  }));
  const table = reportTableHtml({
    columns: [
      { label: 'Timestamp' }, { label: 'User' }, { label: 'Action' }, { label: 'Record' },
      { label: 'Record Type' }, { label: 'Change' }, { label: 'IP Address' },
    ],
    rows,
    empty: 'No audit log entries match these filters.',
  }, e);

  return reportDocumentHtml({
    title: 'Audit Log',
    filters,
    info: [
      ['Coverage', 'All projects you are assigned to'],
      ['Total Entries', String(rows.length)],
      ['Implementing Agency', IMPLEMENTING_AGENCY],
      ['Date Generated', reportGeneratedText(generatedAt)],
    ],
    body: table,
    signatories: programSignatories({ preparedBy, programLeader }),
    generatedAt,
    ...(assetBase !== undefined ? { assetBase } : {}),
  }, e);
}
