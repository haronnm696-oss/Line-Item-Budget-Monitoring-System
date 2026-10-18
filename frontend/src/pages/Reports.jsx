import { useState } from 'react';
import { peso } from '../components/money';
import {
  CURRENT_PROJECT,
  projects,
  libCategoriesPS,
  libCategoriesMOOE,
  expenses,
  payrollRun,
} from '../data/mockData';
import Chip, { statusTone } from '../components/Chip';
import UtilBar from '../components/UtilBar';
import StatCard from '../components/StatCard';

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
  'Jan – Sep 2026 (year to date)',
  'Q3 2026 (Jul – Sep)',
  'Q2 2026 (Apr – Jun)',
  'Q1 2026 (Jan – Mar)',
  'Custom range…',
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

export default function Reports() {
  const [selectedProject, setSelectedProject] = useState(CURRENT_PROJECT);
  const [selectedPeriod, setSelectedPeriod] = useState(PERIODS[0]);
  const [selectedCategory, setSelectedCategory] = useState(CATEGORIES[0]);
  const [toast, setToast] = useState(null); // { msg, type }
  const [printOpen, setPrintOpen] = useState(false);
  const [printReport, setPrintReport] = useState(null); // { key, title, html }
  const [csvReport, setCsvReport] = useState(null); // { key, title, content, filename }

  // ---------- Filtering ----------
  const filteredProjects = projects;

  function filterExpenses() {
    let result = [...expenses];
    // Period filter
    if (selectedPeriod.startsWith('Q3')) {
      result = result.filter((e) => e.date >= '2026-07-01' && e.date <= '2026-09-30');
    } else if (selectedPeriod.startsWith('Q2')) {
      result = result.filter((e) => e.date >= '2026-04-01' && e.date <= '2026-06-30');
    } else if (selectedPeriod.startsWith('Q1')) {
      result = result.filter((e) => e.date >= '2026-01-01' && e.date <= '2026-03-31');
    } else if (selectedPeriod.startsWith('Jan – Sep')) {
      result = result.filter((e) => e.date >= '2026-01-01' && e.date <= '2026-09-30');
    }
    // Category filter
    if (selectedCategory === 'PS only') {
      result = result.filter((e) =>
        e.category.includes('Honoraria') || e.category.includes('Professional Services') || e.category.includes('Salaries')
      );
    } else if (selectedCategory === 'MOOE only') {
      result = result.filter((e) =>
        !e.category.includes('Honoraria') && !e.category.includes('Professional Services') && !e.category.includes('Salaries')
      );
    }
    return result.sort((a, b) => new Date(b.date) - new Date(a.date));
  }

  const expensesForPreview = filterExpenses();
  const payrollForPreview = [...payrollRun.rows];

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

    addGroup('I. Personnel Services (PS)', libCategoriesPS);
    addGroup('II. Maintenance & Other Operating Expenses (MOOE)', libCategoriesMOOE);

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
    // Group expenses by month
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep'];
    const data = {};
    months.forEach((m) => { data[m] = { ps: 0, mooe: 0 }; });
    expensesForPreview.forEach((e) => {
      const m = e.date.slice(5, 7);
      const monthName = months[parseInt(m, 10) - 1];
      if (!data[monthName]) data[monthName] = { ps: 0, mooe: 0 };
      const isPS = e.category.includes('Honoraria') || e.category.includes('Professional Services') || e.category.includes('Salaries');
      if (isPS) data[monthName].ps += e.amount;
      else data[monthName].mooe += e.amount;
    });
    return months.map((m) => ({
      month: m,
      ps: data[m].ps,
      mooe: data[m].mooe,
      total: data[m].ps + data[m].mooe,
    }));
  }

  // ---------- Actions ----------
  function showToast(msg, type = 'info') {
    setToast({ msg, type });
    setTimeout(() => setToast(null), 3500);
  }

  function handleGenerate(type) {
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
      reportTitle = 'Payroll Register — ' + payrollRun.month;
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

    setPrintReport({ key: type, title: reportTitle, html: null });
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
                <td>${r.group || ''}</td>
                <td>${r.item}</td>
                <td class="r">₱${r.allocated.toLocaleString()}</td>
                <td class="r">₱${r.spent.toLocaleString()}</td>
                <td class="r">₱${r.remaining.toLocaleString()}</td>
                <td class="r">${r.util.toFixed(1)}%</td>
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
                <td>${r.date}</td><td>${r.ref}</td><td>${r.description}</td><td>${r.category}</td>
                <td class="r">₱${r.amount.toLocaleString()}</td>
                <td><span style="display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#${statusTone(r.status)==='ok'?'E9F3EE':statusTone(r.status)==='warn'?'F8EFD8':'F8E7E4'};color:#${statusTone(r.status)==='ok'?'2F7D5B':statusTone(r.status)==='warn'?'9A6B10':'B3382C'}">${r.status}</span></td>
                <td>${r.recordedBy}</td><td>${r.verifiedBy}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      `;
    } else if (key === 'payroll-register') {
      const rows = buildPayrollRows();
      subtitle = `Monthly payroll per personnel — ${payrollRun.month}`;
      tableHtml = `
        <table>
          <thead>
            <tr><th>Name</th><th>Position</th><th>Category</th><th class="r">Basic</th><th class="r">Gross Pay</th><th class="r">Deductions</th><th class="r">Net Pay</th><th>Status</th></tr>
          </thead>
          <tbody>
            ${rows.map((r) => `
              <tr>
                <td>${r.name}</td><td>${r.position}</td><td>${r.category}</td>
                <td class="r">₱${r.basic.toLocaleString()}</td><td class="r">₱${r.gross.toLocaleString()}</td>
                <td class="r">₱${r.deductions.toLocaleString()}</td><td class="r">₱${r.net.toLocaleString()}</td>
                <td><span style="display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#${statusTone(r.status)==='ok'?'E9F3EE':'F8EFD8'};color:#${statusTone(r.status)==='ok'?'2F7D5B':'9A6B10'}">${r.status}</span></td>
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
                <td>${r.month}</td><td class="r">₱${r.ps.toLocaleString()}</td>
                <td class="r">₱${r.mooe.toLocaleString()}</td><td class="r">₱${r.total.toLocaleString()}</td>
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
        <title>${title}</title>
        <style>
          @page { size: A4; margin: 1.5cm; }
          body { font-family: 'Public Sans', system-ui, sans-serif; font-size: 11px; color: #221D1A; line-height: 1.4; }
          .no-print { display: none !important; }
          .letterhead { border-bottom: 3px solid #5E1B25; padding-bottom: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 12px; }
          .seal { width: 40px; height: 40px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #C9A227, #8a6d12); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #3d2f06; font-size: 12px; }
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
            <p>Monitoring Agency: <b>MOST-BARMM</b> · Implementing Agency: <b>MSU-Marawi</b></p>
          </div>
        </div>

        <div class="report-title">${title}</div>
        <div class="report-sub">${subtitle}</div>

        <div class="meta">
          <span><b>Project:</b> ${selectedProject.title} (${selectedProject.code})</span>
          <span><b>Project Leader:</b> ${selectedProject.project_leader}</span>
          <span><b>Period:</b> ${selectedPeriod}</span>
          <span><b>Category:</b> ${selectedCategory}</span>
          <span><b>Generated:</b> ${new Date().toLocaleDateString('en-PH')}</span>
        </div>

        ${tableHtml}

        <div class="footer">
          This report was generated from verified expenses and approved payroll only. <br />
          Mindanao State University–Main Campus · MOST R&D Form B · Line-Item Budget Monitoring System.
        </div>
        <script>window.print();</script>
      </body>
      </html>
    `;
  }

  function handlePrint() {
    if (!printReport) return;
    const html = buildPrintHTML();
    setPrintReport((prev) => ({ ...prev, html }));
    setPrintOpen(true);
  }

  function handleCSV() {
    if (!csvReport) return;
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
          <div className="actions" style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
            <button className="btn sm" onClick={handlePrint} disabled={!printReport?.html}>
              <span className="spin" style={{ display: printReport?.html ? 'none' : 'inline-block' }} />
              Generate PDF
            </button>
            <button className="btn sm" onClick={handleCSV}>
              Export to Excel / CSV
            </button>
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

  // ---------- Report summary cards ----------
  const psAllocated = libCategoriesPS.reduce((s, c) => s + c.allocated, 0);
  const psSpent = libCategoriesPS.reduce((s, c) => s + c.spent, 0);
  const mooeAllocated = libCategoriesMOOE.reduce((s, c) => s + c.spent, 0);
  const mooeSpent = libCategoriesMOOE.reduce((s, c) => s + c.spent, 0);
  const totalAlloc = psAllocated + mooeAllocated;
  const totalSpent = psSpent + mooeSpent;

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Reports</h2>
          <p>Generated from verified expenses and approved payroll only · MOST R&D Form B</p>
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className="banner" style={{ position: 'fixed', top: 90, right: 24, zIndex: 60, minWidth: 260, boxShadow: '0 2px 8px rgba(0,0,0,.15)' }}>
          {toast.msg}
        </div>
      )}

      {/* Report cards */}
      <div className="report-opts" style={{ marginBottom: 20 }}>
        {REPORT_TYPES.map((r) => (
          <div className="report-card" key={r.key}>
            <div className="rc-ic">{r.icon}</div>
            <div style={{ flex: 1 }}>
              <b>{r.title}</b>
              <p>{r.desc}</p>
            </div>
            <div style={{ display: 'flex', gap: 6, marginTop: 10 }}>
              <button className="btn sm" onClick={() => handleGenerate(r.key)}>
                Generate PDF
              </button>
              <button className="btn sm" onClick={() => {
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
              }}>
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
          <div className="field" style={{ margin: 0 }}>
            <label>Project</label>
            <select
              value={selectedProject.code}
              onChange={(e) => setSelectedProject(projects.find((p) => p.code === e.target.value) || CURRENT_PROJECT)}
            >
              {filteredProjects.map((p) => (
                <option key={p.id} value={p.code}>{p.title} ({p.code})</option>
              ))}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label>Period</label>
            <select value={selectedPeriod} onChange={(e) => setSelectedPeriod(e.target.value)}>
              {PERIODS.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label>Category</label>
            <select value={selectedCategory} onChange={(e) => setSelectedCategory(e.target.value)}>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </div>
        </div>
      </div>

      {/* Summary widgets */}
      <div className="grid c4" style={{ marginBottom: 16, gap: 14 }}>
        <StatCard label="Total MOST Funding" value={formatMoney(CURRENT_PROJECT.funding_budget)} sub="MOST-BARMM grant" />
        <StatCard label="MSU Counterpart Funding" value={formatMoney(CURRENT_PROJECT.counterpart_budget)} sub="MSU-Marawi share" />
        <StatCard label="Total Disbursed" value={formatMoney(totalSpent)} sub={`${((totalSpent / totalAlloc) * 100).toFixed(1)}% of total budget`} />
        <StatCard label="Remaining Balance" value={formatMoney(totalAlloc - totalSpent)} sub={`${(((totalAlloc - totalSpent) / totalAlloc) * 100).toFixed(1)}% unspent`} />
      </div>

      {/* Live preview */}
      {renderPreviewTable()}

      {/* Print modal */}
      {printOpen && printReport && (
        <div className="modal-overlay" onClick={() => setPrintOpen(false)} role="dialog" aria-modal="true" aria-label="Print report">
          <div className="modal-panel" style={{ maxWidth: '800px', maxHeight: '95vh', display: 'flex', flexDirection: 'column' }} onClick={(e) => e.stopPropagation()}>
            <div className="modal-head">
              <h3>Print Report — {printReport.title}</h3>
              <button type="button" className="modal-close" onClick={() => setPrintOpen(false)} aria-label="Close">×</button>
            </div>
            <div style={{ flex: 1, overflow: 'auto', padding: '16px 20px', fontSize: '12px', lineHeight: '1.5' }} dangerouslySetInnerHTML={{ __html: printReport.html || '' }} />
            <div style={{ padding: '12px 20px', borderTop: '1px solid var(--line)', display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
              <button type="button" className="btn" onClick={() => setPrintOpen(false)}>Close</button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
