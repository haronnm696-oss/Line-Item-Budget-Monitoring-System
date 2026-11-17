import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { formatDate, formatInManila } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import ProgramPill from '../../components/program/ProgramPill';
import MonthlyExpenseTrend from '../../components/program/MonthlyExpenseTrend';
import PsMooeCard from '../../components/program/PsMooeCard';
import ProjectSelect from '../../components/leader/ProjectSelect';
import ReviewRow from '../../components/leader/ReviewRow';
import { useReviewActions } from '../../components/leader/useReviewActions';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import { formatPct, peso } from '../../components/money';
import { REQUEST_STATUS } from '../../lib/budgetRequestForm';

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

function greeting() {
  const hour = Number(formatInManila(new Date(), { hour: 'numeric', hourCycle: 'h23' }, '12'));
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

const BURN_PILL = {
  ok: ['healthy', '✓'],
  warn: ['watch', '⚠'],
  over: ['risk', '✕'],
  neutral: ['neutral', '•'],
};


/** Project Leader Overview: one project's budget, review queue, alerts and requests (descriptive only). */
export default function ProjectLeaderDashboard() {
  const { user } = useAuth();
  const picker = useAssignedProject({ role: 'project-leader' });
  const url = picker.projectId ? ENDPOINTS.leaderOverview(picker.projectId) : null;
  const overview = useApiData(url);
  const { retry } = overview;
  const reload = useCallback(async () => retry(), [retry]);
  const actions = useReviewActions(reload);
  const [recording, setRecording] = useState(false);

  const data = overview.data;
  const firstName = (user?.name ?? 'there').replace(/^Dr\.?\s+/i, '').split(' ')[0];
  const projectQuery = picker.projectId ? `?project=${picker.projectId}` : '';

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="pl-greeting">{greeting()}, {firstName}</div>
          <h1 className="program-h1">{data?.project?.title ?? picker.project?.title ?? 'Project budget'}</h1>
          {data && <ProjectMeta project={data.project} />}
        </div>
        <div className="pl-head-actions">
          <ProjectSelect picker={picker} id="overview-project" />
          {/* With no project led, these stay hidden; the page shows notAssignedText instead. */}
          {picker.projectId && (
            <>
              <Link className="program-btn" to={`/reports${projectQuery}`}>Generate report</Link>
              <button type="button" className="program-btn" onClick={() => { actions.setFeedback(null); setRecording(true); }}>
                Record transaction
              </button>
              <Link className="program-btn primary" to={`/my-requests${projectQuery}${projectQuery ? '&' : '?'}new=fund_release`}>
                Submit budget request
              </Link>
            </>
          )}
        </div>
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={overview} loadingText="Loading project data…">
          {data && (
            <>
              <Kpis kpis={data.kpis} />

              <div className="pl-row pl-row-main">
                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Submissions to review</div>
                      <div className="program-card-sub">Expenses, payroll and purchase requests, oldest first</div>
                    </div>
                    <Link className="program-card-link" to={`/submissions${projectQuery}`}>View all</Link>
                  </div>
                  {data.review.length === 0 ? (
                    <p className="ld-empty">Nothing is waiting for you.</p>
                  ) : (
                    data.review.map((item) => (
                      <ReviewRow
                        key={item.key}
                        item={item}
                        openHref={`/submissions?project=${picker.projectId}&open=${item.key}`}
                      />
                    ))
                  )}
                  {data.kpis.to_review > data.review.length && (
                    <Link className="ld-more" to={`/submissions${projectQuery}`}>
                      {data.kpis.to_review - data.review.length} more waiting
                    </Link>
                  )}
                </section>

                <BudgetAlerts alerts={data.alerts} projectQuery={projectQuery} />
              </div>

              <div className="pl-row pl-row-trend">
                <MonthlyExpenseTrend months={data.monthly_expenses} />
                <PsMooeCard
                  summary={data.category_summary}
                  action={<Link className="program-card-link" to={`/budget${projectQuery}`}>View every line item</Link>}
                />
              </div>

              <div className="pl-row pl-row-charts">
                <TransactionSummary rows={data.transactions} projectQuery={projectQuery} />
                <RequestSummary requests={data.requests} nextTranche={data.alerts.next_tranche} projectQuery={projectQuery} />
              </div>
            </>
          )}
        </LoadState>
      </LoadState>

      {actions.dialog}

      {recording && picker.projectId && (
        <RecordExpenseDialog
          projectId={picker.projectId}
          onClose={() => setRecording(false)}
          onDone={(text) => {
            setRecording(false);
            actions.setFeedback({ tone: 'ok', text });
            retry();
          }}
        />
      )}
    </div>
  );
}

