import { Link } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { formatDate } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import ProgramPill from '../../components/program/ProgramPill';
import MonthlyExpenseTrend from '../../components/program/MonthlyExpenseTrend';
import PsMooeCard from '../../components/program/PsMooeCard';
import ReviewRow from '../../components/leader/ReviewRow';
import { formatPct, peso } from '../../components/money';
import { REQUEST_STATUS } from '../../lib/budgetRequestForm';
import { spendingAlert } from '../../lib/leaderOverview';
import { NO_ACTIVE_PROJECT_TEXT, conflictWarning } from '../../lib/leaderRule';

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

/** Project Leader Overview: one project's budget, review queue, alerts and requests (descriptive only). */
export default function ProjectLeaderDashboard() {
  const picker = useAssignedProject({ role: 'project-leader' });
  const url = picker.projectId ? ENDPOINTS.leaderOverview(picker.projectId) : null;
  const overview = useApiData(url);

  const data = overview.data;
  const projectQuery = picker.projectId ? `?project=${picker.projectId}` : '';

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <h1 className="program-h1">{data?.project?.title ?? picker.project?.title ?? 'Project budget'}</h1>
          {data && <ProjectMeta project={data.project} />}
        </div>
      </div>

      {/* Older data: more than one active project led. Nothing changes until the Program Leader reassigns. */}
      {picker.others?.length > 0 && (
        <div className="program-banner warn" role="status">
          <p><span aria-hidden="true">⚠ </span>{conflictWarning(picker.others)}</p>
          <ul className="pl-other-projects">
            {picker.others.map((other) => (
              <li key={other.id}><Link to={`/dashboard/project-leader?project=${other.id}`}>{other.title} ({other.code})</Link></li>
            ))}
          </ul>
        </div>
      )}

      <LoadState
        state={picker.state}
        emptyText={picker.past?.length ? NO_ACTIVE_PROJECT_TEXT : notAssignedText(picker.role)}
        isEmpty={picker.state.status === 'ready' && !picker.project}
      >
        <LoadState state={overview} loadingText="Loading project data…">
          {data && (
            <>
              <Kpis kpis={data.kpis} funder={data.project?.funder} agency={data.project?.implementing_agency} />

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

      {picker.past?.length > 0 && <PastProjects projects={picker.past} />}
    </div>
  );
}

/** Completed projects the leader led: read-only, each opens the project page. */
function PastProjects({ projects }) {
  return (
    <section className="program-card pl-past" aria-labelledby="pl-past-title">
      <div className="program-card-head">
        <div>
          <div className="program-card-title" id="pl-past-title">Past projects</div>
          <div className="program-card-sub">Completed projects you led · view only</div>
        </div>
      </div>
      <ul className="ld-mini-list">
        {projects.map((project) => (
          <li key={project.id}>
            <div>
              <Link to={`/projects/${project.id}`}><b>{project.title}</b></Link>
              <span>{[project.code, project.agency, project.period].filter(Boolean).join(' · ')}</span>
            </div>
            <div className="ld-mini-side"><ProgramPill value="Completed" label="Completed" tone="neutral" icon="✓" /></div>
          </li>
        ))}
      </ul>
    </section>
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

function Kpis({ kpis, funder, agency }) {
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
          {funder || 'Funder'} {peso(kpis.funder_share)} + {agency || 'Implementing agency'} counterpart {peso(kpis.counterpart)}
        </div>
        <div className="pl-track pl-track-gold"><span style={{ width: `${clampPct(utilization)}%` }} /></div>
        <div className="pl-kpi-foot">{formatPct(utilization)} spent</div>
      </section>

      <section className="program-card pl-kpi">
        <div className="pl-kpi-head"><span className="pl-kpi-label">Total spent</span></div>
        <div className="pl-kpi-value"><AnimatedNumber value={kpis.total_spent} format={peso} /></div>
        <div className="pl-kpi-sub">{formatPct(utilization)} of the total budget · verified expenses and verified payroll</div>
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
  const spending = spendingAlert(alerts);
  const next = alerts.next_tranche;
  // With zero releases, "no next release" must not read as "all released".
  const noTranches = Number(alerts.tranche_count) === 0;
  const missing = alerts.missing_receipts;

  return (
    <section className="program-card">
      <div className="program-card-head">
        <div>
          <div className="program-card-title">Budget alerts</div>
          <div className="program-card-sub">Spending, releases and receipts</div>
        </div>
      </div>

      {spending.setUp ? (
        <AlertRow title="Spending against time" pill={<ProgramPill value={spending.label} tone={spending.tone} icon={spending.icon} />}>
          <div className="pl-bar-line">
            <span className="pl-bar-label">Spent</span>
            <div className="pl-track"><span className={spending.spentPct > spending.elapsedPct ? 'is-warn' : ''} style={{ width: `${clampPct(spending.spentPct)}%` }} /></div>
            <b>{formatPct(spending.spentPct)}</b>
          </div>
          <div className="pl-bar-line is-time">
            <span className="pl-bar-label">Time</span>
            <div className="pl-track"><span style={{ width: `${clampPct(spending.elapsedPct)}%` }} /></div>
            <b>{formatPct(spending.elapsedPct)}</b>
          </div>
          <p className="ld-muted">{spending.message}</p>
        </AlertRow>
      ) : (
        // No line items: no Under-spending / At-risk verdict, only who sets them up.
        <AlertRow title="Spending against time" pill={<ProgramPill value="Not set" label="No line items yet" tone="neutral" icon="•" />}>
          <p className="ld-muted">{spending.message}</p>
        </AlertRow>
      )}

      <AlertRow
        title="Next release"
        pill={noTranches ? <ProgramPill value="Not set" label="No releases set yet" tone="neutral" icon="•" />
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
          <p className="ld-muted">{noTranches ? 'No releases set yet. The Program Leader schedules them on the project page.' : 'All released. Every release of this project has been paid out.'}</p>
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
          <div className="program-card-sub">Latest verified expenses and payroll</div>
        </div>
        <Link className="program-card-link" to={`/transactions${projectQuery}`}>View transaction history</Link>
      </div>
      {rows.length === 0 ? (
        <p className="ld-empty">No verified expenses or payroll yet.</p>
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
                <b>{nextTranche.name}</b>
                <span><ProgramPill value="Fund release" /> {peso(nextTranche.amount)} · planned {formatDate(nextTranche.planned_release_date)}</span>
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
                  {/* The type badge names the kind; a fund release title is only "Release 2". */}
                  <ProgramPill value={request.type_label} />{' '}
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
