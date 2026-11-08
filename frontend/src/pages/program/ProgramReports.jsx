import { useEffect, useMemo, useState } from 'react';
import { fetchProgramOverview } from '../../api/program';
import { formatPct, peso } from '../../components/money';

function formatMoney(amount) {
  return peso(amount);
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

export default function ProgramReports() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [scope, setScope] = useState('consolidated');
  const [selectedCode, setSelectedCode] = useState('');

  useEffect(() => {
    let alive = true;
    setLoading(true);
    fetchProgramOverview()
      .then((payload) => {
        if (!alive) return;
        setData(payload);
        const projects = Array.isArray(payload?.projects) ? payload.projects : [];
        if (projects.length) {
          setSelectedCode(projects[0].code);
        }
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
  const selected = projects.find((project) => project.code === selectedCode) ?? projects[0];

  const consolidatedRows = [...projects].sort((a, b) => (b.budget || 0) - (a.budget || 0));

  function buildExecutiveSummaryHtml() {
    const lines = [
      ['Portfolio budget', formatMoney(kpis.portfolio_budget ?? 0)],
      ['Total disbursed', formatMoney(kpis.total_disbursed ?? 0)],
      ['Utilization', `${formatPct(kpis.utilization_pct ?? 0)}`],
      ['Average receipt compliance', kpis.avg_receipt_compliance != null ? formatPct(kpis.avg_receipt_compliance) : '—'],
      ['Open approvals', String(kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0)],
      ['Closed projects', String(kpis.health?.closed ?? 0)],
    ];

    const body = lines.map(([label, value]) => `<tr><td>${label}</td><td style="text-align:right">${value}</td></tr>`).join('');
    const table = `<table style="border-collapse:collapse;width:100%"><thead><tr><th>Item</th><th style="text-align:right">Amount</th></tr></thead><tbody>${body}</tbody></table>`;

    const html = `<!doctype html><html><body style="font-family:Inter,system-ui;padding:24px"><h1>Executive summary — Program portfolio</h1><p>Generated ${new Date().toLocaleString('en-PH')}</p>${table}</body></html>`;

    const blob = new Blob([html], { type: 'text/html;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const windowProxy = window.open(`${url}`, '_blank');
    if (!windowProxy) return false;
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
    return true;
  }

  function exportConsolidated() {
    const header = ['Project', 'Funder', 'Project Leader', 'Budget', 'Spent', 'Remaining', 'Utilization %', 'Health'];
    const lines = [header.join(',')];
    consolidatedRows.forEach((row) => {
      const cells = [
        row.title,
        row.funder,
        row.project_leader || '',
        String(row.budget ?? 0),
        String(row.spent ?? 0),
        String(row.remaining ?? 0),
        String(row.spent_pct ?? 0),
        row.health,
      ];
      lines.push(cells.map((cell) => (cell == null ? '' : String(cell).replace(/,/g, '')).replace(/,/g, '')).join(','));
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
          <button type="button" className="program-btn" onClick={buildExecutiveSummaryHtml}>
            Generate PDF
          </button>
          <button type="button" className="program-btn primary" onClick={exportConsolidated}>
            Export to Excel / CSV
          </button>
        </div>
      </div>

      {error && <div className="program-banner error" role="alert">{error}</div>}

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Executive summary</div>
            <div className="program-card-sub">
              Year to date through the current month — uses the same backend numbers as the dashboard.
            </div>
          </div>
          <div className="program-tabs" aria-label="Report scope">
            <button
              type="button"
              className={`program-tab${scope === 'consolidated' ? ' active' : ''}`}
              onClick={() => setScope('consolidated')}
            >
              All projects (consolidated)
            </button>
            <button
              type="button"
              className={`program-tab${scope === 'single' ? ' active' : ''}`}
              onClick={() => setScope('single')}
            >
              Per project
            </button>
          </div>
        </div>

        {scope === 'consolidated' ? (
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
                    <td className="r num">{formatMoney(row.budget ?? 0)}</td>
                    <td className="r num">{formatMoney(row.spent ?? 0)}</td>
                    <td className="r num">{formatMoney(row.remaining ?? 0)}</td>
                    <td className="r num">{formatPct(row.spent_pct ?? 0)}</td>
                    <td><span className={`program-pill ${row.health === 'Healthy' ? 'healthy' : row.health === 'Watch' ? 'watch' : row.health === 'At risk' ? 'risk' : 'neutral'}`}>{row.health}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          selected && (
            <div className="program-grid-2">
              <section className="program-kpi">
                <div className="program-kpi-label">Approved budget</div>
                <div className="program-kpi-value">{formatMoney(selected.budget ?? 0)}</div>
                <div className="program-kpi-sub">Project {selected.code}</div>
              </section>
              <section className="program-kpi">
                <div className="program-kpi-label">Spent</div>
                <div className="program-kpi-value">{formatMoney(selected.spent ?? 0)}</div>
                <div className="program-kpi-sub">{formatPct(selected.spent_pct)} of budget</div>
              </section>
              <section className="program-kpi">
                <div className="program-kpi-label">Remaining</div>
                <div className="program-kpi-value">{formatMoney(selected.remaining ?? 0)}</div>
                <div className="program-kpi-sub">Unspent approved budget</div>
              </section>
              <section className="program-kpi">
                <div className="program-kpi-label">Receipts</div>
                <div className="program-kpi-value">
                  {selected.expenses_count > 0 && selected.receipt_compliance_pct != null
                    ? formatPct(selected.receipt_compliance_pct)
                    : '—'}
                </div>
                <div className="program-kpi-sub">
                  {selected.receipts_count ?? 0} of {selected.expenses_count ?? 0} expenses have receipts
                </div>
              </section>
            </div>
          )
        )}

        <div className="program-card-head" style={{ marginTop: 18 }}>
          <div>
            <div className="program-card-title">Executive summary numbers</div>
            <div className="program-card-sub">Budget, disbursement, utilization and health at a glance.</div>
          </div>
        </div>
        <div className="program-grid-2">
          <div className="program-kpi">
            <div className="program-kpi-label">Portfolio budget</div>
            <div className="program-kpi-value">{formatMoney(kpis.portfolio_budget ?? 0)}</div>
            <div className="program-kpi-sub">{kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0} pending approvals</div>
          </div>
          <div className="program-kpi">
            <div className="program-kpi-label">Total disbursed</div>
            <div className="program-kpi-value">{formatMoney(kpis.total_disbursed ?? 0)}</div>
            <div className="program-kpi-sub">{formatPct(kpis.utilization_pct ?? 0)} of portfolio</div>
          </div>
        </div>
      </section>

      {loading && <div className="program-card program-empty">Loading reports…</div>}
    </>
  );
}
