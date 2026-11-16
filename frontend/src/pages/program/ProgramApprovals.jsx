import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useProgramRefresh } from '../../layouts/ProgramLayout';
import { decideApproval, fetchProgramApprovals } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import { peso } from '../../components/money';
import { formatDate } from '../../lib/datetime';

const TYPES = [
  ['all', 'All types'],
  ['fund-release', 'Fund release'],
  ['realignment', 'Realignment'],
  ['liquidation', 'Liquidation'],
  ['payroll', 'Payroll'],
];

const TABS = [
  ['pending', 'Pending'],
  ['approved', 'Approved'],
  ['rejected', 'Rejected'],
];

const EMPTY = {
  pending: 'All caught up. No pending approvals.',
  approved: 'No approved requests yet.',
  rejected: 'No rejected requests.',
};

const PAGE_SIZE = 20;

const TYPE_PILL = {
  disbursement: 'Fund release',
  // Payroll steps escalated to the Program Leader (self-pay / no eligible verifier).
  payroll: 'Payroll',
  budget_realignment: 'Realignment',
  liquidation: 'Liquidation',
};

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

function RemarkBox({ text }) {
  const value = typeof text === 'string' ? text.trim() : '';
  return value
    ? <p className="pa-remark">“{value}”</p>
    : <p className="pa-remark is-empty">No remark</p>;
}

function DecidedRow({ approval }) {
  const approved = approval.status === 'approved';
  return (
    <li className="pa-decided-row">
      <div className="pa-decided-main">
        <div className="pa-decided-title">
          <span className={`pa-status ${approved ? 'is-approved' : 'is-rejected'}`}>
            <span aria-hidden="true">{approved ? '✓' : '✕'}</span>
            {approved ? 'Approved' : 'Rejected'}
          </span>
          <b>{approval.title}</b>
          <span className="pa-type">{TYPE_PILL[approval.type] ?? approval.type_label}</span>
        </div>
        <div className="pa-meta">
          {approval.project ?? 'Unknown project'}
          {approval.amount != null && <> · <b>{peso(approval.amount)}</b></>}
          {' · requested by '}{approval.requested_by ?? 'Unknown user'}
          {' on '}{formatDate(approval.created_at)}
        </div>
        <div className="pa-meta">
          Decided by {approval.reviewed_by ?? 'Unknown user'} on {formatDate(approval.reviewed_at)}
        </div>
        <RemarkBox text={approval.review_notes} />
      </div>
      {approval.project_id != null && (
        <Link className="pa-audit-link" to={`/program-audit?project=${approval.project_id}`}>
          View in audit trail
        </Link>
      )}
    </li>
  );
}

