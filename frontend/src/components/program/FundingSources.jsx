import Panel from '../Panel';
import { formatPct, peso } from '../money';

export default function FundingSources({ sources }) {
  const items = Array.isArray(sources) ? sources : [];

  return (
    <Panel
      title="Funding sources"
      hint="Spent vs allocated per funder"
      style={{ height: '100%' }}
    >
      {items.length === 0 ? (
        <div className="empty-state">No funding sources to show.</div>
      ) : (
        <div className="pd-fund-list">
          {items.map((source) => (
            <div className="pd-fund-row" key={source.funder_id ?? source.funder}>
              <div className="pd-fund-top">
                <b>{source.funder}</b>
                <span>{source.projects} project{source.projects === 1 ? '' : 's'}</span>
              </div>
              <div className="pd-fund-bar" aria-hidden="true">
                <span
                  style={{
                    width: `${Math.max(0, Math.min(100, Number(source.utilization_pct) || 0))}%`,
                  }}
                />
              </div>
              <div className="pd-fund-meta">
                <span>{peso(source.spent)} / {peso(source.allocated)}</span>
                <b>{formatPct(source.utilization_pct)}</b>
              </div>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}