function ProjectMeta({ project }) {
  const period = project.start_date && project.end_date
    ? `${formatDate(project.start_date, '—', { month: 'short', year: 'numeric' })} – ${formatDate(project.end_date, '—', { month: 'short', year: 'numeric' })}`
    : null;
  return (
    <p className="program-sub">
      {[project.code, project.funder, project.status, period].filter(Boolean).join(' · ')}
    </p>
  );
}

function Kpis({ kpis }) {
  const utilization = Number(kpis.utilization_pct) || 0;
  const budget = Number(kpis.total_budget) || 0;
  const remaining = Number(kpis.remaining) || 0;
  const waitingOnOthers = kpis.to_review - kpis.to_review_actionable;

  return (
    <div className="pl-kpi-grid">
      <section className="program-card pl-kpi pl-kpi-hero">
        <div className="pl-kpi-head"><span className="pl-kpi-label">Total budget</span></div>
        <div className="pl-kpi-value"><AnimatedNumber value={budget} format={peso} /></div>
        <div className="pl-kpi-sub">
          Funder {peso(kpis.funder_share)} + MSU–Main Campus counterpart {peso(kpis.counterpart)}
        </div>
        <div className="pl-track pl-track-gold"><span style={{ width: `${clampPct(utilization)}%` }} /></div>
        <div className="pl-kpi-foot">{formatPct(utilization)} spent</div>
      </section>

      <section className="program-card pl-kpi">
        <div className="pl-kpi-head"><span className="pl-kpi-label">Total spent</span></div>
        <div className="pl-kpi-value"><AnimatedNumber value={kpis.total_spent} format={peso} /></div>
        <div className="pl-kpi-sub">{formatPct(utilization)} of the total budget · verified expenses</div>
        <div className="pl-track"><span style={{ width: `${clampPct(utilization)}%` }} /></div>
      </section>

      <section className="program-card pl-kpi">
        <div className="pl-kpi-head"><span className="pl-kpi-label">Remaining balance</span></div>
        <div className="pl-kpi-value"><AnimatedNumber value={remaining} format={peso} /></div>
        <div className="pl-kpi-sub">{formatPct(budget > 0 ? (remaining / budget) * 100 : 0)} still available</div>
      </section>

      <section className="program-card pl-kpi">
        <div className="pl-kpi-head"><span className="pl-kpi-label">To review</span></div>
        <div className="pl-kpi-value"><AnimatedNumber value={kpis.to_review} /></div>
        <div className="pl-kpi-sub">
          {kpis.to_review_actionable} need your decision
          {waitingOnOthers > 0 ? ` · ${waitingOnOthers} waiting for someone else` : ''}
        </div>
      </section>
    </div>
  );
}

function AlertRow({ title, pill, children }) {
  return (
    <div className="ld-alert">
      <div className="ld-alert-top">
        <b>{title}</b>
        {pill}
      </div>
      {children}
    </div>
  );
}

