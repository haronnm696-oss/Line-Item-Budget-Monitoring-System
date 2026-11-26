import { useEffect, useRef, useState } from 'react';
import { csvPct, formatPct } from '../components/money';
import { REGISTER_PERIOD, registerDocumentHtml } from '../lib/payrollRegister';
import { printHtmlDocument, saveBlob } from '../lib/download';
import { downloadPayrollRegisterCsv, downloadReportCsv, fetchFormB, fetchPayrollRegister } from '../api/reports';
import { formBBlocker, formBHtml, formBTitle } from '../lib/formBPrint';
import client from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import DownloadMenu from '../components/DownloadMenu';
import { useAuth } from '../context/AuthContext';
import { normalizeRole, ROLE_MAP } from '../lib/roles';
import { filterReportRows, isPsRow, ledgerPendingNote, REPORT_CATEGORIES, REPORT_PERIODS, verifiedRows } from '../lib/reportRows';
import {
  projectInfo, projectSignatories, reportDocumentHtml, reportGeneratedText, reportNoteHtml, reportTableHtml,
} from '../lib/reportTemplate';
import { canSeePayrollRegister, reportDownloadOptions, reportTypesFor, showReportProjectField } from '../lib/reportDownloads';
import { isReadOnlyRole } from '../lib/pageHead';
import PageTitle from '../components/PageTitle';
import { NO_ACTIVE_PROJECT_TEXT } from '../lib/leaderRule';
import { myProjects, notAssignedText, pickProject, projectRoleFor } from '../lib/myProjects';
import FundingWarnings from '../components/program/FundingWarnings';

const REPORT_TYPES = [
  {
    key: 'form-b',
    icon: '▤',
    title: 'Form B (Project Line-Item Budget)',
    desc: 'Official funder layout',
  },
  {
    key: 'utilization',
    icon: '▦',
    title: 'Budget utilization report',
    desc: 'Allocated, spent, remaining per line item',
  },
  {
    key: 'ledger',
    icon: '₱',
    title: 'Expense ledger',
    desc: 'Expenses and payroll by date',
  },
  {
    key: 'payroll-register',
    icon: '▥',
    title: 'Payroll register',
    desc: 'Verified payroll per person',
  },
  {
    key: 'monthly-summary',
    icon: '▨',
    title: 'Monthly financial summary',
    desc: 'One-month snapshot',
  },
];

const PERIODS = REPORT_PERIODS;
const CATEGORIES = REPORT_CATEGORIES;

