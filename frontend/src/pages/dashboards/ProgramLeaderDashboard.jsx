import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useProgramRefresh } from '../../layouts/ProgramLayout';
import { fetchProgramOverview, decideApproval } from '../../api/program';
import AnimatedNumber from '../../components/program/AnimatedNumber';
import ProgramPill from '../../components/program/ProgramPill';
import ProgramRejectModal from '../../components/program/ProgramRejectModal';
import BudgetByProjectDonut from '../../components/program/BudgetByProjectDonut';
import ScheduledReleasesBars from '../../components/program/ScheduledReleasesBars';
import { compactPeso, formatPct, peso } from '../../components/money';

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

function greeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

function shortDate(iso) {
  const date = new Date(`${iso}T00:00:00`);
  if (Number.isNaN(date.getTime())) return { month: '', day: '' };
  return {
    month: date.toLocaleDateString('en-PH', { month: 'short' }).toUpperCase(),
    day: String(date.getDate()).padStart(2, '0'),
  };
}

function approvalGlyph(approval) {
  if (approval.kind === 'liquidation') return '▤';
  if (approval.kind === 'realignment') return '⇄';
  if (approval.kind === 'tranche') return '₱';
  return '•';
}

const stroke = {
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
};

const KPI_ICONS = {
  budget: (
    <svg viewBox="0 0 24 24" {...stroke} aria-hidden="true">
      <rect x="3" y="6" width="18" height="12" rx="2.5" />
      <circle cx="12" cy="12" r="2.4" />
      <path d="M6.5 12h.01M17.5 12h.01" />
    </svg>
  ),
  disbursed: (
    <svg viewBox="0 0 24 24" {...stroke} aria-hidden="true">
      <circle cx="12" cy="12" r="8.5" />
      <path d="M9 15l6-6M10 9h5v5" />
    </svg>
  ),
  health: (
    <svg viewBox="0 0 24 24" {...stroke} aria-hidden="true">
      <path d="M3 12h4l2.5-6 4 12 2.5-6H21" />
    </svg>
  ),
  approvals: (
    <svg viewBox="0 0 24 24" {...stroke} aria-hidden="true">
      <circle cx="12" cy="12" r="8.5" />
      <path d="M12 7.5V12l3 2" />
    </svg>
  ),
};

