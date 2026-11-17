import { useEffect, useRef, useState } from 'react';
import { peso } from '../components/money';
import { formatDate } from '../lib/datetime';
import client from '../api/client';
import { ENDPOINTS } from '../api/endpoints';
import Chip, { statusTone } from '../components/Chip';
import UtilBar from '../components/UtilBar';
import StatCard from '../components/StatCard';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from '../lib/roles';
import { myProjects, notAssignedText, projectRoleFor } from '../lib/myProjects';

const REPORT_TYPES = [
  {
    key: 'utilization',
    icon: '▦',
    title: 'Budget utilization report',
    desc: 'Allocated vs spent vs remaining per LIB line item, with PS/MOOE subtotals. For funding agency submission.',
  },
  {
    key: 'ledger',
    icon: '₱',
    title: 'Expense ledger',
    desc: 'Chronological list of all verified expenses with reference numbers and attachments index.',
  },
  {
    key: 'payroll-register',
    icon: '▥',
    title: 'Payroll register',
    desc: 'Monthly payroll per personnel with gross pay, itemized deductions, and net pay.',
  },
  {
    key: 'monthly-summary',
    icon: '▨',
    title: 'Monthly financial summary',
    desc: 'One-page snapshot: expense trend, category comparison, and alerts for the selected month.',
  },
];

const PERIODS = [
  'All dates',
  'Current year',
  'Last 90 days',
];

const CATEGORIES = ['PS and MOOE', 'PS only', 'MOOE only'];

function formatMoney(amount) {
  return peso(amount);
}

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

