import Panel from '../Panel';
import Chip from '../Chip';
import { formatPct, peso } from '../money';

const HEALTH_TONE = { Healthy: 'ok', Watch: 'warn', 'At risk': 'over' };
const TRANCHE_TONE = { 'On track': 'ok', 'Final pending': 'warn', Overdue: 'over' };
const REPORT_TONE = { 'On time': 'ok', 'Due soon': 'warn', Late: 'over' };

export default function ProjectHealthMatrix({ projects }) {
  const items = Array.isArray(projects) ? projects : [];

  return (
    <div className="pd-section">
      <Panel
        title="Project health matrix"
        hint="Health rules are computed by the backend across every project"
        action={<Chip tone="neutral">{items.length} projects</Chip>}
      >
        {items.length === 0 ? (
          <div className="empty-state">No projects match the selected funding source.</div>
        ) : (
          <div className="wide-scroll">
            <table className="pd-matrix">
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Health</th>
                  <th className="r">Approved budget</th>
                  <th className="r">Spent vs budget</th>
                  <th className="r">Time elapsed</th>
                  <th>Tranche</th>
                  <th>Reporting</th>
                  <th className="r">Receipts</th>
                </tr>
              </thead>
              <tbody>
                {items.map((project) => (
                  <tr key={project.id}>
                    <td>
                      <div className="pd-project-cell">
                        <b>{project.title}</b>
                        <span>{project.code} · {project.funder}</span>
                      </div>
                    </td>
                    <td>
                      <Chip tone={HEALTH_TONE[project.health] ?? 'neutral'}>{project.health}</Chip>
                      <span className="pd-reason">{project.health_reason}</span>
                    </td>
                    <td className="r num">{peso(project.budget)}</td>
                    <td className="r">
                      <b className="num">{formatPct(project.spent_pct)}</b>
                      <div className="pd-mini-track" aria-hidden="true">
                        <span
                          className={`pd-mini-fill ${HEALTH_TONE[project.health] ?? 'neutral'}`}
                          style={{ width: `${Math.max(0, Math.min(100, Number(project.spent_pct) || 0))}%` }}
                        />
                      </div>
                      <span className="pd-muted num">{peso(project.spent)} spent</span>
                    </td>
                    <td className="r">
                      <b className="num">{formatPct(project.time_pct)}</b>
                      <span className="pd-muted num">{project.elapsed_days} / {project.total_days} days</span>
                    </td>
                    <td>
                      <Chip tone={TRANCHE_TONE[project.tranche_status] ?? 'neutral'}>
                        {project.tranche_status}
                      </Chip>
                    </td>
                    <td>
                      <Chip tone={REPORT_TONE[project.report_status] ?? 'neutral'}>
                        {project.report_status}
                      </Chip>
                    </td>
                    <td className="r">
                      <b className="num">
                        {project.expenses_count > 0 && project.receipt_compliance_pct != null
                          ? formatPct(project.receipt_compliance_pct)
                          : '—'}
                      </b>
                      <span className="pd-muted num">
                        {project.receipts_count} / {project.expenses_count} expenses
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </div>
  );
}
