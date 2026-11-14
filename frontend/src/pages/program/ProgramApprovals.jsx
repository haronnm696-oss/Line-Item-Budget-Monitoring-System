import { useCallback, useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useProgramRefresh } from '../../layouts/ProgramLayout';
import { decideApproval, fetchProgramApprovals } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import { peso } from '../../components/money';

const TYPES = [
  ['all', 'All types'],
  ['fund-release', 'Fund release'],
  ['realignment', 'Realignment'],
  ['liquidation', 'Liquidation'],
  ['budget-change', 'Budget change'],
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
  payroll: 'Fund release',
  budget_realignment: 'Realignment',
  liquidation: 'Liquidation',
};

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

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

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
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
    setLoading(true);
    setError('');
    try {
      const payload = await fetchProgramApprovals({ status, type, project, funder });
      setData(payload);
    } catch (requestError) {
      setError(messageFor(requestError, 'Unable to load approval requests right now.'));
    } finally {
      setLoading(false);
    }
  }, [status, type, project, funder]);

  useEffect(() => {
    load();
  }, [load]);

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

  const counts = data?.counts ?? {};
  const approvals = Array.isArray(data?.approvals) ? data.approvals : [];
  const projects = Array.isArray(data?.projects) ? data.projects : [];
  const funders = Array.isArray(data?.funders) ? data.funders : [];

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
              {label} {counts[value] ?? 0}
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
          <button type="button" className="program-btn sm" onClick={load}>Retry</button>
        </div>
      )}

      {feedback && (
        <div className={`program-banner ${feedback.tone}`} role="status" aria-live="polite">
          {feedback.text}
        </div>
      )}

      {loading && !data ? (
        <section className="program-card pa-empty">Loading approval requests…</section>
      ) : approvals.length === 0 ? (
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