function toCSV(rows, headers) {
  const headerRow = headers.join(',');
  const dataRows = rows.map((r) => headers.map((h) => {
    let v = r[h];
    if (v === null || v === undefined) v = '';
    if (typeof v === 'string' && (v.includes(',') || v.includes('"') || v.includes('\n'))) {
      v = '"' + v.replace(/"/g, '""') + '"';
    }
    return v;
  }).join(','));
  return [headerRow, ...dataRows].join('\n');
}

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

// Every value interpolated into the print document goes through this, since
// the document is rendered as a real page (titles, descriptions and names
// come from user input).
function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function downloadCSV(content, filename) {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.setAttribute('href', url);
  a.setAttribute('download', filename);
  a.style.visibility = 'hidden';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

export default function Reports({ programMode = false, initialCode = '' }) {
  const { user } = useAuth();
  const roleCode = programMode ? 'PRG' : normalizeRole(user);
  // The Project Leader works on the project from ?project= (no Project parameter).
  const isLeader = roleCode === 'PL';
  // Researchers: no Payroll register (pay per person).
  const reportTypes = reportTypesFor(roleCode, REPORT_TYPES);
  const seesRegister = canSeePayrollRegister(roleCode);
  // The one "my projects" rule for this role (the Program Leader: all projects).
  const myRole = programMode ? null : projectRoleFor(roleCode);
  const [chosenCode, setChosenCode] = useState('');
  const [selectedPeriod, setSelectedPeriod] = useState(PERIODS[0]);
  const [selectedCategory, setSelectedCategory] = useState(CATEGORIES[0]);
  const [toast, setToast] = useState(null); // { msg, type }

  // ---------- Project list (real data only, never sample projects) ----------
  // Each result is tagged with the attempt it belongs to, so "loading" is
  // simply "no result for the current attempt yet".
  const [projectsAttempt, setProjectsAttempt] = useState(0);
  const [projectsResult, setProjectsResult] = useState(null); // { attempt, list } | { attempt, error }

  useEffect(() => {
    let alive = true;
    client.get(ENDPOINTS.projects)
      .then((response) => {
        if (alive) setProjectsResult({ attempt: projectsAttempt, list: myProjects(response.data, myRole) });
      })
      .catch((error) => {
        if (alive) setProjectsResult({ attempt: projectsAttempt, error: messageFor(error, 'Could not load projects') });
      });
    return () => { alive = false; };
  }, [projectsAttempt, myRole]);

  const projectsStatus = projectsResult?.attempt !== projectsAttempt
    ? 'loading'
    : projectsResult.error ? 'error' : projectsResult.list.length ? 'ready' : 'empty';
  // ?project=<id> (links from the role Overviews) picks the project too.
  const requestedId = new URLSearchParams(window.location.search).get('project');
  // The Project Leader: their one active project (lib/leaderRule.js).
  const leaderPick = isLeader && projectsStatus === 'ready' ? pickProject(projectsResult.list, requestedId, myRole) : null;
  const projectList = leaderPick ? leaderPick.projects : projectsStatus === 'ready' ? projectsResult.list : [];
  const listRow = leaderPick ? leaderPick.project : projectList.find((p) => p.code === chosenCode)
    ?? projectList.find((p) => p.code === initialCode)
    ?? projectList.find((p) => requestedId && String(p.id) === requestedId)
    ?? projectList[0]
    ?? null;
  const selectedCode = listRow?.code ?? '';
  // One project (Researcher, Finance/Admin) or the Project Leader: no Project
  // parameter; the subtitle names the project instead.
  const showProjectField = showReportProjectField(roleCode, projectsStatus, projectList.length);

  // ---------- Selected project's financial detail ----------
  const [detailAttempt, setDetailAttempt] = useState(0);
  const [detailResult, setDetailResult] = useState(null); // { key, data } | { key, error }
  const detailKey = listRow ? `${listRow.id}:${detailAttempt}` : '';

  useEffect(() => {
    if (!detailKey) return undefined;
    const projectId = detailKey.split(':')[0];
    let alive = true;
    client.get(ENDPOINTS.project(projectId))
      .then((response) => { if (alive) setDetailResult({ key: detailKey, data: response.data }); })
      .catch(() => {
        if (alive) setDetailResult({ key: detailKey, error: "Could not load this project's report data." });
      });
    return () => { alive = false; };
  }, [detailKey]);

  const detailStatus = !detailKey
    ? 'idle'
    : detailResult?.key !== detailKey ? 'loading' : detailResult.error ? 'error' : 'ready';
  const detail = detailStatus === 'ready' ? detailResult.data : null;
  const project = detail?.project ?? null;
  const reportsReady = detailStatus === 'ready' && project !== null;
  const selectedProject = project ?? {};
  const psCategories = Array.isArray(detail?.categories?.ps) ? detail.categories.ps : [];
  const mooeCategories = Array.isArray(detail?.categories?.mooe) ? detail.categories.mooe : [];
  const expenseRows = Array.isArray(detail?.expenses) ? detail.expenses : [];
  // ---------- Payroll register (server: verified batches only) ----------
  const registerParams = { period: REGISTER_PERIOD[selectedPeriod] ?? 'all' };
  const registerKey = listRow && seesRegister ? `${listRow.id}:${registerParams.period}:${detailAttempt}` : '';
  const [registerResult, setRegisterResult] = useState(null); // { key, data } | { key, error }

  useEffect(() => {
    if (!registerKey) return undefined;
    const [projectId, period] = registerKey.split(':');
    let alive = true;
    fetchPayrollRegister(projectId, { period })
      .then((data) => { if (alive) setRegisterResult({ key: registerKey, data }); })
      .catch(() => { if (alive) setRegisterResult({ key: registerKey, error: 'Could not load the payroll register.' }); });
    return () => { alive = false; };
  }, [registerKey]);

  const registerStatus = !registerKey ? 'idle'
    : registerResult?.key !== registerKey ? 'loading' : registerResult.error ? 'error' : 'ready';
  const register = registerStatus === 'ready' ? registerResult.data : null;

  function retryProjects() {
    setProjectsAttempt((value) => value + 1);
  }

  function retryDetail() {
    setDetailAttempt((value) => value + 1);
  }

  // No funder or no line items: Form B is not exported (the server refuses too).
  const formBReason = reportsReady ? formBBlocker(project) : null;

  function reportAvailable(key) {
    if (key === 'form-b' && formBReason) return false;
    return reportsReady && (key !== 'payroll-register' || register !== null);
  }

  const formBNote = formBReason
    ? <p className="pu-hint" id="form-b-blocked" role="status"><span aria-hidden="true">⚠ </span>{formBReason}</p>
    : null;

  // ---------- Filtering ----------
  const expensesForPreview = filterReportRows(expenseRows, { period: selectedPeriod, category: selectedCategory });

  // ---------- Report data builders ----------
  function buildUtilizationRows() {
    const rows = [];
    let psAlloc = 0, psSpent = 0, mooeAlloc = 0, mooeSpent = 0;

    const addGroup = (title, cats) => {
      let gAlloc = 0, gSpent = 0;
      cats.forEach((c) => {
        const remaining = c.allocated - c.spent;
        const util = c.allocated ? (c.spent / c.allocated) * 100 : 0;
        rows.push({
          group: title,
          item: c.name,
          allocated: c.allocated,
          spent: c.spent,
          remaining,
          util,
        });
        gAlloc += c.allocated; gSpent += c.spent;
      });
      const gUtil = gAlloc ? (gSpent / gAlloc) * 100 : 0;
      rows.push({
        group: title, item: `${title} Subtotal`, allocated: gAlloc, spent: gSpent,
        remaining: gAlloc - gSpent, util: gUtil, bold: true,
      });
      if (title.startsWith('I.')) { psAlloc = gAlloc; psSpent = gSpent; }
      else { mooeAlloc = gAlloc; mooeSpent = gSpent; }
    };

    addGroup('I. Personnel Services (PS)', psCategories);
    addGroup('II. Maintenance & Other Operating Expenses (MOOE)', mooeCategories);

    const totalAlloc = psAlloc + mooeAlloc;
    const totalSpent = psSpent + mooeSpent;
    rows.push({
      group: 'Total', item: 'Grand Total (PS + MOOE)', allocated: totalAlloc, spent: totalSpent,
      remaining: totalAlloc - totalSpent, util: totalAlloc ? (totalSpent / totalAlloc) * 100 : 0, bold: true,
    });

    return rows;
  }

  function buildLedgerRows() {
    return expensesForPreview.map((e) => ({
      date: e.date,
      ref: e.ref || '—',
      description: e.desc,
      category: e.category,
      amount: e.amount,
      status: e.status,
      recordedBy: e.recorded_by || '—',
      verifiedBy: e.verified_by || '—',
    }));
  }

  function buildMonthlySummaryRows() {
    const data = {};
    // Spent only (verified expenses and verified payroll), like every other screen.
    verifiedRows(expensesForPreview).forEach((e) => {
      const key = (e.date || '').slice(0, 7);
      if (!key) return;
      if (!data[key]) data[key] = { ps: 0, mooe: 0 };
      if (isPsRow(e)) data[key].ps += Number(e.amount) || 0;
      else data[key].mooe += Number(e.amount) || 0;
    });

    return Object.keys(data).sort().map((key) => ({
      month: new Date(`${key}-01T00:00:00`).toLocaleDateString('en-PH', {
        month: 'short',
        year: 'numeric',
      }),
      ps: data[key].ps,
      mooe: data[key].mooe,
      total: data[key].ps + data[key].mooe,
    }));
  }

  // ---------- Actions ----------
  function showToast(msg, type = 'info') {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3500);
  }

  // CSV columns and rows for one report type (the preview and the direct export).
  function csvFor(type) {
    if (type === 'utilization') {
      return {
        title: 'Budget Utilization Report',
        filename: 'budget-utilization.csv',
        headers: ['Category Type', 'Line Item', 'Allocated', 'Spent', 'Remaining', 'Utilization %'],
        rows: buildUtilizationRows().map((r) => ({
          'Category Type': r.group || '',
          'Line Item': r.item,
          'Allocated': r.allocated,
          'Spent': r.spent,
          'Remaining': r.remaining,
          // A plain number (spreadsheets sum and sort it), never "<0.1".
          'Utilization %': csvPct(r.util),
        })),
      };
    }
    if (type === 'ledger') {
      return {
        title: 'Expense Ledger',
        filename: 'expense-ledger.csv',
        headers: ['Date', 'Reference', 'Description', 'Category', 'Amount', 'Status', 'Recorded By', 'Verified By'],
        rows: buildLedgerRows().map((r) => ({
          Date: r.date, Reference: r.ref, Description: r.description, Category: r.category,
          Amount: r.amount, Status: r.status, 'Recorded By': r.recordedBy, 'Verified By': r.verifiedBy,
        })),
      };
    }
    return {
      title: 'Monthly Financial Summary',
      filename: 'monthly-financial-summary.csv',
      headers: ['Month', 'Personnel Services (PS)', 'Maintenance & Other Operating Expenses (MOOE)', 'Total'],
      rows: buildMonthlySummaryRows().map((r) => ({
        Month: r.month, 'Personnel Services (PS)': r.ps,
        'Maintenance & Other Operating Expenses (MOOE)': r.mooe, Total: r.total,
      })),
    };
  }

  // The payroll register CSV comes from the backend (same data and label).
  async function downloadRegisterCsv() {
    showToast('Exporting Payroll Register to CSV…', 'info');
    try {
      const { blob, filename } = await downloadPayrollRegisterCsv(listRow.id, registerParams);
      saveBlob(blob, filename);
    } catch {
      showToast('Could not export the payroll register. Please try again.', 'error');
    }
  }

  // R&D Form B: the backend document in the official layout, printed
  // straight away ("Save as PDF" in the print dialog).
  async function exportFormBPdf() {
    showToast('Preparing Form B…', 'info');
    try {
      const doc = await fetchFormB(listRow.id);
      printHtmlDocument(formBHtml(doc, escapeHtml), { title: formBTitle(doc) });
    } catch {
      showToast('Could not load Form B. Please try again.', 'error');
    }
  }

  async function downloadFormBCsv() {
    showToast('Exporting Form B to CSV…', 'info');
    try {
      const { blob, filename } = await downloadReportCsv(listRow.id, 'form-b');
      saveBlob(blob, filename);
    } catch {
      showToast('Could not export Form B. Please try again.', 'error');
    }
  }

  // PDF: the print dialog opens straight away ("Save as PDF" there).
  function downloadReportPdf(type) {
    if (!reportAvailable(type)) return;
    if (type === 'form-b') {
      exportFormBPdf();
      return;
    }
    const title = type === 'payroll-register' ? 'Payroll Register' : csvFor(type).title;
    printHtmlDocument(buildPrintHTML(type, title));
  }

  // ---------- Print-ready HTML (lib/reportTemplate.js: the official layout) ----------
  function buildPrintHTML(key, title) {
    const generatedAt = new Date();
    const preparedBy = { name: user?.name, role: ROLE_MAP[roleCode]?.label };

    if (key === 'payroll-register') {
      return registerDocumentHtml(register, {
        project: selectedProject, signatories: detail?.signatories, preparedBy, generatedAt, escape: escapeHtml,
      });
    }

    let body = '';
    if (key === 'utilization') {
      body = reportTableHtml({
        columns: [
          { label: 'Category Type' }, { label: 'Line Item' }, { label: 'Allocated', amount: true },
          { label: 'Spent', amount: true }, { label: 'Remaining', amount: true }, { label: 'Utilization (%)' },
        ],
        rows: buildUtilizationRows().map((r) => (r.group === 'Total'
          ? { kind: 'total', cells: [{ span: 2, text: 'TOTAL (PS + MOOE)' }, null, r.allocated, r.spent, r.remaining, formatPct(r.util)] }
          : { kind: r.bold ? 'subtotal' : undefined, cells: [r.group, r.item, r.allocated, r.spent, r.remaining, formatPct(r.util)] })),
      }, escapeHtml);
    } else if (key === 'ledger') {
      // Verified expenses and verified payroll only; the note counts the rest.
      const rows = verifiedRows(expensesForPreview);
      const total = rows.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
      const pending = filterReportRows(detail?.ledger_pending, { period: selectedPeriod, category: selectedCategory });
      body = reportTableHtml({
        columns: [
          { label: 'Date' }, { label: 'Reference No.' }, { label: 'Particulars' }, { label: 'Line Item' },
          { label: 'Recorded By' }, { label: 'Verified By' }, { label: 'Amount', amount: true },
        ],
        rows: [
          ...rows.map((r) => ({ cells: [r.date, r.ref || '—', r.desc, r.category, r.recorded_by || '—', r.verified_by || '—', r.amount] })),
          ...(rows.length ? [{ kind: 'total', cells: [{ span: 6, text: 'TOTAL' }, null, null, null, null, null, total] }] : []),
        ],
        empty: 'No verified expenses or payroll for these filters.',
      }, escapeHtml) + reportNoteHtml(ledgerPendingNote(pending), escapeHtml);
    } else {
      const rows = buildMonthlySummaryRows();
      const sum = (field) => rows.reduce((total, r) => total + r[field], 0);
      body = reportTableHtml({
        columns: [
          { label: 'Month' }, { label: 'Personnel Services (PS)', amount: true },
          { label: 'Maintenance & Other Operating Expenses (MOOE)', amount: true }, { label: 'Total', amount: true },
        ],
        rows: [
          ...rows.map((r) => ({ cells: [r.month, r.ps, r.mooe, r.total] })),
          ...(rows.length ? [{ kind: 'total', cells: ['TOTAL', sum('ps'), sum('mooe'), sum('total')] }] : []),
        ],
        empty: 'No verified expenses or payroll for these filters.',
      }, escapeHtml);
    }

    return reportDocumentHtml({
      title,
      docTitle: `${title} · ${selectedProject.code ?? ''}`,
      filters: `Period: ${selectedPeriod} · Category: ${selectedCategory}`,
      info: projectInfo(selectedProject, reportGeneratedText(generatedAt)),
      body,
      signatories: projectSignatories({
        preparedBy,
        financeAdmin: detail?.signatories?.finance_admin,
        projectLeader: detail?.signatories?.project_leader,
      }),
      generatedAt,
    }, escapeHtml);
  }

  // Direct CSV export for one report type (same rows as the preview).
  function exportReportCsv(r) {
    if (!reportAvailable(r.key)) return;
    if (r.key === 'payroll-register') {
      downloadRegisterCsv();
      return;
    }
    if (r.key === 'form-b') {
      downloadFormBCsv();
      return;
    }
    const csv = csvFor(r.key);
    showToast(`Exporting ${r.title} to CSV…`, 'info');
    downloadCSV(toCSV(csv.rows, csv.headers), csv.filename);
  }

  // Project / Period / Category selects, shared by both layouts. The Project
  // select only when there is a project to choose (showReportProjectField).
  function renderParamFields(program) {
    const fieldProps = program ? { className: 'program-field' } : { className: 'field', style: { margin: 0 } };
    const selectClass = program ? 'program-select' : undefined;
    const placeholder = {
      loading: 'Loading projects…',
      error: 'Could not load projects',
      empty: myRole ? notAssignedText(myRole) : 'No projects yet',
    }[projectsStatus];
    return (
      <>
        {showProjectField && <div {...fieldProps}>
          <label htmlFor="report-param-project">Project</label>
          <div className="rp-project">
            <select
              id="report-param-project"
              className={selectClass}
              value={selectedCode}
              onChange={(e) => setChosenCode(e.target.value)}
              disabled={projectsStatus !== 'ready'}
              aria-busy={projectsStatus === 'loading'}
              aria-invalid={projectsStatus === 'error' || undefined}
            >
              {projectsStatus === 'ready'
                ? projectList.map((p) => (
                  <option key={p.id} value={p.code}>{p.title} ({p.code})</option>
                ))
                : <option value="">{placeholder}</option>}
            </select>
            {projectsStatus === 'error' && (
              <button type="button" className={program ? 'program-btn' : 'btn'} onClick={retryProjects}>
                Try again
              </button>
            )}
          </div>
        </div>}
        <div {...fieldProps}>
          <label htmlFor="report-param-period">Period</label>
          <select id="report-param-period" className={selectClass} value={selectedPeriod} onChange={(e) => setSelectedPeriod(e.target.value)}>
            {PERIODS.map((p) => <option key={p} value={p}>{p}</option>)}
          </select>
        </div>
        <div {...fieldProps}>
          <label htmlFor="report-param-category">Category</label>
          <select id="report-param-category" className={selectClass} value={selectedCategory} onChange={(e) => setSelectedCategory(e.target.value)}>
            {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
      </>
    );
  }

  // One "Download" button per report: PDF or CSV (Form B: "Form B (PDF)").
  function downloadMenu(r, buttonClassName, align) {
    const actions = { pdf: () => downloadReportPdf(r.key), csv: () => exportReportCsv(r) };
    return (
      <DownloadMenu
        label={r.title}
        buttonClassName={buttonClassName}
        align={align}
        disabled={!reportAvailable(r.key)}
        describedBy={r.key === 'form-b' && formBReason ? 'form-b-blocked' : undefined}
        options={reportDownloadOptions(r.key).map((option) => ({ ...option, onSelect: actions[option.key] }))}
      />
    );
  }

  // Shown under the parameters while the selected project's data failed.
  const detailError = detailStatus === 'error' && (
    <div className="rp-error rp-error-block" role="alert">
      <span>{detailResult?.error || "Could not load this project's report data."}</span>
      <button type="button" className={programMode ? 'program-btn' : 'btn sm'} onClick={retryDetail}>Try again</button>
    </div>
  );
  // Payroll register state under its card (verified batches only).
  const payrollNote = registerStatus === 'error'
    ? (
      <p className="rp-note" role="alert">
        {registerResult?.error}{' '}
        <button type="button" className={programMode ? 'program-btn sm' : 'btn sm'} onClick={retryDetail}>Try again</button>
      </p>
    )
    : registerStatus === 'loading' ? <p className="rp-note">Loading payroll…</p>
      : register && register.months.length === 0
        ? <p className="rp-note">No verified payroll for this period.</p>
        : register ? <p className="rp-note">{register.months.length} verified {register.months.length === 1 ? 'pay period' : 'pay periods'}</p>
          : null;

  // ?report=<key>&format=pdf|csv (the PDF / CSV buttons on the role
  // Overviews): run that export once the project's data is loaded, with the
  // same code as the buttons below.
  const autoExport = useRef(false);
  useEffect(() => {
    if (autoExport.current || !reportsReady) return;
    const params = new URLSearchParams(window.location.search);
    const report = reportTypes.find((r) => r.key === params.get('report'));
    const format = params.get('format');
    if (!report || !['pdf', 'csv'].includes(format)) return;
    // The payroll register loads separately: wait for it.
    if (report.key === 'payroll-register' && registerStatus === 'loading') return;
    autoExport.current = true;
    if (!reportAvailable(report.key)) return;
    if (format === 'pdf') downloadReportPdf(report.key);
    else exportReportCsv(report);
  });

  return (
    <>
      {!programMode && (
        <div className="pl-head">
          <div>
            <PageTitle title="Reports" readOnly={isReadOnlyRole(roleCode)} />
            {!showProjectField && listRow && (
              <p className="program-sub">{`${listRow.title} · ${listRow.code}`}</p>
            )}
          </div>
        </div>
      )}

      {/* Toast */}
      {toast && (
        <div className="banner" style={{ position: 'fixed', top: 90, right: 24, zIndex: 60, minWidth: 260, boxShadow: '0 2px 8px rgba(0,0,0,.15)' }}>
          {toast.msg}
        </div>
      )}

      {programMode ? (
        <div className="prr">
          <div className="program-card-head">
            <div>
              <div className="program-card-title">Per-project reports</div>
            </div>
          </div>

          <div className="prr-params" role="group" aria-label="Report parameters">
            {renderParamFields(true)}
          </div>
          {detailError}

          <ul className="prr-list" aria-label="Reports for this project">
            {reportTypes.map((r) => (
              <li className="prr-row" key={r.key}>
                <span className="prr-ic" aria-hidden="true">{r.icon}</span>
                <div className="prr-text">
                  <b>{r.title}</b>
                  <p>{r.desc}</p>
                  {r.key === 'payroll-register' && reportsReady && payrollNote}
                  {r.key === 'form-b' && formBNote}
                </div>
                <div className="prr-actions">{downloadMenu(r, `program-btn${r.key === 'form-b' ? ' primary' : ''}`, 'end')}</div>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <>
          {/* Without the Project select, the project list's state shows here. */}
          {!showProjectField && projectsStatus === 'error' && (
            <div className="rp-error rp-error-block" role="alert">
              <span>{projectsResult?.error || 'Could not load projects'}</span>
              <button type="button" className="btn sm" onClick={retryProjects}>Try again</button>
            </div>
          )}
          {!showProjectField && projectsStatus === 'empty' && <div className="banner" role="status">{notAssignedText(myRole)}</div>}
          {leaderPick && !leaderPick.project && (
            <div className="banner" role="status">{leaderPick.past.length ? NO_ACTIVE_PROJECT_TEXT : notAssignedText(myRole)}</div>
          )}

          {/* Report parameters first: every report below uses them. */}
          <div className="panel" style={{ marginBottom: 16 }}>
            <div className="panel-head"><h3>Report parameters</h3></div>
            <div style={{ padding: 16 }} className={`grid ${showProjectField ? 'c3' : 'c2'}`}>
              {renderParamFields(false)}
            </div>
          </div>
          {detailError}

          {/* Report cards */}
          <div className="report-opts" style={{ marginBottom: 20 }}>
            {reportTypes.map((r) => (
              <div className="report-card" key={r.key}>
                <div className="rc-ic">{r.icon}</div>
                <div style={{ flex: 1 }}>
                  <b>{r.title}</b>
                  <p>{r.desc}</p>
                  {r.key === 'payroll-register' && reportsReady && payrollNote}
                  {r.key === 'form-b' && formBNote}
                  <div className="report-card-actions">{downloadMenu(r, 'btn', 'start')}</div>
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {/* Typed project amounts not yet assigned to line items (Form B). The
          Project Leader cannot fix that, so they see who can instead. */}
      {isLeader
        ? (detail?.form_b?.warnings?.length > 0 && (
          <div className="banner warn" role="status"><span aria-hidden="true">⚠ </span>The Program Leader hasn&apos;t finished the budget setup.</div>
        ))
        : (
          <FundingWarnings
            warnings={detail?.form_b?.warnings}
            className={programMode ? 'program-banner warn' : 'banner warn'}
            fixTo={programMode && project?.id ? `/program-projects/${project.id}` : undefined}
          />
        )}
    </>
  );
}