export default function ProgramLeaderDashboard() {
  const { user } = useAuth();
  const { refreshPending } = useProgramRefresh();
  const [data, setData] = useState(null);
  const [funders, setFunders] = useState([]);
  const [funder, setFunder] = useState('');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState(null);
  const [busyApprovalId, setBusyApprovalId] = useState(null);
  const [rejectTarget, setRejectTarget] = useState(null);
  const [rejectBusy, setRejectBusy] = useState(false);
  const [rejectError, setRejectError] = useState('');
  const requestRef = useRef(0);

  const load = useCallback(async (selectedFunder, quiet = false) => {
    const requestId = ++requestRef.current;

    if (quiet) {
      setRefreshing(true);
    } else {
      setLoading(true);
      setData(null);
    }
    setError('');

    try {
      const payload = await fetchProgramOverview(selectedFunder);
      if (requestId !== requestRef.current) return;

      setData(payload);
      if (Array.isArray(payload.funders)) setFunders(payload.funders);
    } catch (requestError) {
      if (requestId !== requestRef.current) return;
      setError(messageFor(requestError, 'Unable to load the program dashboard right now.'));
    } finally {
      if (requestId === requestRef.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, []);

  useEffect(() => {
    load(funder);
  }, [funder, load]);

  async function refreshAll() {
    await load(funder, true);
    refreshPending();
  }

  async function handleApprove(approval) {
    if (busyApprovalId) return;

    setBusyApprovalId(approval.id);
    setFeedback(null);

    try {
      await decideApproval(approval.id, { decision: 'approved' });
      setFeedback({ tone: 'ok', text: `Approved “${approval.title}”.` });
      await refreshAll();
    } catch (requestError) {
      setFeedback({
        tone: 'error',
        text: messageFor(requestError, 'Unable to approve this request. Please try again.'),
      });
    } finally {
      setBusyApprovalId(null);
    }
  }

  async function submitReject(reason) {
    if (!rejectTarget || rejectBusy) return;

    setRejectBusy(true);
    setRejectError('');

    try {
      await decideApproval(rejectTarget.id, { decision: 'rejected', remarks: reason });
      setFeedback({ tone: 'ok', text: `Rejected “${rejectTarget.title}”.` });
      setRejectTarget(null);
      await refreshAll();
    } catch (requestError) {
      const validationError = requestError?.response?.data?.errors?.remarks?.[0];
      setRejectError(
        validationError || messageFor(requestError, 'Unable to reject this request. Please try again.')
      );
    } finally {
      setRejectBusy(false);
    }
  }

  const kpis = data?.kpis ?? {};
  const projects = Array.isArray(data?.projects) ? data.projects : [];
  const approvals = Array.isArray(data?.approvals) ? data.approvals : [];
  const releases = Array.isArray(data?.scheduled_releases)
    ? data.scheduled_releases
    : Array.isArray(data?.forecast)
      ? data.forecast
      : [];
  const fundingSources = Array.isArray(data?.funding_sources) ? data.funding_sources : [];
  const deadlines = Array.isArray(data?.deadlines) ? data.deadlines : [];

  const health = kpis.health ?? {};
  const healthyCount = Number(health.healthy) || 0;
  const watchCount = Number(health.watch) || 0;
  const riskCount = Number(health.at_risk) || 0;
  const healthTotal = healthyCount + watchCount + riskCount;
  const healthPct = (value) => (healthTotal ? (value / healthTotal) * 100 : 0);
  const budget = Number(kpis.portfolio_budget) || 0;
  const disbursed = Number(kpis.total_disbursed) || 0;
  const utilization = Number(kpis.utilization_pct) || 0;
  const remaining = budget - disbursed;
  const pending = kpis.pending_approvals_total ?? kpis.pending_approvals ?? 0;
  const compliance = kpis.avg_receipt_compliance;

  const expenseCount = projects.reduce((sum, project) => sum + (Number(project.expenses_count) || 0), 0);
  const receiptCount = projects.reduce((sum, project) => sum + (Number(project.receipts_count) || 0), 0);
  const projectCount = data?.meta?.project_count ?? projects.length;

  const projectRows = [...projects]
    .sort((a, b) => (Number(b.budget) || 0) - (Number(a.budget) || 0))
    .slice(0, 6);
  const firstName = (user?.name ?? 'there').replace(/^Dr\.?\s+/i, '').split(' ')[0];
  const isEmpty = Boolean(data) && projects.length === 0;

  return (
    <>
      <div className="pl-head">
        <div>
          <div className="pl-greeting">{greeting()}, {firstName}</div>
          <h1 className="program-h1">Research portfolio</h1>
          <p className="program-sub">
            {projectCount} project{projectCount === 1 ? '' : 's'} · {peso(disbursed)} disbursed of{' '}
            {peso(budget)} · {pending} waiting for your approval
          </p>
        </div>

        <div className="pl-head-actions">
          <div className="program-field">
            <label htmlFor="program-funder">Funding source</label>
            <select
              id="program-funder"
              className="program-select"
              value={funder}
              onChange={(event) => {
                setFunder(event.target.value);
                setFeedback(null);
              }}
              disabled={loading && funders.length === 0}
            >
              <option value="">All funders</option>
              {funders.map((agency) => (
                <option value={agency.id} key={agency.id}>
                  {agency.label}
                </option>
              ))}
            </select>
          </div>
          <Link className="program-btn primary" to="/program-approvals">
            Review approvals
            {pending > 0 && <span className="pl-count">{pending}</span>}
          </Link>
          <span className="pl-updated" aria-live="polite">
            {refreshing ? 'Updating…' : 'Live data'}
          </span>
        </div>
      </div>

      {error && (
        <div className="program-banner error" role="alert">
          <span>{error}</span>{' '}
          <button type="button" className="program-btn sm" onClick={() => load(funder)}>
            Retry
          </button>
        </div>
      )}

      {feedback && (
        <div className={`program-banner ${feedback.tone}`} role="status" aria-live="polite">
          {feedback.text}
        </div>
      )}

      {loading && !data && (
        <div className="program-card pl-loading">
          <span className="pd-loading-dot" aria-hidden="true" />
          Loading program data…
        </div>
      )}

      {isEmpty && !loading && (
        <div className="program-card program-empty">
          No projects match the selected funding source.
        </div>
      )}

      {data && !isEmpty && (
        <>
          <div className="pl-kpi-grid">
            <section className="program-card pl-kpi pl-kpi-hero">
              <div className="pl-kpi-head">
                <span className="pl-kpi-ic">{KPI_ICONS.budget}</span>
                <span className="pl-kpi-label">Portfolio budget</span>
              </div>
              <div className="pl-kpi-value">
                <AnimatedNumber value={budget} format={peso} />
              </div>
              <div className="pl-kpi-sub">
                {projectCount} project{projectCount === 1 ? '' : 's'} across all funders
              </div>
              <div className="pl-track pl-track-gold">
                <span style={{ width: `${clampPct(utilization)}%` }} />
              </div>
              <div className="pl-kpi-foot">
                <AnimatedNumber value={utilization} format={formatPct} /> utilized
              </div>
            </section>

            <section className="program-card pl-kpi">
              <div className="pl-kpi-head">
                <span className="pl-kpi-ic">{KPI_ICONS.disbursed}</span>
                <span className="pl-kpi-label">Total disbursed</span>
              </div>
              <div className="pl-kpi-value">
                <AnimatedNumber value={disbursed} format={peso} />
              </div>
              <div className="pl-kpi-sub">
                <AnimatedNumber value={utilization} format={formatPct} /> of the portfolio
              </div>
              <div className="pl-track">
                <span style={{ width: `${clampPct(utilization)}%` }} />
              </div>
              <div className="pl-kpi-foot">
                Remaining <AnimatedNumber value={Math.max(remaining, 0)} format={peso} />
              </div>
            </section>

            <section className="program-card pl-kpi">
              <div className="pl-kpi-head">
                <span className="pl-kpi-ic">{KPI_ICONS.health}</span>
                <span className="pl-kpi-label">Project health</span>
              </div>
              <div className="pl-kpi-value">
                {riskCount}
                <span className="pl-kpi-unit">at risk</span>
              </div>
              <div className="pl-kpi-sub">
                <AnimatedNumber value={healthyCount} /> healthy · <AnimatedNumber value={watchCount} /> on watch
              </div>
              <div className="pl-health-bar" aria-hidden="true">
                <span className="is-healthy" style={{ width: `${healthPct(healthyCount)}%` }} />
                <span className="is-watch" style={{ width: `${healthPct(watchCount)}%` }} />
                <span className="is-risk" style={{ width: `${healthPct(riskCount)}%` }} />
              </div>
              <div className="pl-kpi-foot">
                {health.closed ?? 0} closed · {projects.length} tracked
              </div>
            </section>

            <section className="program-card pl-kpi">
              <div className="pl-kpi-head">
                <span className="pl-kpi-ic">{KPI_ICONS.approvals}</span>
                <span className="pl-kpi-label">Pending approvals</span>
              </div>
              <div className="pl-kpi-value">
                <AnimatedNumber value={pending} />
              </div>
              <div className="pl-kpi-sub">
                Oldest waiting {kpis.oldest_waiting_days ?? 0} day{Number(kpis.oldest_waiting_days) === 1 ? '' : 's'}
              </div>
              <div className="pl-kpi-foot">
                {compliance != null
                  ? `Receipts ${formatPct(compliance)} · ${receiptCount}/${expenseCount} expenses`
                  : `${approvals.length} in the dashboard queue`}
              </div>
            </section>
          </div>

          <div className="pl-row pl-row-main">
            <section className="program-card">
              <div className="program-card-head">
                <div>
                  <div className="program-card-title">Spending against time</div>
                  <div className="program-card-sub">Spent versus elapsed time, largest projects first</div>
                </div>
                <Link className="program-card-link" to="/program-projects">View projects</Link>
              </div>

              <div className="pl-spend-list">
                {projectRows.map((project) => (
                  <div className="pl-spend-row" key={project.id}>
                    <div className="pl-spend-main">
                      <div className="pl-spend-title">
                        <b>{project.title}</b>
                        <span>{project.code}</span>
                      </div>
                      <div className="pl-bar-line">
                        <span className="pl-bar-label">Spent</span>
                        <div className="pl-track">
                          <span
                            className={Number(project.spent_pct) > Number(project.time_pct) ? 'is-warn' : ''}
                            style={{ width: `${clampPct(project.spent_pct)}%` }}
                          />
                        </div>
                        <b>{formatPct(project.spent_pct)}</b>
                      </div>
                      <div className="pl-bar-line is-time">
                        <span className="pl-bar-label">Time</span>
                        <div className="pl-track">
                          <span style={{ width: `${clampPct(project.time_pct)}%` }} />
                        </div>
                        <b>{formatPct(project.time_pct)}</b>
                      </div>
                    </div>
                    <div className="pl-spend-side">
                      <ProgramPill value={project.health} />
                      <span>{compactPeso(project.budget)}</span>
                    </div>
                  </div>
                ))}
              </div>
            </section>
            <div className="pl-row-stack">
              <section className="program-card pl-wallet pl-stretch">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Funding sources</div>
                    <div className="program-card-sub">{fundingSources.length} active funder{fundingSources.length === 1 ? '' : 's'}</div>
                  </div>
                </div>

                <div className="pl-wallet-hero">
                  <span>Ready to disburse</span>
                  <b><AnimatedNumber value={Math.max(remaining, 0)} format={peso} /></b>
                  <div className="pl-track pl-track-gold">
                    <span style={{ width: `${clampPct(utilization)}%` }} />
                  </div>
                  <div className="pl-wallet-split">
                    <div><span>Allocated</span><b><AnimatedNumber value={budget} format={peso} /></b></div>
                    <div><span>Disbursed</span><b><AnimatedNumber value={disbursed} format={peso} /></b></div>
                  </div>
                </div>

                <div className="pl-fund-list">
                  {fundingSources.slice(0, 4).map((source) => (
                    <div className="pl-fund-row" key={source.funder}>
                      <div className="pl-fund-top">
                        <b>{source.funder}</b>
                        <span>{source.projects} project{source.projects === 1 ? '' : 's'}</span>
                      </div>
                      <div className="pl-track">
                        <span style={{ width: `${clampPct(source.utilization_pct)}%` }} />
                      </div>
                      <div className="pl-fund-meta">
                        <span>{formatPct(source.utilization_pct)} utilized</span>
                        <b><AnimatedNumber value={source.allocated} format={compactPeso} /></b>
                      </div>
                    </div>
                  ))}
                </div>

                <Link className="program-btn primary pl-wallet-cta" to="/program-projects">
                  Open portfolio
                </Link>
              </section>
            </div>
          </div>

          <div className="pl-row pl-row-charts">
            <BudgetByProjectDonut projects={projects} />
            <ScheduledReleasesBars releases={releases} />
          </div>

          <div className="pl-layout">
            <div className="pl-column">
              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Approval inbox</div>
                    <div className="program-card-sub">All projects, oldest first</div>
                  </div>
                  <Link className="program-card-link" to="/program-approvals">View all</Link>
                </div>

                {approvals.length === 0 ? (
                  <div className="program-empty">All caught up. No pending approvals.</div>
                ) : (
                  <div className="pl-approval-list">
                    {approvals.map((approval) => {
                      const isOwn = Number(approval.requested_by_id) === Number(user?.id);
                      const busy = busyApprovalId === approval.id;
                      return (
                        <div className="pl-approval" key={approval.id}>
                          <span className="pl-approval-icon" aria-hidden="true">
                            {approvalGlyph(approval)}
                          </span>
                          <div className="pl-approval-main">
                            <div className="pl-approval-title">
                              <b>{approval.title}</b>
                              <ProgramPill value={approval.type_label} />
                            </div>
                            <div className="pl-approval-meta">
                              <span>{approval.project}</span>
                              {approval.amount != null && <span className="amt">{peso(approval.amount)}</span>}
                              <span>{approval.days_waiting ?? 0}d waiting</span>
                              {approval.requested_by && (
                                <span>{approval.requested_by_role ?? approval.requested_by}</span>
                              )}
                            </div>
                            {isOwn && (
                              <div className="program-warning">
                                You cannot approve a request you created
                              </div>
                            )}
                          </div>
                          <div className="pl-approval-actions">
                            <button
                              type="button"
                              className="program-btn sm primary"
                              disabled={busy || isOwn}
                              aria-disabled={busy || isOwn}
                              onClick={() => handleApprove(approval)}
                            >
                              {busy ? '…' : 'Approve'}
                            </button>
                            <button
                              type="button"
                              className="program-btn sm"
                              disabled={busy || isOwn}
                              aria-disabled={busy || isOwn}
                              onClick={() => {
                                setRejectError('');
                                setRejectTarget(approval);
                              }}
                            >
                              Reject
                            </button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </section>

              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Project health matrix</div>
                    <div className="program-card-sub">Spending, tranche, receipts and overall health</div>
                  </div>
                </div>

                <div className="program-scroll">
                  <table className="program-table pl-table" style={{ minWidth: 860 }}>
                    <thead>
                      <tr>
                        <th>Project</th>
                        <th>Spent / time</th>
                        <th>Tranche</th>
                        <th>Receipts</th>
                        <th>Health</th>
                      </tr>
                    </thead>
                    <tbody>
                      {projects.map((project) => (
                        <tr key={project.id}>
                          <td>
                            <div className="project-name">{project.title}</div>
                            <div className="project-code">{project.code} · {project.funder}</div>
                          </td>
                          <td>
                            <div className="pl-mini-bars">
                              <div className="pl-mini-line">
                                <div className="pl-track">
                                  <span style={{ width: `${clampPct(project.spent_pct)}%` }} />
                                </div>
                                <b>{formatPct(project.spent_pct)}</b>
                              </div>
                              <div className="pl-mini-line is-time">
                                <div className="pl-track">
                                  <span style={{ width: `${clampPct(project.time_pct)}%` }} />
                                </div>
                                <b>{formatPct(project.time_pct)}</b>
                              </div>
                            </div>
                          </td>
                          <td><ProgramPill value={project.tranche_status} /></td>
                          <td className="num">
                            {project.expenses_count > 0 && project.receipt_compliance_pct != null
                              ? formatPct(project.receipt_compliance_pct)
                              : '—'}
                          </td>
                          <td>
                            <ProgramPill value={project.health} />
                            <div className="pl-health-reason">{project.health_reason}</div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>
            </div>

            <aside className="pl-rail">
              <section className="program-card">
                <div className="program-card-head">
                  <div>
                    <div className="program-card-title">Coming up</div>
                    <div className="program-card-sub">Reports, liquidations and tranches</div>
                  </div>
                  <Link className="program-card-link" to="/program-audit">Calendar</Link>
                </div>

                {deadlines.length === 0 ? (
                  <div className="program-empty">Nothing due in the near term.</div>
                ) : (
                  <div className="pl-deadline-list">
                    {deadlines.slice(0, 6).map((item) => {
                      const { month, day } = shortDate(item.date);
                      return (
                        <div className="pl-deadline" key={item.id}>
                          <div className={`pl-date ${item.tone}`}>
                            <span>{month}</span>
                            <b>{day}</b>
                          </div>
                          <div className="pl-deadline-main">
                            <b>{item.title}</b>
                            <span>{item.project}</span>
                          </div>
                          <span className={`pl-deadline-status ${item.tone}`}>{item.status}</span>
                        </div>
                      );
                    })}
                  </div>
                )}
              </section>
            </aside>
          </div>
        </>
      )}

      <ProgramRejectModal
        key={rejectTarget?.id ?? 'closed'}
        approval={rejectTarget}
        busy={rejectBusy}
        error={rejectError}
        onClose={() => {
          if (!rejectBusy) setRejectTarget(null);
        }}
        onConfirm={submitReject}
      />
    </>
  );
}