// The parent renders this with a key built from the tab and filters, so a
// change remounts it and visibleCount starts again at PAGE_SIZE.
// Placeholder rows built from the real row classes so they take the same
// height as the rows they stand in for.
function ListSkeleton({ status }) {
  const decided = status !== 'pending';
  return (
    <section className="program-card" aria-busy="true" aria-label="Loading approval requests">
      {decided ? (
        <ul className="pa-decided-list" aria-hidden="true">
          {[0, 1, 2].map((index) => (
            <li className="pa-decided-row" key={index}>
              <div className="pa-decided-main">
                <div className="pa-decided-title">
                  <span className="pa-skel" style={{ width: 84, height: 23 }} />
                  <span className="pa-skel" style={{ width: '38%', height: 20 }} />
                </div>
                <div className="pa-meta"><span className="pa-skel" style={{ width: '62%', height: 16 }} /></div>
                <div className="pa-meta"><span className="pa-skel" style={{ width: '34%', height: 16 }} /></div>
                <span className="pa-skel pa-skel-box" />
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <div aria-hidden="true">
          {/* Same wrapper, title, two-line subtitle and justification box as a real pending row. */}
          {[0, 1, 2].map((index) => (
            <div key={index}>
              <div className="program-row">
                <div className="program-row-main">
                  <div className="program-row-title"><span className="pa-skel" style={{ width: '40%', height: 25 }} /></div>
                  <div className="program-row-sub pa-skel-lines">
                    <span className="pa-skel" style={{ width: '70%', height: 16 }} />
                    <span className="pa-skel" style={{ width: '45%', height: 16 }} />
                  </div>
                  <span className="pa-skel pa-skel-box" style={{ height: 62 }} />
                </div>
                <div className="program-row-actions">
                  <span className="pa-skel" style={{ width: 92, height: 40 }} />
                  <span className="pa-skel" style={{ width: 78, height: 40 }} />
                </div>
              </div>
            </div>
          ))}
        </div>
      )}
      <span className="pa-sr-only">Loading approval requests…</span>
    </section>
  );
}

function DecidedList({ approvals }) {
  const [visibleCount, setVisibleCount] = useState(PAGE_SIZE);

  return (
    <section className="program-card">
      <ul className="pa-decided-list">
        {approvals.slice(0, visibleCount).map((approval) => (
          <DecidedRow key={approval.id} approval={approval} />
        ))}
      </ul>
      {approvals.length > visibleCount && (
        <div className="pa-more">
          <button
            type="button"
            className="program-btn"
            onClick={() => setVisibleCount((count) => count + PAGE_SIZE)}
          >
            Load more
          </button>
        </div>
      )}
    </section>
  );
}

export default function ProgramApprovals() {
  const { user } = useAuth();
  const { refreshPending } = useProgramRefresh();
  const [searchParams, setSearchParams] = useSearchParams();

  // Older links use ?status=; ?tab= is the current name.
  const rawTab = searchParams.get('tab') ?? searchParams.get('status');
  const status = TABS.some(([value]) => value === rawTab) ? rawTab : 'pending';
  const rawType = searchParams.get('type');
  const type = TYPES.some(([value]) => value === rawType) ? rawType : 'all';
  const project = searchParams.get('project') ?? '';
  const funder = searchParams.get('funder') ?? '';
  const hasFilters = type !== 'all' || project !== '' || funder !== '';

  // Each response is stored with the tab + filter combination it was
  // requested for, so rows from another tab can never render here.
  const requestKey = `${status}|${type}|${project}|${funder}`;
  const [result, setResult] = useState(null); // { key, payload }
  const [failure, setFailure] = useState(null); // { key, message }
  const controllerRef = useRef(null);
  const [feedback, setFeedback] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [rejectId, setRejectId] = useState(null);
  const [rejectReason, setRejectReason] = useState('');
  const [rejectError, setRejectError] = useState('');
  const [rejectBusy, setRejectBusy] = useState(false);

  function updateQuery(changes) {
    const next = new URLSearchParams(searchParams);
    next.delete('status');
    if (!next.has('tab') && status !== 'pending') next.set('tab', status);
    Object.entries(changes).forEach(([key, value]) => {
      const isDefault = value === '' || value == null
        || (key === 'type' && value === 'all')
        || (key === 'tab' && value === 'pending');
      if (isDefault) next.delete(key);
      else next.set(key, value);
    });
    setSearchParams(next, { replace: true });
  }

  const load = useCallback(async () => {
    // Cancel the previous request; only the latest one may update state.
    controllerRef.current?.abort();
    const controller = new AbortController();
    controllerRef.current = controller;

    try {
      const payload = await fetchProgramApprovals(
        { status, type, project, funder },
        { signal: controller.signal }
      );
      if (controllerRef.current !== controller) return;
      setResult({ key: requestKey, payload });
      setFailure(null);
    } catch (requestError) {
      if (controller.signal.aborted || controllerRef.current !== controller) return;
      setFailure({ key: requestKey, message: messageFor(requestError, 'Unable to load approval requests right now.') });
    }
  }, [status, type, project, funder, requestKey]);

  useEffect(() => {
    load();
    return () => controllerRef.current?.abort();
  }, [load]);

  function retry() {
    setFailure(null);
    load();
  }

  async function refreshAll() {
    await load();
    refreshPending();
  }

  async function handleApprove(approval) {
    if (busyId) return;
    setBusyId(approval.id);
    setFeedback(null);
    try {
      await decideApproval(approval.id, { decision: 'approved' });
      setFeedback({ tone: 'ok', text: `Approved “${approval.title}”. The decision was written to the audit trail.` });
      await refreshAll();
    } catch (requestError) {
      setFeedback({ tone: 'error', text: messageFor(requestError, 'Unable to approve this request.') });
    } finally {
      setBusyId(null);
    }
  }

  function openReject(approval) {
    setRejectId(approval.id);
    setRejectReason('');
    setRejectError('');
  }

  async function confirmReject(approval) {
    if (!rejectReason.trim()) {
      setRejectError('A reason for rejection is required.');
      return;
    }
    if (rejectBusy) return;

    setRejectBusy(true);
    setRejectError('');
    try {
      await decideApproval(approval.id, { decision: 'rejected', remarks: rejectReason.trim() });
      setFeedback({ tone: 'ok', text: `Rejected “${approval.title}”. The reason was recorded.` });
      setRejectId(null);
      setRejectReason('');
      await refreshAll();
    } catch (requestError) {
      const validationError = requestError?.response?.data?.errors?.remarks?.[0];
      setRejectError(validationError || messageFor(requestError, 'Unable to reject this request.'));
    } finally {
      setRejectBusy(false);
    }
  }

  const payload = result?.payload;
  const isCurrent = result?.key === requestKey;
  const error = failure?.key === requestKey ? failure.message : '';
  const loading = !isCurrent && !error;
  // Counts and filter options keep their last known values while a new
  // request is in flight, so they never flicker to 0.
  const counts = payload?.counts ?? null;
  const projects = Array.isArray(payload?.projects) ? payload.projects : [];
  const funders = Array.isArray(payload?.funders) ? payload.funders : [];
  // Safety net: only rows of the selected tab's status are ever rendered.
  const approvals = isCurrent && Array.isArray(payload?.approvals)
    ? payload.approvals.filter((approval) => approval.status === status)
    : [];

  const emptyState = (
    <section className="program-card pa-empty">
      <span>{EMPTY[status]}</span>
      {hasFilters && (
        <button
          type="button"
          className="pp-clear"
          onClick={() => updateQuery({ type: '', project: '', funder: '' })}
        >
          Clear filters
        </button>
      )}
    </section>
  );

  return (
    <>
      <div className="program-page-head">
        <div>
          <div className="program-eyebrow">Approvals · Program Leader</div>
          <h1 className="program-h1">Approval requests</h1>
          <p className="program-sub">
            Fund releases, realignments, and liquidations from every project.
          </p>
        </div>
      </div>

      <div className="program-filter-row pa-controls">
        <div className="program-tabs pa-tabs" role="tablist" aria-label="Approval status">
          {TABS.map(([value, label]) => (
            <button
              key={value}
              type="button"
              role="tab"
              aria-selected={status === value}
              className={`program-tab${status === value ? ' active' : ''}`}
              onClick={() => updateQuery({ tab: value })}
            >
              {label}{counts ? ` ${counts[value] ?? 0}` : ''}
            </button>
          ))}
        </div>

        <span className="spacer" />

        <div className="program-field">
          <label htmlFor="approval-type">Type</label>
          <select
            id="approval-type"
            className="program-select"
            value={type}
            onChange={(event) => updateQuery({ type: event.target.value })}
          >
            {TYPES.map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </div>

        <div className="program-field">
          <label htmlFor="approval-project">Project</label>
          <select
            id="approval-project"
            className="program-select"
            value={project}
            onChange={(event) => updateQuery({ project: event.target.value })}
          >
            <option value="">All projects</option>
            {projects.map((item) => (
              <option key={item.id} value={item.id}>{item.title}</option>
            ))}
          </select>
        </div>

        <div className="program-field">
          <label htmlFor="approval-funder">Funder</label>
          <select
            id="approval-funder"
            className="program-select"
            value={funder}
            onChange={(event) => updateQuery({ funder: event.target.value })}
          >
            <option value="">All funders</option>
            {funders.map((agency) => (
              <option key={agency.id} value={agency.id}>{agency.label}</option>
            ))}
          </select>
        </div>
      </div>

      {error && (
        <div className="program-banner error" role="alert">
          {error}{' '}
          <button type="button" className="program-btn sm" onClick={retry}>Retry</button>
        </div>
      )}

      {feedback && (
        <div className={`program-banner ${feedback.tone}`} role="status" aria-live="polite">
          {feedback.text}
        </div>
      )}

      {loading ? (
        <ListSkeleton status={status} />
      ) : error ? null : approvals.length === 0 ? (
        emptyState
      ) : status === 'pending' ? (
        <section className="program-card">
          {approvals.map((approval) => {
            const isOwn = Number(approval.requested_by_id) === Number(user?.id);
            const busy = busyId === approval.id;
            const rejecting = rejectId === approval.id;

            return (
              <div key={approval.id}>
                <div className="program-row">
                  <div className="program-row-main">
                    <div className="program-row-title">
                      <span>{approval.title}</span>
                      <ProgramPill value={approval.type_label} />
                    </div>
                    <div className="program-row-sub">
                      {approval.project}
                      {approval.amount != null ? ` · ${peso(approval.amount)}` : ''}
                      {` · waiting ${approval.days_waiting ?? 0} days`}
                      {approval.requested_by ? ` · requested by ${approval.requested_by_role ?? 'user'}` : ''}
                      {approval.requested_by ? ` (${approval.requested_by})` : ''}
                    </div>
                    {approval.justification?.trim() && (
                      <p className="pa-remark">“{approval.justification.trim()}”</p>
                    )}
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
                      onClick={() => openReject(approval)}
                    >
                      Reject
                    </button>
                  </div>
                </div>

                {rejecting && (
                  <div className="program-reject">
                    <div className="program-field">
                      <label htmlFor={`reject-${approval.id}`}>Reason for rejection (required)</label>
                      <textarea
                        id={`reject-${approval.id}`}
                        className="program-textarea"
                        value={rejectReason}
                        onChange={(event) => setRejectReason(event.target.value)}
                        placeholder="Tell the Project Leader what to fix"
                      />
                    </div>
                    {rejectError && (
                      <p className="program-error" aria-live="polite">{rejectError}</p>
                    )}
                    <div className="program-reject-actions">
                      <button
                        type="button"
                        className="program-btn"
                        onClick={() => setRejectId(null)}
                        disabled={rejectBusy}
                      >
                        Cancel
                      </button>
                      <button
                        type="button"
                        className="program-btn primary"
                        onClick={() => confirmReject(approval)}
                        disabled={rejectBusy}
                      >
                        {rejectBusy ? 'Rejecting…' : 'Confirm rejection'}
                      </button>
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </section>
      ) : (
        // Keyed so "Load more" resets to 20 rows when the tab or filters change.
        <DecidedList key={`${status}|${type}|${project}|${funder}`} approvals={approvals} />
      )}
    </>
  );
}
