import { useEffect, useMemo, useState } from 'react';
import { fetchProgramOverview } from '../../api/program';
import { formatPct, peso } from '../../components/money';
import ProgramPill from '../../components/program/ProgramPill';
import Reports from '../Reports';

function downloadCSV(content, filename) {
  const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.setAttribute('href', url);
  anchor.setAttribute('download', filename);
  anchor.style.visibility = 'hidden';
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

function csvCell(value) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

export default function ProgramReports() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;
    setLoading(true);
    fetchProgramOverview()
      .then((payload) => {
        if (alive) setData(payload);
      })
      .catch((requestError) => {
        if (alive) setError(requestError?.response?.data?.message || 'Unable to load reports.');
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => { alive = false; };
  }, []);

  const projects = useMemo(() => (Array.isArray(data?.projects) ? data.projects : []), [data]);
  const kpis = data?.kpis ?? {};
  const consolidatedRows = [...projects].sort((a, b) => (b.budget || 0) - (a.budget || 0));

  function printExecutiveSummary() {
    const lines = [
      ['Portfolio budget', peso(kpis.portfolio_budget ?? 0)],
      ['Total disbursed', peso(kpis.total_disbursed ?? 0)],
      ['Utilization', formatPct(kpis.utilization_pct ?? 0)],
      ['Average receipt compliance', kpis.avg_receipt_compliance != null ? formatPct(kpis.avg_receipt_compliance) : '—'],
      ['Open approvals', String(kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0)],
      ['Closed projects', String(kpis.health?.closed ?? 0)],
    ];

    const summaryRows = lines
      .map(([label, value]) => `<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e0d8">${label}</td><td style="padding:6px 8px;border-bottom:1px solid #e5e0d8;text-align:right">${value}</td></tr>`)
      .join('');

    const projectRows = consolidatedRows
      .map((row) => `<tr>
        <td style="padding:6px 8px;border-bottom:1px solid #e5e0d8">${row.title} (${row.code})</td>
        <td style="padding:6px 8px;border-bottom:1px solid #e5e0d8">${row.funder || '—'}</td>
        <td style="padding:6px 8px;border-bottom:1px solid #e5e0d8;text-align:right">${peso(row.budget ?? 0)}</td>
        <td style="padding:6px 8px;border-bottom:1px solid #e5e0d8;text-align:right">${peso(row.spent ?? 0)}</td>
        <td style="padding:6px 8px;border-bottom:1px solid #e5e0d8;text-align:right">${formatPct(row.spent_pct ?? 0)}</td>
        <td style="padding:6px 8px;border-bottom:1px solid #e5e0d8">${row.health}</td>
      </tr>`)
      .join('');

    const html = `<!doctype html><html><head><meta charset="utf-8"><title>Executive summary — Program portfolio</title></head>
      <body style="font-family:Inter,system-ui,-apple-system,sans-serif;color:#1f1a17;padding:32px">
        <h1 style="margin:0 0 4px;color:#6b1d2b">Executive summary — Program portfolio</h1>
        <p style="margin:0 0 20px;color:#6b625b;font-size:13px">Generated ${new Date().toLocaleString('en-PH')} · LIB Monitoring System, MSU–Main Campus</p>
        <h2 style="font-size:16px;margin:0 0 8px">Portfolio totals</h2>
        <table style="border-collapse:collapse;width:100%;font-size:13px">${summaryRows}</table>
        <h2 style="font-size:16px;margin:24px 0 8px">Project breakdown</h2>
        <table style="border-collapse:collapse;width:100%;font-size:12px">
          <thead><tr>
            <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #d9d2c7">Project</th>
            <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #d9d2c7">Funder</th>
            <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #d9d2c7">Budget</th>
            <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #d9d2c7">Spent</th>
            <th style="text-align:right;padding:6px 8px;border-bottom:2px solid #d9d2c7">Utilization</th>
            <th style="text-align:left;padding:6px 8px;border-bottom:2px solid #d9d2c7">Health</th>
          </tr></thead>
          <tbody>${projectRows}</tbody>
        </table>
      </body></html>`;

    const printWindow = window.open('', '_blank', 'width=960,height=720');
    if (!printWindow) {
      setError('Allow pop-ups for this site to print the executive summary.');
      return;
    }
    printWindow.document.write(html);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  }

  function exportConsolidated() {
    const header = ['Project', 'Code', 'Funder', 'Project Leader', 'Budget', 'Spent', 'Remaining', 'Utilization %', 'Health'];
    const lines = [header.map(csvCell).join(',')];
    consolidatedRows.forEach((row) => {
      lines.push([
        row.title,
        row.code,
        row.funder,
        row.project_leader,
        row.budget ?? 0,
        row.spent ?? 0,
        row.remaining ?? 0,
        row.spent_pct ?? 0,
        row.health,
      ].map(csvCell).join(','));
    });
    downloadCSV(lines.join('\n'), 'program-executive-summary.csv');
  }

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Reports · Program Leader</div>
          <h1 className="program-h1">Reports</h1>
          <p className="program-sub">Portfolio-level reporting for every funder and project.</p>
        </div>
        <div className="program-head-actions">
          <button type="button" className="program-btn" onClick={printExecutiveSummary}>
            Print executive summary
          </button>
          <button type="button" className="program-btn primary" onClick={exportConsolidated}>
            Export CSV
          </button>
        </div>
      </div>

      {error && <div className="program-banner error" role="alert">{error}</div>}

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Executive summary</div>
            <div className="program-card-sub">
              Year to date through the current month. These numbers come from the same backend payload as the dashboard.
            </div>
          </div>
        </div>

        <div className="program-grid-2">
          <div className="program-kpi">
            <div className="program-kpi-label">Portfolio budget</div>
            <div className="program-kpi-value">{peso(kpis.portfolio_budget ?? 0)}</div>
            <div className="program-kpi-sub">{kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0} pending approvals · {kpis.oldest_waiting_days ?? 0} days oldest</div>
          </div>
          <div className="program-kpi">
            <div className="program-kpi-label">Total disbursed</div>
            <div className="program-kpi-value">{peso(kpis.total_disbursed ?? 0)}</div>
            <div className="program-kpi-sub">{formatPct(kpis.utilization_pct ?? 0)} of portfolio budget</div>
          </div>
          <div className="program-kpi">
            <div className="program-kpi-label">Average receipt compliance</div>
            <div className="program-kpi-value">
              {kpis.avg_receipt_compliance != null ? formatPct(kpis.avg_receipt_compliance) : '—'}
            </div>
            <div className="program-kpi-sub">Verified expenses with receipts on file</div>
          </div>
          <div className="program-kpi">
            <div className="program-kpi-label">Project health</div>
            <div className="program-kpi-value">
              {kpis.health?.healthy ?? 0} healthy · {kpis.health?.watch ?? 0} watch
            </div>
            <div className="program-kpi-sub">
              {kpis.health?.at_risk ?? 0} at risk · {kpis.health?.closed ?? 0} closed
            </div>
          </div>
        </div>
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">All projects (consolidated)</div>
            <div className="program-card-sub">Every project in the portfolio, sorted by approved budget.</div>
          </div>
        </div>

        <div className="program-scroll">
          <table className="program-table" style={{ minWidth: 900 }}>
            <thead>
              <tr>
                <th>Project</th>
                <th>Funder</th>
                <th>Project Leader</th>
                <th className="r">Approved budget</th>
                <th className="r">Spent</th>
                <th className="r">Remaining</th>
                <th className="r">Utilization</th>
                <th>Health</th>
              </tr>
            </thead>
            <tbody>
              {consolidatedRows.map((row) => (
                <tr key={row.id}>
                  <td>
                    <div className="project-name">{row.title}</div>
                    <div className="project-code">{row.code}</div>
                  </td>
                  <td>{row.funder}</td>
                  <td>{row.project_leader || '—'}</td>
                  <td className="r num">{peso(row.budget ?? 0)}</td>
                  <td className="r num">{peso(row.spent ?? 0)}</td>
                  <td className="r num">{peso(row.remaining ?? 0)}</td>
                  <td className="r num">{formatPct(row.spent_pct ?? 0)}</td>
                  <td>
                    <ProgramPill value={row.health} />
                  </td>
                </tr>
              ))}
              {!loading && consolidatedRows.length === 0 && (
                <tr><td colSpan={8} className="program-empty">No projects are available to report on.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      <section className="program-card">
        {loading ? (
          <div className="program-empty">Loading reports…</div>
        ) : (
          <Reports programMode />
        )}
      </section>
    </>
  );
}
