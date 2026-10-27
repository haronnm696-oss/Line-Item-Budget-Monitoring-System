import client from './client';
import { ENDPOINTS } from './endpoints';
import { getWithFallback, mutateWithFallback } from './http';
import { buildMockDashboard } from '../data/dashboardMock';
import { expenses as mockExpenses } from '../data/mockData';

const USE_MOCK = import.meta.env.VITE_USE_MOCK === 'true';

/** Aggregate dashboard payload (project, KPIs, tranches, burn, approvals, compliance). */
export function fetchProgramDashboard(projectId) {
  return getWithFallback(ENDPOINTS.programLeaderDashboard(projectId), buildMockDashboard());
}

export function fetchTranches(projectId) {
  return getWithFallback(ENDPOINTS.projectTranches(projectId), buildMockDashboard().tranches);
}

export function fetchBurnRate(projectId) {
  return getWithFallback(ENDPOINTS.projectBurnRate(projectId), buildMockDashboard().burn_rate);
}

export function fetchCompliance(projectId) {
  return getWithFallback(ENDPOINTS.projectCompliance(projectId), buildMockDashboard().compliance);
}

export function fetchApprovals(projectId) {
  return getWithFallback(
    `${ENDPOINTS.approvals}?project_id=${encodeURIComponent(projectId)}&status=pending`,
    { approvals: buildMockDashboard().approvals }
  );
}

export function approveApproval(id, reviewNotes = '') {
  return mutateWithFallback(
    'patch',
    ENDPOINTS.approveApproval(id),
    { review_notes: reviewNotes },
    { approval: { id, status: 'approved' } }
  );
}

export function rejectApproval(id, reviewNotes = '') {
  return mutateWithFallback(
    'patch',
    ENDPOINTS.rejectApproval(id),
    { review_notes: reviewNotes },
    { approval: { id, status: 'rejected' } }
  );
}

