import { useState } from 'react';
import { mutateWithFallback } from '../api/http';
import { ENDPOINTS } from '../api/endpoints';
import { CURRENT_PROJECT } from '../data/mockData';

const REPORT_TYPES = [
  { key: 'utilization', icon: '▦', title: 'Budget utilization report', desc: 'Allocated vs spent vs remaining per LIB line item, with PS/MOOE subtotals. For funding agency submission.' },
  { key: 'ledger', icon: '₱', title: 'Expense ledger', desc: 'Chronological list of all verified expenses with reference numbers and attachments index.' },
  { key: 'payroll-register', icon: '▥', title: 'Payroll register', desc: 'Monthly payroll per personnel with gross pay, itemized deductions, and net pay.' },
  { key: 'monthly-summary', icon: '▨', title: 'Monthly financial summary', desc: 'One-page snapshot: expense trend, category comparison, and alerts for the selected month.' },
];

export default function Reports() {
  const [generating, setGenerating] = useState(null);

  async function generate(type) {
    setGenerating(type);
    try {
      const result = await mutateWithFallback('get', ENDPOINTS.report(CURRENT_PROJECT.id, type), null, { url: null });
      if (result?.url) window.open(result.url, '_blank');
    } finally {
      setGenerating(null);
    }
  }

  return (
    <>
      <div className="page-head">
        <div>
          <h2>Reports</h2>
          <p>Generated from verified expenses and approved payroll only</p>
        </div>
      </div>

      <div className="report-opts">
        {REPORT_TYPES.map((r) => (
          <div className="report-card" key={r.key}>
            <div className="rc-ic">{r.icon}</div>
            <div>
              <b>{r.title}</b>
              <p>{r.desc}</p>
              <button className="btn" style={{ marginTop: 10 }} onClick={() => generate(r.key)} disabled={generating === r.key}>
                {generating === r.key && <span className="spin" />}Generate PDF
              </button>
            </div>
          </div>
        ))}
      </div>

      <div className="panel" style={{ marginTop: 16 }}>
        <div className="panel-head"><h3>Report parameters</h3></div>
        <div style={{ padding: 16 }} className="grid c3">
          <div className="field" style={{ margin: 0 }}>
            <label>Project</label>
            <select><option>{CURRENT_PROJECT.title} ({CURRENT_PROJECT.code})</option><option>All my projects</option></select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label>Period</label>
            <select><option>Jan – Sep 2026 (year to date)</option><option>Q3 2026</option><option>Custom range…</option></select>
          </div>
          <div className="field" style={{ margin: 0 }}>
            <label>Category</label>
            <select><option>PS and MOOE</option><option>PS only</option><option>MOOE only</option></select>
          </div>
        </div>
      </div>
    </>
  );
}