function BudgetAlerts({ alerts, projectQuery }) {
  const burn = alerts.burn_rate;
  const [tone, icon] = BURN_PILL[burn.tone] ?? BURN_PILL.neutral;
  const next = alerts.next_tranche;
  // With zero tranches, "no next tranche" must not read as "all released".
  const noTranches = Number(alerts.tranche_count) === 0;
  const missing = alerts.missing_receipts;

  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Budget alerts</div>
          <div className="program-card-sub">Spending, tranches and receipts</div>
        </div>
      </div>

      <AlertRow title="Spending against time" pill={<ProgramPill value={burn.status_label} tone={tone} icon={icon} />}>
        <div className="pl-bar-line">
          <span className="pl-bar-label">Spent</span>
          <div className="pl-track"><span className={burn.spent_pct > burn.elapsed_pct ? 'is-warn' : ''} style={{ width: `${clampPct(burn.spent_pct)}%` }} /></div>
          <b>{formatPct(burn.spent_pct)}</b>
        </div>
        <div className="pl-bar-line is-time">
          <span className="pl-bar-label">Time</span>
          <div className="pl-track"><span style={{ width: `${clampPct(burn.elapsed_pct)}%` }} /></div>
          <b>{formatPct(burn.elapsed_pct)}</b>
        </div>
        <p className="ld-muted">{burn.message}</p>
      </AlertRow>

      <AlertRow
        title="Next tranche"
        pill={noTranches ? <ProgramPill value="Not set" label="No tranches set yet" tone="neutral" icon="•" />
          : !next ? <ProgramPill value="All released" tone="healthy" icon="✓" />
          : next.display_status === 'overdue' ? <ProgramPill value="Overdue" />
          : next.requested ? <ProgramPill value="Pending" label="Release requested" />
          : <ProgramPill value="Not yet requested" />}
      >
        {next ? (
          <p className="ld-muted">
            {next.name} · {peso(next.amount)} · planned {formatDate(next.planned_release_date)}
            {!next.requested && (
              <> · <Link to={`/my-requests${projectQuery}${projectQuery ? '&' : '?'}new=fund_release&tranche=${next.id}`}>Request release</Link></>
            )}
          </p>
        ) : (
          <p className="ld-muted">{noTranches ? 'No tranches set yet. The Program Leader schedules them on the project page.' : 'Every tranche of this project is released.'}</p>
        )}
      </AlertRow>

      <AlertRow
        title="Verified expenses without a receipt"
        pill={missing.count > 0
          ? <ProgramPill value="Missing" label={`${missing.count} missing`} tone="risk" icon="!" />
          : <ProgramPill value="None" label="None missing" tone="healthy" icon="✓" />}
      >
        {missing.count > 0 ? (
          <ul className="ld-alert-list">
            {missing.items.map((item) => (
              <li key={item.id}>
                <span>{item.description}</span>
                <b>{peso(item.amount)}</b>
              </li>
            ))}
          </ul>
        ) : (
          <p className="ld-muted">Every verified expense has a receipt document.</p>
        )}
      </AlertRow>
    </section>
  );
}

function TransactionSummary({ rows, projectQuery }) {
  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Transaction summary</div>
          <div className="program-card-sub">Latest verified expenses</div>
        </div>
        <Link className="program-card-link" to={`/transactions${projectQuery}`}>View transaction history</Link>
      </div>
      {rows.length === 0 ? (
        <p className="ld-empty">No verified expenses yet.</p>
      ) : (
        <ul className="ld-mini-list">
          {rows.map((row) => (
            <li key={row.id}>
              <div>
                <b>{row.description}</b>
                <span>{formatDate(row.date)} · {row.line_item ?? '—'}</span>
              </div>
              <b className="ld-amount">{peso(row.amount)}</b>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function RequestSummary({ requests, nextTranche, projectQuery }) {
  const showTranche = nextTranche && !nextTranche.requested;

  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">My requests</div>
          <div className="program-card-sub">Budget requests to the Program Leader</div>
        </div>
        <Link className="program-card-link" to={`/my-requests${projectQuery}`}>View all</Link>
      </div>
      {requests.length === 0 && !showTranche ? (
        <p className="ld-empty">No budget requests yet.</p>
      ) : (
        <ul className="ld-mini-list">
          {showTranche && (
            <li>
              <div>
                <b>Fund release: {nextTranche.name}</b>
                <span>{peso(nextTranche.amount)} · planned {formatDate(nextTranche.planned_release_date)}</span>
              </div>
              <div className="ld-mini-side">
                <ProgramPill value="Not yet requested" />
                <Link className="program-btn sm" to={`/my-requests${projectQuery}${projectQuery ? '&' : '?'}new=fund_release&tranche=${nextTranche.id}`}>
                  Request release
                </Link>
              </div>
            </li>
          )}
          {requests.map((request) => (
            <li key={request.id}>
              <div>
                <b>{request.title}</b>
                <span>
                  {request.amount != null ? `${peso(request.amount)} · ` : ''}
                  {request.status === 'pending'
                    ? `sent ${formatDate(request.created_at)}`
                    : `${request.reviewed_by ?? 'Program Leader'} · ${formatDate(request.reviewed_at)}`}
                </span>
                {request.review_notes && <span className="ld-remark">“{request.review_notes}”</span>}
              </div>
              <div className="ld-mini-side">
                <ProgramPill value={REQUEST_STATUS[request.status] ?? request.status} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