/** Escape one CSV cell (mirrors ReportController::csvCell). */
function csvCell(value) {
  if (value === null || value === undefined) return '';
  const text = String(value);
  return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Build a locally generated CSV when mock mode is on or the report API is unavailable. */
function buildMockReport(type, compliance = 'all') {
  const summary = buildMockDashboard();
  const project = summary.project;
  const categories = [...summary.categories.ps, ...summary.categories.mooe];
  const missingIds = new Set(summary.compliance.missing_items.map((item) => item.id));
  const bucket = (items) => {
    const allocated = items.reduce((sum, item) => sum + Number(item.allocated || 0), 0);
    const spent = items.reduce((sum, item) => sum + Number(item.spent || 0), 0);
    return {
      allocated,
      spent,
      remaining: allocated - spent,
      utilization: allocated > 0 ? Math.round((spent / allocated) * 1000) / 10 : 0,
    };
  };
  const psBucket = bucket(summary.categories.ps);
  const mooeBucket = bucket(summary.categories.mooe);
  let rows;

  if (type === 'form-b') {
    rows = [
      ['MOST R&D Form B — Line-Item Budget Monitoring'],
      ['Project Code', project.code],
      ['Project Title', project.title],
      ['Implementing Agency', project.implementing_agency],
      ['Funding Agency', project.funding_agency],
      ['Project Leader', project.project_leader],
      ['Project Period', project.period],
      [],
      ['Category', 'Type', 'Line Item Code', 'Allocated', 'Spent', 'Remaining', 'Utilization %'],
      ...categories.map((category) => [
        category.name, category.type, category.code, category.allocated,
        category.spent, category.remaining, category.utilization_pct,
      ]),
      ['GRAND TOTAL', '', '', summary.kpis.total_budget, summary.kpis.total_spent, summary.kpis.remaining, summary.kpis.utilization_pct],
    ];
  } else if (type === 'form-c') {
    rows = [
      ['MOST R&D Form C — Financial and Fund-Release Report'],
      ['Project Code', project.code],
      ['Project Title', project.title],
      ['Project Leader', project.project_leader],
      ['Project Period', project.period],
      [],
      ['FUND RELEASES'],
      ['Tranche', 'Milestone', 'Amount', 'Status', 'Planned Release', 'Actual Release'],
      ...summary.tranches.items.map((tranche) => [
        tranche.sequence, tranche.name, tranche.amount, tranche.display_status,
        tranche.planned_release_date ?? '', tranche.released_at ?? '',
      ]),
      ['TOTAL', '', summary.tranches.summary.total_amount, `${summary.tranches.summary.released_amount} released`, '', ''],
      [],
      ['EXPENDITURE SUMMARY'],
      ['Bucket', 'Allocated', 'Spent', 'Remaining', 'Utilization %'],
      ['Personnel Services (PS)', psBucket.allocated, psBucket.spent, psBucket.remaining, psBucket.utilization],
      ['MOOE', mooeBucket.allocated, mooeBucket.spent, mooeBucket.remaining, mooeBucket.utilization],
      ['GRAND TOTAL', summary.kpis.total_budget, summary.kpis.total_spent, summary.kpis.remaining, summary.kpis.utilization_pct],
      [],
      ['RECEIPT COMPLIANCE'],
      ['Total expenses', summary.compliance.total_expenses],
      ['With digitized receipt', summary.compliance.with_receipt],
      ['Missing receipt', summary.compliance.missing_receipt],
      ['Compliance %', summary.compliance.compliance_pct],
    ];
  } else if (type === 'utilization') {
    rows = [
      ['Budget Utilization Report'],
      ['Project Code', project.code],
      ['Project Title', project.title],
      [],
      ['Category', 'Type', 'Allocated', 'Spent', 'Remaining', 'Utilization %'],
      ...categories.map((category) => [
        category.name, category.type, category.allocated,
        category.spent, category.remaining, category.utilization_pct,
      ]),
      ['GRAND TOTAL', '', summary.kpis.total_budget, summary.kpis.total_spent, summary.kpis.remaining, summary.kpis.utilization_pct],
    ];
  } else if (type === 'liquidation') {
    let items = mockExpenses;
    if (compliance === 'verified') {
      items = items.filter((expense) => expense.status === 'Verified');
    } else if (compliance === 'missing_receipts') {
      items = items.filter((expense) => missingIds.has(expense.id));
    }

    rows = [
      ['Liquidation / Expense Summary'],
      ['Project Code', project.code],
      ['Project Title', project.title],
      ['Compliance filter', compliance],
      [],
      ['Date', 'Reference', 'Description', 'Category', 'Amount', 'Status', 'Receipt Attached', 'Verified By'],
      ...items.map((expense) => [
        expense.date, expense.ref, expense.desc, expense.category, expense.amount,
        expense.status, missingIds.has(expense.id) ? 'No' : 'Yes', expense.verified_by ?? '',
      ]),
      ['TOTAL', '', '', '', items.reduce((sum, expense) => sum + Number(expense.amount || 0), 0), '', '', ''],
    ];
  } else {
    throw new Error(`Unknown report type: ${type}`);
  }

  const csv = `\uFEFF${rows.map((row) => row.map(csvCell).join(',')).join('\r\n')}`;
  const stamp = new Date().toISOString().slice(0, 10).replace(/-/g, '');

  return {
    blob: new Blob([csv], { type: 'text/csv;charset=utf-8' }),
    filename: `${project.code.toLowerCase()}-${type}-${stamp}.csv`,
  };
}

/** Download a generated CSV report. Returns the blob + server-provided filename. */
export async function downloadReport(projectId, type, filters = {}) {
  if (USE_MOCK) {
    return buildMockReport(type, filters.compliance);
  }

  try {
    const res = await client.get(ENDPOINTS.report(projectId, type), {
      params: filters,
      responseType: 'blob',
    });

    const disposition = res.headers?.['content-disposition'] || '';
    const match = /filename="?([^";]+)"?/i.exec(disposition);

    return {
      blob: res.data,
      filename: match?.[1] || `${type}.csv`,
    };
  } catch (error) {
    if ([404, 501].includes(error.response?.status)) {
      return buildMockReport(type, filters.compliance);
    }
    throw error;
  }
}

/** Trigger a browser download for an already-fetched blob. */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
