import { formatPct, peso } from '../money';

export default function ProgramKpis({ kpis }) {
  const health = kpis?.health ?? {};

  return (
    <div className="pd-kpi-grid">
      <div className="panel pd-kpi brand">
        <span className="pd-kpi-label">Portfolio budget</span>
        <b className="pd-kpi-value">{peso(kpis?.portfolio_budget ?? 0)}</b>
        <span className="pd-kpi-sub">Across all projects and funding sources</span>
      </div>

      <div className="panel pd-kpi">
        <span className="pd-kpi-label">Total disbursed</span>
        <b className="pd-kpi-value">{peso(kpis?.total_disbursed ?? 0)}</b>
        <span className="pd-kpi-sub">{formatPct(kpis?.utilization_pct ?? 0)} of the portfolio used</span>
      </div>

      <div className={`panel pd-kpi ${health.at_risk ? 'over' : 'ok'}`}>
        <span className="pd-kpi-label">At-risk projects</span>
        <b className="pd-kpi-value">{health.at_risk ?? 0}</b>
        <span className="pd-kpi-sub">
          {health.watch ?? 0} watch · {health.healthy ?? 0} healthy
        </span>
      </div>

      <div className="panel pd-kpi">
        <span className="pd-kpi-label">Avg receipt compliance</span>
        <b className="pd-kpi-value">{formatPct(kpis?.avg_receipt_compliance ?? 0)}</b>
        <span className="pd-kpi-sub">Expenses with a digitized receipt on file</span>
      </div>
    </div>
  );
}
