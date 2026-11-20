import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { fetchProgramOverview } from '../../api/program';
import { formatPct, peso } from '../../components/money';
import { formatInManila } from '../../lib/datetime';
import ProgramPill from '../../components/program/ProgramPill';

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

function formatGenerated(date) {
  return formatInManila(date, {
    month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

/**
 * Printable executive report (/program-reports/executive?funder=).
 * Uses the Overview payload as is: no numbers are computed here except the
 * same fallbacks the Overview uses when a KPI field is missing.
 */
export default function ProgramExecutiveReport() {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const funder = searchParams.get('funder') ?? '';
  const autoPrint = searchParams.get('print') === '1';
  const requestKey = funder;

  const [result, setResult] = useState(null); // { key, payload, generatedAt }
  const [failure, setFailure] = useState(null); // { key, message }
  const printedRef = useRef(false);

  useEffect(() => {
    let alive = true;
    fetchProgramOverview(funder)
      .then((payload) => {
        if (alive) setResult({ key: requestKey, payload, generatedAt: new Date() });
      })
      .catch((error) => {
        if (alive) setFailure({ key: requestKey, message: messageFor(error, 'Unable to load the executive report.') });
      });
    return () => { alive = false; };
  }, [funder, requestKey]);

  const ready = result?.key === requestKey;
  const error = failure?.key === requestKey ? failure.message : '';

  // "Download PDF" on the Reports page opens this view with ?print=1.
  // Print once, after the report has rendered, then drop the flag so a
  // reload does not print again.
  useEffect(() => {
    if (!autoPrint || !ready || printedRef.current) return;
    printedRef.current = true;
    const timer = window.setTimeout(() => {
      window.print();
      const next = new URLSearchParams(searchParams);
      next.delete('print');
      setSearchParams(next, { replace: true });
    }, 0);
    return () => window.clearTimeout(timer);
  }, [autoPrint, ready, searchParams, setSearchParams]);

  const payload = ready ? result.payload : null;
  const kpis = payload?.kpis ?? {};
  const projects = Array.isArray(payload?.projects) ? payload.projects : [];
  const rows = [...projects].sort((a, b) => (Number(b.budget) || 0) - (Number(a.budget) || 0));
  const funders = Array.isArray(payload?.funders) ? payload.funders : [];
  const funderLabel = funder
    ? (funders.find((agency) => String(agency.id) === String(funder))?.label ?? `Funder #${funder}`)
    : 'All funders';

  // Same fields and fallbacks as the Overview KPI cards.
  const budget = Number(kpis.portfolio_budget) || 0;
  const spent = Number(kpis.total_spent ?? kpis.total_disbursed) || 0;
  const remaining = kpis.remaining != null ? Number(kpis.remaining) : budget - spent;
  const pending = Number(kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0) || 0;
  const health = kpis.health ?? {};

  const summary = [
    ['Total budget', peso(budget)],
    [`Total spent (${formatPct(kpis.utilization_pct ?? 0)})`, peso(spent)],
    ['Remaining balance', peso(remaining)],
    ['Pending approvals', String(pending)],
    ['Project health', `${health.healthy ?? 0} healthy · ${health.watch ?? 0} watch · ${health.at_risk ?? 0} at risk · ${health.closed ?? 0} closed`],
    ['Average receipt compliance', kpis.avg_receipt_compliance != null ? formatPct(kpis.avg_receipt_compliance) : '—'],
  ];

  return (
    <>
      <div className="er-toolbar no-print">
        <Link className="pa-audit-link" to="/program-reports">← Back to reports</Link>
        <span className="spacer" />
        <button
          type="button"
          className="program-btn primary"
          onClick={() => window.print()}
          disabled={!ready}
        >
          Print / Save as PDF
        </button>
      </div>

      {error && (
        <div className="program-banner error no-print" role="alert">{error}</div>
      )}

      {!ready && !error && (
        <section className="program-card pa-empty" aria-busy="true">Preparing the executive report…</section>
      )}

      {ready && (
        <article className="program-card er-doc" aria-labelledby="er-doc-title">
          <header className="er-head">
            <div className="er-org">LIB Monitoring System · MSU–Main Campus</div>
            <h1 className="er-title" id="er-doc-title">Executive report</h1>
            <dl className="er-meta">
              <div><dt>Funding source</dt><dd>{funderLabel}</dd></div>
              <div><dt>Generated</dt><dd>{formatGenerated(result.generatedAt)} by {user?.name ?? 'Unknown user'}</dd></div>
            </dl>
          </header>

          <section className="er-section" aria-labelledby="er-summary-title">
            <h2 className="er-h2" id="er-summary-title">Summary</h2>
            <table className="er-table er-summary">
              <tbody>
                {summary.map(([label, value]) => (
                  <tr key={label}>
                    <th scope="row">{label}</th>
                    <td className="r num">{value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>

          <section className="er-section" aria-labelledby="er-projects-title">
            <h2 className="er-h2" id="er-projects-title">All projects</h2>
            {rows.length === 0 ? (
              <p className="er-empty">No projects match this funding source.</p>
            ) : (
              <div className="er-scroll">
                <table className="er-table">
                  <thead>
                    <tr>
                      <th scope="col">Project</th>
                      <th scope="col">Code</th>
                      <th scope="col">Funder</th>
                      <th scope="col">Project leader</th>
                      <th scope="col" className="r">Approved budget</th>
                      <th scope="col" className="r">Spent</th>
                      <th scope="col" className="r">Remaining</th>
                      <th scope="col" className="r">Utilization</th>
                      <th scope="col">Health</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => (
                      <tr key={row.id}>
                        <td>{row.title}</td>
                        <td className="er-code">{row.code}</td>
                        <td>{row.funder || '—'}</td>
                        <td>{row.project_leader || '—'}</td>
                        <td className="r num">{peso(row.budget ?? 0)}</td>
                        <td className="r num">{peso(row.spent ?? 0)}</td>
                        <td className="r num">{peso(row.remaining ?? 0)}</td>
                        <td className="r num">{formatPct(row.spent_pct ?? 0)}</td>
                        <td><ProgramPill value={row.health} /></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </article>
      )}
    </>
  );
}
