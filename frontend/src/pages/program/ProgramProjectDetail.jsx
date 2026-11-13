import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { fetchProgramProject } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import { formatPct, peso } from '../../components/money';

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value.length <= 10 ? `${value}T00:00:00` : value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatDateTime(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function actionLabel(action) {
  const text = String(action ?? '').replace(/_/g, ' ');
  return text.charAt(0).toUpperCase() + text.slice(1);
}

const LIQUIDATION_TONE = { Approved: 'healthy', Pending: 'watch', Rejected: 'risk', 'Not submitted': 'watch' };

export default function ProgramProjectDetail() {
  const { id } = useParams();
  const location = useLocation();
  const backTo = location.state?.from?.startsWith('/program-projects') ? location.state.from : '/program-projects';
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let alive = true;
    setLoading(true);
    setError('');
    fetchProgramProject(id)
      .then((payload) => {
        if (alive) setData(payload);
      })
      .catch((requestError) => {
        if (!alive) return;
        setError(requestError?.response?.status === 404
          ? 'This project does not exist or was removed.'
          : requestError?.response?.data?.message || 'Unable to load this project.');
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => { alive = false; };
  }, [id]);

  const backLink = (
    <Link className="program-card-link pd-back" to={backTo}>← Back to projects</Link>
  );

  if (loading && !data) {
    return (
      <>
        {backLink}
        <div className="program-card pl-loading">Loading project…</div>
      </>
    );
  }

  if (error || !data) {
    return (
      <>
        {backLink}
        <div className="program-banner error" role="alert">{error || 'Unable to load this project.'}</div>
      </>
    );
  }

  const project = data.project;
  const groups = data.line_items?.groups ?? [];
  const total = data.line_items?.total;
  const tranches = data.tranches ?? [];
  const expenses = data.expenses ?? [];
  const team = data.team ?? [];
  const activity = data.activity ?? [];

  return (
    <>
      {backLink}

      <div className="program-page-head">
        <div className="pd-head">
          <div className="program-eyebrow">Project · {project.code}</div>
          <h1 className="program-h1">{project.title}</h1>
          <p className="program-sub">
            {project.funder}
            {project.funder_reference ? ` · ref. ${project.funder_reference}` : ''}
            {' · '}Project Leader: {project.project_leader || '—'}
            {' · '}{formatDate(project.start_date)} – {formatDate(project.end_date)}
          </p>
          <div className="pd-pills">
            <ProgramPill value={project.status} />
            <ProgramPill value={project.health} />
            <span className="pd-reason">{project.health_reason}</span>
          </div>
        </div>
        <div className="program-head-actions">
          <Link className="program-btn" to={`/program-approvals?project=${project.id}`}>View approvals</Link>
          <Link className="program-btn primary" to={`/program-reports?project=${encodeURIComponent(project.code)}`}>
            Export Form B
          </Link>
        </div>
      </div>

      <div className="pl-kpi-grid">
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Total budget</div>
          <div className="pl-kpi-value pd-kpi-value">{peso(project.budget)}</div>
        </section>
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Spent</div>
          <div className="pl-kpi-value pd-kpi-value">{peso(project.spent)}</div>
          <div className="pl-kpi-sub">{formatPct(project.spent_pct)} of the budget</div>
        </section>
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Remaining</div>
          <div className="pl-kpi-value pd-kpi-value">{peso(project.remaining)}</div>
        </section>
        <section className="program-card pl-kpi">
          <div className="pl-kpi-label">Time elapsed</div>
          <div className="pl-kpi-value pd-kpi-value">{formatPct(project.time_pct)}</div>
          <div className="pl-kpi-sub">{project.elapsed_days} of {project.total_days} days</div>
        </section>
      </div>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Line-item budget</div>
            <div className="program-card-sub">Allocated versus verified spending, grouped by PS and MOOE</div>
          </div>
        </div>
        {groups.length === 0 ? (
          <p className="pl-inbox-empty">No line items recorded yet.</p>
        ) : (
          <div className="program-scroll">
            <table className="program-table pd-table">
              <thead>
                <tr>
                  <th>Line item</th>
                  <th className="num">Allocated</th>
                  <th className="num">Spent</th>
                  <th className="num">Remaining</th>
                  <th className="num">% used</th>
                </tr>
              </thead>
              <tbody>
                {groups.map((group) => (
                  <GroupRows key={group.type} group={group} />
                ))}
              </tbody>
              {total && (
                <tfoot>
                  <tr className="pd-total">
                    <td>Grand total</td>
                    <td className="num">{peso(total.allocated)}</td>
                    <td className="num">{peso(total.spent)}</td>
                    <td className="num">{peso(total.remaining)}</td>
                    <td className="num">{formatPct(total.utilization_pct)}</td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        )}
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Tranches</div>
            <div className="program-card-sub">Planned releases and their liquidation</div>
          </div>
        </div>
        {tranches.length === 0 ? (
          <p className="pl-inbox-empty">No tranches scheduled.</p>
        ) : (
          <div className="program-scroll">
            <table className="program-table pd-table">
              <thead>
                <tr>
                  <th>No.</th>
                  <th>Planned date</th>
                  <th className="num">Amount</th>
                  <th>Status</th>
                  <th>Liquidation</th>
                </tr>
              </thead>
              <tbody>
                {tranches.map((tranche) => (
                  <tr key={tranche.id}>
                    <td>
                      <div className="project-name">Tranche {tranche.sequence}</div>
                      <div className="project-code">{tranche.name}</div>
                    </td>
                    <td>{formatDate(tranche.planned_release_date)}</td>
                    <td className="num">{peso(tranche.amount)}</td>
                    <td>
                      <ProgramPill
                        value={tranche.status}
                        tone={tranche.status === 'Released' ? 'healthy' : tranche.status === 'Overdue' ? 'risk' : 'neutral'}
                        icon={tranche.status === 'Released' ? '✓' : tranche.status === 'Overdue' ? '✕' : '•'}
                      />
                    </td>
                    <td>
                      {tranche.liquidation_status ? (
                        <ProgramPill
                          value={tranche.liquidation_status}
                          tone={LIQUIDATION_TONE[tranche.liquidation_status] ?? 'neutral'}
                          icon={tranche.liquidation_status === 'Not submitted' ? '⚠' : undefined}
                        />
                      ) : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Recent verified expenses</div>
            <div className="program-card-sub">Latest 10 verified records</div>
          </div>
        </div>
        {expenses.length === 0 ? (
          <p className="pl-inbox-empty">No verified expenses yet.</p>
        ) : (
          <div className="program-scroll">
            <table className="program-table pd-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Line item</th>
                  <th>Payee</th>
                  <th className="num">Amount</th>
                  <th>Reference</th>
                </tr>
              </thead>
              <tbody>
                {expenses.map((expense) => (
                  <tr key={expense.id}>
                    <td>{formatDate(expense.date)}</td>
                    <td>
                      <div>{expense.line_item || '—'}</div>
                      <div className="project-code">{expense.description}</div>
                    </td>
                    <td>{expense.payee || '—'}</td>
                    <td className="num">{peso(expense.amount)}</td>
                    <td>{expense.reference_no || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <div className="pl-row pl-row-charts">
        <section className="program-card">
          <div className="program-card-head">
            <div>
              <div className="program-card-title">Team</div>
              <div className="program-card-sub">Roles on this project</div>
            </div>
            <Link className="program-card-link" to="/program-users">Users and roles</Link>
          </div>
          {team.length === 0 ? (
            <p className="pl-inbox-empty">No one is assigned yet.</p>
          ) : (
            <ul className="pd-list">
              {team.map((member) => (
                <li key={`${member.user_id}-${member.role_slug}`}>
                  <b>{member.name}</b>
                  <span>{member.role}</span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section className="program-card">
          <div className="program-card-head">
            <div>
              <div className="program-card-title">Recent activity</div>
              <div className="program-card-sub">Latest 10 audit-trail entries</div>
            </div>
            <Link className="program-card-link" to="/program-audit">Audit trail</Link>
          </div>
          {activity.length === 0 ? (
            <p className="pl-inbox-empty">No recorded activity yet.</p>
          ) : (
            <ul className="pd-list">
              {activity.map((entry) => (
                <li key={entry.id}>
                  <b>
                    {actionLabel(entry.action)} · {entry.record_type}
                    {entry.summary ? ` — ${entry.summary}` : ''}
                  </b>
                  <span>
                    {entry.user} · {formatDateTime(entry.created_at)}
                    {entry.reason ? ` · ${entry.reason}` : ''}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </>
  );
}

function GroupRows({ group }) {
  return (
    <>
      <tr className="pd-group">
        <td colSpan={5}>{group.label}</td>
      </tr>
      {group.items.map((item) => (
        <tr key={item.id}>
          <td>
            <div>{item.name}</div>
            {item.code && <div className="project-code">{item.code}</div>}
          </td>
          <td className="num">{peso(item.allocated)}</td>
          <td className="num">{peso(item.spent)}</td>
          <td className="num">{peso(item.remaining)}</td>
          <td className="num">
            {formatPct(item.utilization_pct)}
            {item.over_budget && (
              <div><ProgramPill value="Over budget" tone="risk" icon="✕" /></div>
            )}
          </td>
        </tr>
      ))}
      <tr className="pd-subtotal">
        <td>{group.type} subtotal</td>
        <td className="num">{peso(group.subtotal.allocated)}</td>
        <td className="num">{peso(group.subtotal.spent)}</td>
        <td className="num">{peso(group.subtotal.remaining)}</td>
        <td className="num">{formatPct(group.subtotal.utilization_pct)}</td>
      </tr>
    </>
  );
}