function money(value) {
  return `₱${(Number(value) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// Opens the browser print dialog (where "Save as PDF" is available) for a
// standalone HTML document, using a hidden iframe so no pop-up is needed.
function printHtmlDocument(html) {
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.tabIndex = -1;
  Object.assign(frame.style, { position: 'fixed', right: '0', bottom: '0', width: '0', height: '0', border: '0' });
  frame.onload = () => {
    const view = frame.contentWindow;
    if (!view) return;
    const cleanup = () => window.setTimeout(() => frame.remove(), 0);
    view.addEventListener('afterprint', cleanup, { once: true });
    view.focus();
    view.print();
    // Fallback for browsers that do not fire afterprint.
    window.setTimeout(() => { if (frame.isConnected) frame.remove(); }, 60000);
  };
  frame.srcdoc = html;
  document.body.appendChild(frame);
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
  // The one "my projects" rule for this role (the Program Leader: all projects).
  const myRole = programMode ? null : projectRoleFor(normalizeRole(user));
  const [chosenCode, setChosenCode] = useState('');
  const [selectedPeriod, setSelectedPeriod] = useState(PERIODS[0]);
  const [selectedCategory, setSelectedCategory] = useState(CATEGORIES[0]);
  const [toast, setToast] = useState(null); // { msg, type }
  const [printReport, setPrintReport] = useState(null); // { key, title }
  const [csvReport, setCsvReport] = useState(null); // { key, title, content, filename }

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
  const projectList = projectsStatus === 'ready' ? projectsResult.list : [];
  // ?project=<id> (links from the role Overviews) picks the project too.
  const requestedId = new URLSearchParams(window.location.search).get('project');
  const listRow = projectList.find((p) => p.code === chosenCode)
    ?? projectList.find((p) => p.code === initialCode)
    ?? projectList.find((p) => requestedId && String(p.id) === requestedId)
    ?? projectList[0]
    ?? null;
  const selectedCode = listRow?.code ?? '';

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
  // The API does not return payroll rows for reports yet, so the payroll
  // register stays unavailable instead of showing sample data.
  const payrollSource = Array.isArray(detail?.payroll) ? detail.payroll : null;

  function retryProjects() {
    setProjectsAttempt((value) => value + 1);
  }

  function retryDetail() {
    setDetailAttempt((value) => value + 1);
  }

  function reportAvailable(key) {
    return reportsReady && (key !== 'payroll-register' || payrollSource !== null);
  }

  // ---------- Filtering ----------
  function filterExpenses() {
    let result = [...expenseRows];
    const today = new Date();

    if (selectedPeriod === 'Current year') {
      const yearStart = `${today.getFullYear()}-01-01`;
      result = result.filter((e) => e.date >= yearStart);
    } else if (selectedPeriod === 'Last 90 days') {
      const cutoff = new Date(today.getTime() - 90 * 24 * 60 * 60 * 1000)
        .toISOString()
        .slice(0, 10);
      result = result.filter((e) => e.date >= cutoff);
    }

    if (selectedCategory === 'PS only') {
      result = result.filter((e) => e.type === 'PS' || (
        (e.category || '').includes('Honoraria')
        || (e.category || '').includes('Professional Services')
        || (e.category || '').includes('Salaries')
      ));
    } else if (selectedCategory === 'MOOE only') {
      result = result.filter((e) => e.type === 'MOOE' || (
        !(e.category || '').includes('Honoraria')
        && !(e.category || '').includes('Professional Services')
        && !(e.category || '').includes('Salaries')
      ));
    }

    return result.sort((a, b) => new Date(b.date) - new Date(a.date));
  }

  const expensesForPreview = filterExpenses();
  const payrollForPreview = payrollSource ?? [];

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
          bar: <UtilBar key={c.name} pct={util} />,
        });
        gAlloc += c.allocated; gSpent += c.spent;
      });
      const gUtil = gAlloc ? (gSpent / gAlloc) * 100 : 0;
      rows.push({
        group: title, item: `${title} Subtotal`, allocated: gAlloc, spent: gSpent,
        remaining: gAlloc - gSpent, util: gUtil, bold: true,
        bar: <UtilBar key={title} pct={gUtil} />,
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
      bar: <UtilBar key="total" pct={totalAlloc ? (totalSpent / totalAlloc) * 100 : 0} />,
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
      chip: <Chip key={e.id} tone={statusTone(e.status)}>{e.status}</Chip>,
    }));
  }

  function buildPayrollRows() {
    return payrollForPreview.map((r) => ({
      name: r.name,
      position: r.position,
      category: r.category,
      basic: r.basic,
      gross: r.gross,
      deductions: r.deductions,
      net: r.net,
      status: r.status,
      chip: <Chip key={r.id} tone={statusTone(r.status)}>{r.status}</Chip>,
    }));
  }

  function buildMonthlySummaryRows() {
    const data = {};
    expensesForPreview.forEach((e) => {
      const key = (e.date || '').slice(0, 7);
      if (!key) return;
      if (!data[key]) data[key] = { ps: 0, mooe: 0 };
      const isPS = e.type === 'PS'
        || (e.category || '').includes('Honoraria')
        || (e.category || '').includes('Professional Services')
        || (e.category || '').includes('Salaries');
      if (isPS) data[key].ps += Number(e.amount) || 0;
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

  function handleGenerate(type) {
    if (!reportAvailable(type)) return;
    const projectName = selectedProject.title;
    showToast(`Generating report preview for ${projectName}…`, 'info');

    let previewRows, reportTitle, csvHeaders, csvRows;

    if (type === 'utilization') {
      previewRows = buildUtilizationRows();
      reportTitle = 'Budget Utilization Report';
      csvHeaders = ['Category Type', 'Line Item', 'Allocated', 'Spent', 'Remaining', 'Utilization %'];
      csvRows = previewRows.map((r) => ({
        'Category Type': r.group || '',
        'Line Item': r.item,
        'Allocated': r.allocated,
        'Spent': r.spent,
        'Remaining': r.remaining,
        'Utilization %': r.util.toFixed(1),
      }));
    } else if (type === 'ledger') {
      previewRows = buildLedgerRows();
      reportTitle = 'Expense Ledger';
      csvHeaders = ['Date', 'Reference', 'Description', 'Category', 'Amount', 'Status', 'Recorded By', 'Verified By'];
      csvRows = previewRows.map((r) => ({
        Date: r.date, Reference: r.ref, Description: r.description, Category: r.category,
        Amount: r.amount, Status: r.status, 'Recorded By': r.recordedBy, 'Verified By': r.verifiedBy,
      }));
    } else if (type === 'payroll-register') {
      previewRows = buildPayrollRows();
      reportTitle = 'Payroll Register';
      csvHeaders = ['Name', 'Position', 'Category', 'Basic', 'Gross Pay', 'Deductions', 'Net Pay', 'Status'];
      csvRows = previewRows.map((r) => ({
        Name: r.name, Position: r.position, Category: r.category, Basic: r.basic,
        'Gross Pay': r.gross, Deductions: r.deductions, 'Net Pay': r.net, Status: r.status,
      }));
    } else {
      previewRows = buildMonthlySummaryRows();
      reportTitle = 'Monthly Financial Summary';
      csvHeaders = ['Month', 'Personnel Services (PS)', 'Maintenance & Other Operating Expenses (MOOE)', 'Total'];
      csvRows = previewRows.map((r) => ({
        Month: r.month, 'Personnel Services (PS)': r.ps,
        'Maintenance & Other Operating Expenses (MOOE)': r.mooe, Total: r.total,
      }));
    }

    setPrintReport({ key: type, title: reportTitle });
    setCsvReport({ key: type, title: reportTitle, content: toCSV(csvRows, csvHeaders), filename: `${type.toLowerCase().replace(/\s+/g, '-')}.csv` });
  }

  // ---------- Print-ready HTML ----------
  function buildPrintHTML() {
    const { key, title } = printReport || {};
    if (!key) return '';

    let tableHtml = '';
    let subtitle = '';

    if (key === 'utilization') {
      const rows = buildUtilizationRows();
      subtitle = 'Allocated vs spent vs remaining per LIB line item, with PS/MOOE subtotals';
      tableHtml = `
        <table>
          <thead>
            <tr><th>Category Type</th><th>Line Item</th><th class="r">Allocated</th><th class="r">Spent</th><th class="r">Remaining</th><th class="r">Utilization</th></tr>
          </thead>
          <tbody>
            ${rows.map((r) => `
              <tr${r.bold ? ' style="font-weight:700;background:#F7F6F2"' : ''}>
                <td>${escapeHtml(r.group)}</td>
                <td>${escapeHtml(r.item)}</td>
                <td class="r">${money(r.allocated)}</td>
                <td class="r">${money(r.spent)}</td>
                <td class="r">${money(r.remaining)}</td>
                <td class="r">${(Number(r.util) || 0).toFixed(1)}%</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    } else if (key === 'ledger') {
      const rows = buildLedgerRows();
      subtitle = 'Chronological list of all verified expenses with reference numbers';
      tableHtml = `
        <table>
          <thead>
            <tr><th>Date</th><th>Reference</th><th>Description</th><th>Category</th><th class="r">Amount</th><th>Status</th><th>Recorded By</th><th>Verified By</th></tr>
          </thead>
          <tbody>
            ${rows.map((r) => `
              <tr>
                <td>${escapeHtml(r.date)}</td><td>${escapeHtml(r.ref)}</td><td>${escapeHtml(r.description)}</td><td>${escapeHtml(r.category)}</td>
                <td class="r">${money(r.amount)}</td>
                <td><span style="display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#${statusTone(r.status)==='ok'?'E9F3EE':statusTone(r.status)==='warn'?'F8EFD8':'F8E7E4'};color:#${statusTone(r.status)==='ok'?'2F7D5B':statusTone(r.status)==='warn'?'9A6B10':'B3382C'}">${escapeHtml(r.status)}</span></td>
                <td>${escapeHtml(r.recordedBy)}</td><td>${escapeHtml(r.verifiedBy)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    } else if (key === 'payroll-register') {
      const rows = buildPayrollRows();
      subtitle = 'Monthly payroll per personnel';
      tableHtml = `
        <table>
          <thead>
            <tr><th>Name</th><th>Position</th><th>Category</th><th class="r">Basic</th><th class="r">Gross Pay</th><th class="r">Deductions</th><th class="r">Net Pay</th><th>Status</th></tr>
          </thead>
          <tbody>
            ${rows.map((r) => `
              <tr>
                <td>${escapeHtml(r.name)}</td><td>${escapeHtml(r.position)}</td><td>${escapeHtml(r.category)}</td>
                <td class="r">${money(r.basic)}</td><td class="r">${money(r.gross)}</td>
                <td class="r">${money(r.deductions)}</td><td class="r">${money(r.net)}</td>
                <td><span style="display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#${statusTone(r.status)==='ok'?'E9F3EE':'F8EFD8'};color:#${statusTone(r.status)==='ok'?'2F7D5B':'9A6B10'}">${escapeHtml(r.status)}</span></td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    } else {
      const rows = buildMonthlySummaryRows();
      subtitle = 'Expense trend, category comparison, and alerts for the selected month';
      tableHtml = `
        <table>
          <thead>
            <tr><th>Month</th><th class="r">Personnel Services (PS)</th><th class="r">Maintenance & Other Operating Expenses (MOOE)</th><th class="r">Total</th></tr>
          </thead>
          <tbody>
            ${rows.map((r) => `
              <tr>
                <td>${escapeHtml(r.month)}</td><td class="r">${money(r.ps)}</td>
                <td class="r">${money(r.mooe)}</td><td class="r">${money(r.total)}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    }

    return `
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="utf-8" />
        <title>${escapeHtml(title)}</title>
        <style>
          @page { size: A4; margin: 1.5cm; }
          body { font-family: 'Public Sans', system-ui, sans-serif; font-size: 11px; color: #221D1A; line-height: 1.4; }
          .no-print { display: none !important; }
          .letterhead { border-bottom: 3px solid #5E1B25; padding-bottom: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
          .seal { width: 40px; height: 40px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #BE9A3E, #8a6d12); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #3d2f06; font-size: 12px; }
          .org h1 { font-size: 17px; font-weight: 800; margin: 0; color: #5E1B25; }
          .org p { font-size: 10px; color: #6E655E; margin: 2px 0 0; }
          .report-title { font-size: 15px; font-weight: 700; margin: 10px 0 4px; color: #221D1A; }
          .report-sub { font-size: 10.5px; color: #6E655E; margin-bottom: 14px; }
          .meta { font-size: 10px; color: #6E655E; margin-bottom: 14px; display: flex; gap: 24px; flex-wrap: wrap; }
          table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
          th { background: #F7F6F2; color: #6E655E; font-weight: 600; text-align: left; padding: 6px 8px; border: 1px solid #CFC9BC; }
          td { padding: 5px 8px; border: 1px solid #E4E0D7; vertical-align: top; }
          td.r { text-align: right; }
          tr:nth-child(even) td { background: #FAF9F7; }
          .total-row { font-weight: 700; background: #E4E0D7 !important; }
          .footer { margin-top: 20px; font-size: 9px; color: #6E655E; border-top: 1px solid #CFC9BC; padding-top: 8px; }
        </style>
      </head>
      <body>
        <div class="letterhead">
          <div class="seal">MSU</div>
          <div class="org">
            <h1>Mindanao State University–Main Campus</h1>
            <p>Office of the Vice Chancellor for Research & Extension · LIB Monitoring System</p>
            <p>Monitoring Agency: <b>${escapeHtml(selectedProject.funding_agency || 'External funder')}</b> · Implementing Agency: <b>MSU-Marawi</b></p>
          </div>
        </div>

        <div class="report-title">${escapeHtml(title)}</div>
        <div class="report-sub">${escapeHtml(subtitle)}</div>

        <div class="meta">
          <span><b>Project:</b> ${escapeHtml(selectedProject.title)} (${escapeHtml(selectedProject.code)})</span>
          <span><b>Project Leader:</b> ${escapeHtml(selectedProject.project_leader)}</span>
          <span><b>Period:</b> ${escapeHtml(selectedPeriod)}</span>
          <span><b>Category:</b> ${escapeHtml(selectedCategory)}</span>
          <span><b>Generated:</b> ${escapeHtml(formatDate(new Date()))}</span>
        </div>

        ${tableHtml}

        <div class="footer">
          This report was generated from verified expense records only. <br />
          Mindanao State University–Main Campus · MOST R&D Form B · Line-Item Budget Monitoring System.
        </div>
      </body>
      </html>
    `;
  }

  // Opens the print dialog for the previewed report ("Save as PDF" there).
  function handlePrint() {
    if (!printReport || !reportAvailable(printReport.key)) return;
    printHtmlDocument(buildPrintHTML());
  }

  function handleCSV() {
    if (!csvReport || !reportAvailable(csvReport.key)) return;
    showToast(`Exporting ${csvReport.title} to CSV…`, 'info');
    downloadCSV(csvReport.content, csvReport.filename);
  }

  // ---------- Render preview table ----------
  function renderPreviewTable() {
    const { key } = printReport || {};
    if (!key) return null;

    let rows, columns;

    if (key === 'utilization') {
      rows = buildUtilizationRows();
      columns = [
        { key: 'item', label: 'Line Item' },
        { key: 'group', label: 'Category Type' },
        { key: 'allocated', label: 'Allocated' },
        { key: 'spent', label: 'Spent' },
        { key: 'remaining', label: 'Remaining' },
        { key: 'util', label: 'Utilization' },
      ];
    } else if (key === 'ledger') {
      rows = buildLedgerRows();
      columns = [
        { key: 'date', label: 'Date' },
        { key: 'ref', label: 'Reference' },
        { key: 'description', label: 'Description' },
        { key: 'category', label: 'Category' },
        { key: 'amount', label: 'Amount' },
        { key: 'status', label: 'Status' },
      ];
    } else if (key === 'payroll-register') {
      rows = buildPayrollRows();
      columns = [
        { key: 'name', label: 'Name' },
        { key: 'position', label: 'Position' },
        { key: 'category', label: 'Category' },
        { key: 'basic', label: 'Basic' },
        { key: 'gross', label: 'Gross Pay' },
        { key: 'deductions', label: 'Deductions' },
        { key: 'net', label: 'Net Pay' },
        { key: 'status', label: 'Status' },
      ];
    } else {
      rows = buildMonthlySummaryRows();
      columns = [
        { key: 'month', label: 'Month' },
        { key: 'ps', label: 'PS' },
        { key: 'mooe', label: 'MOOE' },
        { key: 'total', label: 'Total' },
      ];
    }

    return (
      <div className="panel" style={{ marginTop: 16, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
        <div className="panel-head">
          <h3>Live Report Preview</h3>
          <span className="hint">{printReport?.title} · {rows.length} row(s)</span>
          <div className="actions" style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            {detailStatus === 'error' ? (
              <span className="rp-error" role="alert">
                Could not load report data.
                <button type="button" className="btn sm" onClick={retryDetail}>Try again</button>
              </span>
            ) : (
              <>
                <button
                  type="button"
                  className="btn sm"
                  onClick={handlePrint}
                  disabled={!reportAvailable(printReport?.key)}
                  aria-busy={detailStatus === 'loading'}
                >
                  {detailStatus === 'loading' && <span className="spin" aria-hidden="true" />}
                  {detailStatus === 'loading' ? 'Loading data…' : 'Generate PDF'}
                </button>
                <button type="button" className="btn sm" onClick={handleCSV} disabled={!reportAvailable(printReport?.key)}>
                  Export to Excel / CSV
                </button>
              </>
            )}
          </div>
        </div>
        <div className="wide-scroll" style={{ padding: '0 20px 20px', overflowX: 'auto' }}>
          <table>
            <thead>
              <tr>
                {columns.map((c) => (
                  <th key={c.key} className={c.key === 'amount' || c.key === 'allocated' || c.key === 'spent' || c.key === 'remaining' || c.key === 'basic' || c.key === 'gross' || c.key === 'deductions' || c.key === 'net' || c.key === 'total' || c.key === 'util' ? 'r' : ''}>{c.label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => {
                const isBold = r.bold;
                const isTotalRow = r.item === 'Grand Total (PS + MOOE)' || r.item === 'PS Subtotal' || r.item === 'MOOE Subtotal';
                return (
                  <tr key={i} style={{ fontWeight: isBold || isTotalRow ? 700 : 400, background: isTotalRow ? 'color-mix(in srgb, var(--gold) 8%, transparent)' : 'inherit' }}>
                    {columns.map((c) => {
                      if (c.key === 'bar') return <td key={c.key}>{r.bar}</td>;
                      if (c.key === 'chip') return <td key={c.key}>{r.chip}</td>;
                      let val = r[c.key];
                      if (typeof val === 'number' && (c.key.includes('allocated') || c.key.includes('spent') || c.key.includes('remaining') || c.key.includes('basic') || c.key.includes('gross') || c.key.includes('deductions') || c.key.includes('net') || c.key.includes('total') || c.key === 'ps' || c.key === 'mooe')) {
                        val = formatMoney(val);
                      }
                      if (c.key === 'util') val = `${r.util.toFixed(1)}%`;
                      return <td key={c.key} className={(c.key === 'amount' || c.key === 'allocated' || c.key === 'spent' || c.key === 'remaining' || c.key === 'basic' || c.key === 'gross' || c.key === 'deductions' || c.key === 'net' || c.key === 'total' || c.key === 'ps' || c.key === 'mooe' || c.key === 'util') ? 'r' : ''}>{val}</td>;
                    })}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
    );
  }

  // ---------- Report summary cards (canonical backend figures) ----------
  const totalAlloc = Number(selectedProject.total_budget) || 0;
  const totalSpent = Number(selectedProject.total_spent) || 0;
  const remaining = Number(selectedProject.remaining ?? (totalAlloc - totalSpent)) || 0;
  const utilization = Number(selectedProject.utilization_pct) || 0;

  // Direct CSV export for one report type (same rows as the PDF preview).
  function exportReportCsv(r) {
    if (!reportAvailable(r.key)) return;
    // Build CSV on the fly for direct export
    let rows, headers, filename;
    if (r.key === 'utilization') {
      rows = buildUtilizationRows();
      headers = ['Category Type', 'Line Item', 'Allocated', 'Spent', 'Remaining', 'Utilization %'];
      filename = 'budget-utilization.csv';
    } else if (r.key === 'ledger') {
      rows = buildLedgerRows();
      headers = ['Date', 'Reference', 'Description', 'Category', 'Amount', 'Status', 'Recorded By', 'Verified By'];
      filename = 'expense-ledger.csv';
    } else if (r.key === 'payroll-register') {
      rows = buildPayrollRows();
      headers = ['Name', 'Position', 'Category', 'Basic', 'Gross Pay', 'Deductions', 'Net Pay', 'Status'];
      filename = 'payroll-register.csv';
    } else {
      rows = buildMonthlySummaryRows();
      headers = ['Month', 'Personnel Services (PS)', 'Maintenance & Other Operating Expenses (MOOE)', 'Total'];
      filename = 'monthly-financial-summary.csv';
    }
    showToast(`Exporting ${r.title} to CSV…`, 'info');
    downloadCSV(toCSV(rows, headers), filename);
  }

  // Project / Period / Category selects, shared by both layouts.
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
        <div {...fieldProps}>
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
        </div>
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

  // Values appear only once the selected project's real data has loaded.
  const shown = (value) => (project ? value : '—');
  const summaryCards = (
    <>
      <StatCard label={`Total ${project?.funding_agency || listRow?.agency || 'Funder'} funding`} value={shown(formatMoney(selectedProject.funding_budget || 0))} sub={`${project?.funding_agency || listRow?.agency || 'External funder'} grant`} />
      <StatCard label="MSU–Main Campus counterpart" value={shown(formatMoney(selectedProject.counterpart_budget || 0))} sub="Implementing agency share" />
      <StatCard label="Total spent" value={shown(formatMoney(totalSpent))} sub={project ? `${utilization.toFixed(1)}% of total budget` : 'Waiting for project data'} />
      <StatCard label="Remaining Balance" value={shown(formatMoney(remaining))} sub={project ? `${(totalAlloc ? ((remaining / totalAlloc) * 100) : 0).toFixed(1)}% unspent` : 'Waiting for project data'} />
    </>
  );

  // Shown under the parameters while the selected project's data failed.
  const detailError = detailStatus === 'error' && (
    <div className="rp-error rp-error-block" role="alert">
      <span>{detailResult?.error || "Could not load this project's report data."}</span>
      <button type="button" className={programMode ? 'program-btn' : 'btn sm'} onClick={retryDetail}>Try again</button>
    </div>
  );
  const payrollNote = 'Not available yet: the server does not provide payroll records for reports.';

  // ?report=<key>&format=pdf|csv (the PDF / CSV buttons on the role
  // Overviews): run that export once the project's data is loaded, with the
  // same code as the buttons below.
  const autoExport = useRef(false);
  useEffect(() => {
    if (autoExport.current || !reportsReady) return;
    const params = new URLSearchParams(window.location.search);
    const report = REPORT_TYPES.find((r) => r.key === params.get('report'));
    const format = params.get('format');
    if (!report || !['pdf', 'csv'].includes(format)) return;
    autoExport.current = true;
    if (!reportAvailable(report.key)) return;
    if (format === 'pdf') handleGenerate(report.key);
    else exportReportCsv(report);
  });

  return (
    <>
      {!programMode && (
        <div className="page-head">
          <div>
            <h2>Reports</h2>
            <p>Generated from verified expense records only · MOST R&D Form B</p>
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
              <div className="program-card-sub">
                Choose a project and period first. Every report below uses this selection and the same verified data as the dashboard.
              </div>
            </div>
          </div>

          <div className="prr-params" role="group" aria-label="Report parameters">
            {renderParamFields(true)}
          </div>
          {detailError}

          <div className="prr-label" id="prr-glance">Selected project at a glance</div>
          <div className="prr-stats" role="group" aria-labelledby="prr-glance">
            {summaryCards}
          </div>

          <div className="prr-label" id="prr-reports">Reports for this project</div>
          <ul className="prr-list" aria-labelledby="prr-reports">
            {REPORT_TYPES.map((r) => (
              <li className="prr-row" key={r.key}>
                <span className="prr-ic" aria-hidden="true">{r.icon}</span>
                <div className="prr-text">
                  <b>{r.title}</b>
                  <p>{r.desc}</p>
                  {r.key === 'payroll-register' && reportsReady && payrollSource === null && (
                    <p className="rp-note">{payrollNote}</p>
                  )}
                </div>
                <div className="prr-actions">
                  <button type="button" className="program-btn" onClick={() => handleGenerate(r.key)} disabled={!reportAvailable(r.key)}>
                    Generate PDF
                  </button>
                  <button type="button" className="program-btn" onClick={() => exportReportCsv(r)} disabled={!reportAvailable(r.key)}>
                    Export CSV
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <>
          {/* Report cards */}
          <div className="report-opts" style={{ marginBottom: 20 }}>
            {REPORT_TYPES.map((r) => (
              <div className="report-card" key={r.key}>
                <div className="rc-ic">{r.icon}</div>
                <div style={{ flex: 1 }}>
                  <b>{r.title}</b>
                  <p>{r.desc}</p>
                  {r.key === 'payroll-register' && reportsReady && payrollSource === null && (
                    <p className="rp-note">{payrollNote}</p>
                  )}
                </div>
                <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
                  <button className="btn sm" onClick={() => handleGenerate(r.key)} disabled={!reportAvailable(r.key)}>
                    Generate PDF
                  </button>
                  <button className="btn sm" onClick={() => exportReportCsv(r)} disabled={!reportAvailable(r.key)}>
                    Export CSV
                  </button>
                </div>
              </div>
            ))}
          </div>

          {/* Report parameters */}
          <div className="panel" style={{ marginBottom: 16 }}>
            <div className="panel-head"><h3>Report parameters</h3></div>
            <div style={{ padding: 16 }} className="grid c3">
              {renderParamFields(false)}
            </div>
          </div>
          {detailError}

          {/* Summary widgets */}
          <div className="grid c4" style={{ marginBottom: 16, gap: 14 }}>
            {summaryCards}
          </div>
        </>
      )}

      {/* Live preview */}
      {renderPreviewTable()}
    </>
  );
}
