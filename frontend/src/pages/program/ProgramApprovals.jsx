import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
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

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}

function messageFor(error, fallback) {
  return error?.response?.data?.message || fallback;
}

export default function ProgramApprovals() {
  const { user } = useAuth();
  const { refreshPending } = useProgramRefresh();
  const [searchParams] = useSearchParams();
  const [status, setStatus] = useState(() => (
    ['pending', 'approved', 'rejected'].includes(searchParams.get('status')) ? searchParams.get('status') : 'pending'
  ));
  const [type, setType] = useState('all');
  const [project, setProject] = useState(() => searchParams.get('project') ?? '');
  const [funder, setFunder] = useState('');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [feedback, setFeedback] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [rejectId, setRejectId] = useState(null);
  const [rejectReason, setRejectReason] = useState('');
  const [rejectError, setRejectError] = useState('');
  const [rejectBusy, setRejectBusy] = useState(false);

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
  const recent = Array.isArray(data?.recent) ? data.recent : [];
  const projects = Array.isArray(data?.projects) ? data.projects : [];
  const funders = Array.isArray(data?.funders) ? data.funders : [];

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

      <div className="program-filter-row">
        <div className="program-tabs" role="tablist" aria-label="Approval status">
          {[
            ['pending', 'Pending', counts.pending ?? 0],
            ['approved', 'Approved', counts.approved ?? 0],
            ['rejected', 'Rejected', counts.rejected ?? 0],
          ].map(([value, label, count]) => (
            <button
              key={value}
              type="button"
              role="tab"
              aria-selected={status === value}
              className={`program-tab${status === value ? ' active' : ''}`}
              onClick={() => setStatus(value)}
            >
              {label} {count}
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
            onChange={(event) => setType(event.target.value)}
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
            onChange={(event) => setProject(event.target.value)}
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
            onChange={(event) => setFunder(event.target.value)}
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

      <section className="program-card">
        {loading && !data ? (
          <div className="program-empty">Loading approval requests…</div>
        ) : approvals.length === 0 ? (
          <div className="program-empty">
            {status === 'pending'
              ? 'All caught up. No pending approvals.'
              : `No ${status} requests match these filters.`}
          </div>
        ) : (
          approvals.map((approval) => {
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
                    {isOwn && (
                      <div className="program-warning" style={{ marginTop: 6 }}>
                        ⚠ You cannot approve a request you created
                      </div>
                    )}
                  </div>

                  {status === 'pending' && (
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
                  )}
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
          })
        )}
      </section>

      <section className="program-card">
        <div className="program-card-head">
          <div>
            <div className="program-card-title">Recently decided</div>
            <div className="program-card-sub">Who decided, when, and why</div>
          </div>
        </div>

        {recent.length === 0 ? (
          <div className="program-empty">No decisions have been recorded yet.</div>
        ) : (
          recent.map((approval) => (
            <div className="program-row" key={`recent-${approval.id}`}>
              <div className="program-row-main">
                <div className="program-row-title">
                  <ProgramPill value={approval.status === 'approved' ? 'Approved' : 'Rejected'} />
                  <span>{approval.title}</span>
                </div>
                <div className="program-row-sub">
                  {approval.project} · {formatDate(approval.reviewed_at)}
                  {approval.reviewed_by ? ` · decided by ${approval.reviewed_by}` : ''}
                  {approval.review_notes ? ` · “${approval.review_notes}”` : ''}
                </div>
              </div>
            </div>
          ))
        )}
      </section>
    </>
  );
}
