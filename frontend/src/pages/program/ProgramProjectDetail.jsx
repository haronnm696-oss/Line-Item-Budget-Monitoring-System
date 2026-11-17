import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { fetchProgramProject } from '../../api/program';
import { ENDPOINTS } from '../../api/endpoints';
import ProgramPill from '../../components/program/ProgramPill';
import { LineItemDialog, ProjectFormDialog, RemoveDialog, TrancheDialog } from '../../components/program/ProjectSetup';
import { formatPct, peso } from '../../components/money';
import { formatDate, formatDateTime } from '../../lib/datetime';


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
  // Set-up dialogs: { kind: 'details' | 'line' | 'removeLine' | 'tranche' | 'removeTranche', item? }
  const [dialog, setDialog] = useState(null);
  const [notice, setNotice] = useState('');
  const [reloadKey, setReloadKey] = useState(0);

  function saved(message) {
    setDialog(null);
    setNotice(message);
    setReloadKey((value) => value + 1);
  }

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
  }, [id, reloadKey]);

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
  const setup = data.setup ?? null;
  const left = setup ? setup.total_budget - setup.allocated : 0;

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
          {setup && (
            <button type="button" className="program-btn" onClick={() => { setNotice(''); setDialog({ kind: 'details' }); }}>Edit details</button>
          )}
          <Link className="program-btn" to={`/program-approvals?project=${project.id}`}>View approvals</Link>
          <Link className="program-btn primary" to={`/program-reports?project=${encodeURIComponent(project.code)}`}>
            Export Form B
          </Link>
        </div>
      </div>

      <div aria-live="polite">
        {notice && <div className="program-banner ok">{notice}</div>}
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
          {setup && (
            <button type="button" className="program-btn" onClick={() => { setNotice(''); setDialog({ kind: 'line' }); }}>Add line item</button>
          )}
        </div>
        {setup && (
          <p className={`ld-hint${left < 0 ? ' is-risk' : ''}`} role="status">
            Line items: {peso(setup.allocated)} of the {peso(setup.total_budget)} total budget
            {left >= 0 ? ` · ${peso(left)} left to allocate` : ` · ⚠ ${peso(-left)} over the total budget`}
            {setup.allocations_locked ? ' · Verified expenses exist: allocated amounts change only through a realignment request.' : ''}
          </p>
        )}
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
                  {setup && <th><span className="pa-sr-only">Actions</span></th>}
                </tr>
              </thead>
              <tbody>
                {groups.map((group) => (
                  <GroupRows
                    key={group.type}
                    group={group}
                    setup={setup}
                    onEdit={(item) => { setNotice(''); setDialog({ kind: 'line', item: { ...item, type: group.type } }); }}
                    onRemove={(item) => { setNotice(''); setDialog({ kind: 'removeLine', item }); }}
                  />
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
                    {setup && <td />}
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
            <div className="program-card-sub">Planned releases and their liquidation. Releasing goes through the fund release approval.</div>
          </div>
          {setup && (
            <button type="button" className="program-btn" onClick={() => { setNotice(''); setDialog({ kind: 'tranche' }); }}>Add tranche</button>
          )}
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
                  {setup && <th><span className="pa-sr-only">Actions</span></th>}
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
                    {setup && (
                      <td className="pd-actions">
                        {setup.editable_tranches.includes(tranche.id) ? (
                          <>
                            <button type="button" className="program-btn sm" onClick={() => { setNotice(''); setDialog({ kind: 'tranche', item: tranche }); }}>
                              Edit<span className="pa-sr-only"> tranche {tranche.sequence}</span>
                            </button>
                            <button type="button" className="program-btn sm danger" onClick={() => { setNotice(''); setDialog({ kind: 'removeTranche', item: tranche }); }}>
                              Remove<span className="pa-sr-only"> tranche {tranche.sequence}</span>
                            </button>
                          </>
                        ) : (
                          <span className="project-code">{tranche.status === 'Released' ? '🔒 Released' : '🔒 Release requested'}</span>
                        )}
                      </td>
                    )}
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
            <Link className="program-card-link" to={`/program-audit?project=${project.id}`}>Audit trail</Link>
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

      {setup && dialog?.kind === 'details' && (
        <ProjectFormDialog mode="edit" projectId={project.id} setup={setup} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {setup && dialog?.kind === 'line' && (
        <LineItemDialog projectId={project.id} item={dialog.item ?? null} setup={setup} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {setup && dialog?.kind === 'tranche' && (
        <TrancheDialog projectId={project.id} tranche={dialog.item ?? null} setup={setup} onClose={() => setDialog(null)} onSaved={saved} />
      )}
      {dialog?.kind === 'removeLine' && (
        <RemoveDialog
          title="Remove line item"
          text={`Remove "${dialog.item.name}" (${peso(dialog.item.allocated)}) from the Line-Item Budget?`}
          url={ENDPOINTS.programLineItem(dialog.item.id)}
          onClose={() => setDialog(null)}
          onSaved={saved}
        />
      )}
      {dialog?.kind === 'removeTranche' && (
        <RemoveDialog
          title="Remove tranche"
          text={`Remove ${dialog.item.name} (${peso(dialog.item.amount)}, planned ${formatDate(dialog.item.planned_release_date)})?`}
          url={ENDPOINTS.programTranche(dialog.item.id)}
          onClose={() => setDialog(null)}
          onSaved={saved}
        />
      )}
    </>
  );
}

function GroupRows({ group, setup, onEdit, onRemove }) {
  return (
    <>
      <tr className="pd-group">
        <td colSpan={setup ? 6 : 5}>{group.label}</td>
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
          {setup && (
            <td className="pd-actions">
              <button type="button" className="program-btn sm" onClick={() => onEdit(item)}>
                Edit<span className="pa-sr-only"> {item.name}</span>
              </button>
              {setup.used_line_items.includes(item.id) ? (
                <span className="project-code" title="It has expenses, payroll or purchase requests">🔒 In use</span>
              ) : (
                <button type="button" className="program-btn sm danger" onClick={() => onRemove(item)}>
                  Remove<span className="pa-sr-only"> {item.name}</span>
                </button>
              )}
            </td>
          )}
        </tr>
      ))}
      <tr className="pd-subtotal">
        <td>{group.type} subtotal</td>
        <td className="num">{peso(group.subtotal.allocated)}</td>
        <td className="num">{peso(group.subtotal.spent)}</td>
        <td className="num">{peso(group.subtotal.remaining)}</td>
        <td className="num">{formatPct(group.subtotal.utilization_pct)}</td>
        {setup && <td />}
      </tr>
    </>
  );
}
