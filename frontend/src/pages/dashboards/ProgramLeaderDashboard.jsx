import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useProgramRefresh } from '../../layouts/ProgramLayout';
import { fetchProgramOverview, decideApproval } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import ProgramRejectModal from '../../components/program/ProgramRejectModal';
import { formatPct, peso } from '../../components/money';

function compactAmount(value) {
  const amount = Number(value) || 0;
  if (amount >= 1000000) return `₱${(amount / 1000000).toFixed(1)}M`;
  if (amount >= 1000) return `₱${(amount / 1000).toFixed(1)}K`;
  return `₱${amount.toFixed(0)}`;
}

function clampPct(value) {
  return Math.max(0, Math.min(100, Number(value) || 0));
}

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

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

  const kpis = data?.kpis;
  const projects = Array.isArray(data?.projects) ? data.projects : [];
  const approvals = Array.isArray(data?.approvals) ? data.approvals : [];
  const releases = Array.isArray(data?.scheduled_releases)
    ? data.scheduled_releases
    : Array.isArray(data?.forecast)
      ? data.forecast
      : [];
  const health = kpis?.health ?? {};
  const isEmpty = Boolean(data) && projects.length === 0;
  const tallest = Math.max(...releases.map((month) => Number(month.amount) || 0), 1);

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Program overview · Program Leader</div>
          <h1 className="program-h1">Externally funded research portfolio</h1>
          <p className="program-sub">All projects, every funding source, one decision queue.</p>
        </div>

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
          <span className="program-muted" aria-live="polite">
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
        <div className="program-card program-empty">Loading program data…</div>
      )}

      {isEmpty && !loading && (
        <div className="program-card program-empty">
          No projects match the selected funding source.
        </div>
      )}

      {data && !isEmpty && (
        <>
          <div className="program-kpis">
            <section className="program-kpi">
              <div className="program-kpi-label">Portfolio budget</div>
              <div className="program-kpi-value">{peso(kpis?.portfolio_budget ?? 0)}</div>
              <div className="program-kpi-sub">
                {data.meta?.project_count ?? projects.length} projects, all funders
              </div>
            </section>

            <section className="program-kpi">
              <div className="program-kpi-label">Total disbursed</div>
              <div className="program-kpi-value">{peso(kpis?.total_disbursed ?? 0)}</div>
              <div className="program-kpi-sub">
                {formatPct(kpis?.utilization_pct ?? 0)} of portfolio
              </div>
            </section>

            <section className="program-kpi">
              <div className="program-kpi-label">Project health</div>
              <div className="program-kpi-pills">
                <ProgramPill value="Healthy" label={`${health.healthy ?? 0} Healthy`} />
                <ProgramPill value="Watch" label={`${health.watch ?? 0} Watch`} />
                <ProgramPill value="At risk" label={`${health.at_risk ?? 0} At risk`} />
              </div>
              <div className="program-kpi-sub">{health.closed ?? 0} project(s) closed</div>
            </section>

            <section className="program-kpi">
              <div className="program-kpi-label">Pending approvals</div>
              <div className="program-kpi-value">{kpis?.pending_approvals_total ?? kpis?.pending_approvals ?? 0}</div>
              <div className="program-kpi-sub">
                Oldest waiting {kpis?.oldest_waiting_days ?? 0} days
              </div>
            </section>
          </div>

          <div className="program-grid-2">
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
                approvals.map((approval) => {
                  const isOwn = Number(approval.requested_by_id) === Number(user?.id);
                  const busy = busyApprovalId === approval.id;
                  return (
                    <div className="program-row" key={approval.id}>
                      <div className="program-row-main">
                        <div className="program-row-title">
                          <span>{approval.title}</span>
                          <ProgramPill value={approval.type_label} />
                        </div>
                        <div className="program-row-sub">
                          {approval.project}
                          {approval.amount != null ? ` · ${peso(approval.amount)}` : ''}
                          {` · waiting ${approval.days_waiting ?? 0} days`}
                          {approval.requested_by ? ` · requested by ${approval.requested_by_role ?? approval.requested_by}` : ''}
                        </div>
                        {isOwn && (
                          <div className="program-warning" style={{ marginTop: 6 }}>
                            ⚠ You cannot approve a request you created
                          </div>
                        )}
                      </div>
                      <div className="program-row-actions">
                        <button
                          type="button"
                          className="program-btn primary"
                          disabled={busy || isOwn}
                          aria-disabled={busy || isOwn}
                          onClick={() => handleApprove(approval)}
                        >
                          {busy ? '…' : 'Approve'}
                        </button>
                        <button
                          type="button"
                          className="program-btn"
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
                })
              )}
            </section>

            <section className="program-card">
              <div className="program-card-head">
                <div>
                  <div className="program-card-title">Scheduled releases</div>
                  <div className="program-card-sub">Planned tranches, next four months</div>
                </div>
              </div>

              <div className="program-bars">
                {releases.map((month) => {
                  const amount = Number(month.amount) || 0;
                  return (
                    <div className="program-bar-col" key={month.month}>
                      <span className="program-bar-amount">
                        {amount > 0 ? compactAmount(amount) : 'None'}
                      </span>
                      <div
                        className={`program-bar${amount > 0 ? '' : ' none'}`}
                        style={amount > 0 ? { height: `${Math.max(4, (amount / tallest) * 120)}px` } : undefined}
                        aria-hidden="true"
                      />
                      <span className="program-bar-month">{month.label}</span>
                    </div>
                  );
                })}
              </div>
              <p className="program-footnote">Overdue tranches count in the current month.</p>
            </section>
          </div>

          <section className="program-card">
            <div className="program-card-head">
              <div>
                <div className="program-card-title">Project health matrix</div>
                <div className="program-card-sub">Spending against time elapsed, tranche, receipts</div>
              </div>
            </div>

            <div className="program-scroll">
              <table className="program-table" style={{ minWidth: 900 }}>
                <thead>
                  <tr>
                    <th>Project</th>
                    <th>Funder</th>
                    <th>Spent and time elapsed</th>
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
                        <div className="project-code">{project.code}</div>
                      </td>
                      <td>{project.funder}</td>
                      <td>
                        <div className="program-bar-stack">
                          <div>
                            <div className="program-label-row">
                              <span>Spent {formatPct(project.spent_pct)}</span>
                            </div>
                            <div className="program-progress">
                              <span style={{ width: `${clampPct(project.spent_pct)}%` }} />
                            </div>
                          </div>
                          <div>
                            <div className="program-label-row">
                              <span>Time {formatPct(project.time_pct)}</span>
                            </div>
                            <div className="program-progress time">
                              <span style={{ width: `${clampPct(project.time_pct)}%` }} />
                            </div>
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
                        <div className="program-muted" style={{ marginTop: 4 }}>
                          {project.health_reason}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
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
