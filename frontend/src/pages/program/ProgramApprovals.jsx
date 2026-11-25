import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useProgramRefresh } from '../../layouts/ProgramLayout';
import { fetchProgramApprovals } from '../../api/program';
import ProgramPill from '../../components/program/ProgramPill';
import ApprovalPanel from '../../components/program/ApprovalPanel';
import { peso } from '../../components/money';
import { formatDate } from '../../lib/datetime';
import { ALL_TYPES, approvalListError, typeOptions } from '../../lib/approvalFilters';

const TABS = [
  ['pending', 'Pending'],
  ['approved', 'Approved'],
  ['returned', 'Returned'],
  ['withdrawn', 'Withdrawn'],
];

const EMPTY = {
  pending: 'All caught up. No pending approvals.',
  approved: 'No approved requests yet.',
  returned: 'No returned requests.',
  withdrawn: 'No withdrawn requests.',
};

const PAGE_SIZE = 20;

const TYPE_PILL = {
  disbursement: 'Fund release',
  // Payroll steps escalated to the Program Leader (self-pay / no eligible verifier).
  payroll: 'Payroll',
  budget_realignment: 'Realignment',
  liquidation: 'Liquidation',
  // Expenses no Finance/Admin of the project may verify (they recorded or approved it).
  expense: 'Expense verification',
};

/** Only when there are attachments: "2 attachments". */
function documentsText(count) {
  if (!count) return '';
  return ` · ${count} attachment${count === 1 ? '' : 's'}`;
}

function RemarkBox({ text }) {
  const value = typeof text === 'string' ? text.trim() : '';
  return value ? <p className="pa-remark">“{value}”</p> : null;
}

// Each decided tab holds one status, so the row does not repeat it (the tab says it).
function DecidedRow({ approval, onOpen, showProject }) {
  const withdrawn = approval.status === 'withdrawn';
  return (
    <li className="pa-decided-row">
      <div className="pa-decided-main">
        <div className="pa-decided-title">
          <b>{approval.title}</b>
          <span className="pa-type">{TYPE_PILL[approval.type] ?? approval.type_label}</span>
        </div>
        <div className="pa-meta">
          {showProject && <>{approval.project ?? 'Unknown project'} · </>}
          {approval.amount != null && <><b>{peso(approval.amount)}</b> · </>}
          {approval.requested_by ?? 'Unknown user'}
          {' · '}{formatDate(approval.created_at)}
        </div>
        <div className="pa-meta">
          {withdrawn ? 'Returned by ' : ''}{approval.reviewed_by ?? 'Unknown user'} · {formatDate(approval.reviewed_at)}
          {withdrawn && approval.withdrawn_at ? ` · withdrawn ${formatDate(approval.withdrawn_at)}` : ''}
          {approval.status === 'returned' ? ' · awaiting resubmission' : ''}
        </div>
        <RemarkBox text={approval.review_notes} />
      </div>
      <div className="pa-decided-side">
        {/* The audit trail link lives in the detail panel. */}
        <button type="button" className="program-btn sm" onClick={() => onOpen(approval)}>View details</button>
      </div>
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
                  <span className="pa-skel" style={{ width: 92, height: 44 }} />
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

function DecidedList({ approvals, onOpen, showProject }) {
  const [visibleCount, setVisibleCount] = useState(PAGE_SIZE);

  return (
    <section className="program-card">
      <ul className="pa-decided-list">
        {approvals.slice(0, visibleCount).map((approval) => (
          <DecidedRow key={approval.id} approval={approval} onOpen={onOpen} showProject={showProject} />
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

  // Older links use ?status=; ?tab= is the current name. "rejected" (older links) is Returned.
  const tabParam = searchParams.get('tab') ?? searchParams.get('status');
  const rawTab = tabParam === 'rejected' ? 'returned' : tabParam;
  const status = TABS.some(([value]) => value === rawTab) ? rawTab : 'pending';
  // The server validates the type against the list it returns as `types`;
  // a value it does not accept shows "This filter isn't supported."
  const type = searchParams.get('type') || ALL_TYPES;
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
  // ?open=ID shows the review panel; Approve / Return live only there.
  const openId = /^\d+$/.test(searchParams.get('open') ?? '') ? Number(searchParams.get('open')) : null;

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
      setFailure({ key: requestKey, message: approvalListError(requestError) });
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

  function openPanel(approval) {
    setFeedback(null);
    const next = new URLSearchParams(searchParams);
    next.set('open', String(approval.id));
    setSearchParams(next); // a history entry: Back closes the panel
  }

  const closePanel = useCallback(() => {
    const next = new URLSearchParams(searchParams);
    next.delete('open');
    setSearchParams(next, { replace: true });
  }, [searchParams, setSearchParams]);

  async function handleDecided(text) {
    closePanel();
    setFeedback({ tone: 'ok', text });
    await refreshAll();
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
  const types = typeOptions(payload?.types, type);
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
          <h1 className="program-h1">Approval requests</h1>
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
            {types.map(([value, label]) => (
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
          {hasFilters && (
            <>
              {' '}
              <button
                type="button"
                className="program-btn sm"
                onClick={() => updateQuery({ type: '', project: '', funder: '' })}
              >
                Clear filters
              </button>
            </>
          )}
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

            return (
              <div key={approval.id}>
                <div className="program-row">
                  <div className="program-row-main">
                    <div className="program-row-title">
                      <button type="button" className="ld-link" onClick={() => openPanel(approval)}>{approval.title}</button>
                      <ProgramPill value={approval.type_label} />
                    </div>
                    <div className="program-row-sub">
                      {[
                        project ? null : approval.project,
                        approval.amount != null ? peso(approval.amount) : null,
                        `waiting ${approval.days_waiting ?? 0} days`,
                        approval.requested_by,
                      ].filter(Boolean).join(' · ')}
                      {documentsText(approval.documents_count)}
                    </div>
                    {approval.justification?.trim() && (
                      <p className="pa-remark">“{approval.justification.trim()}”</p>
                    )}
                    {approval.resubmitted_at && (
                      <div className="program-row-sub">
                        ↩ Resubmitted {formatDate(approval.resubmitted_at)}
                        {approval.last_return_reason ? ` after your return: “${approval.last_return_reason}”` : ''}
                      </div>
                    )}
                    {isOwn && (
                      <div className="program-warning" style={{ marginTop: 6 }}>
                        ⚠ You cannot approve a request you created
                      </div>
                    )}
                  </div>

                  <div className="program-row-actions">
                    <button type="button" className="program-btn primary" onClick={() => openPanel(approval)}>
                      Review
                    </button>
                  </div>
                </div>
              </div>
            );
          })}
        </section>
      ) : (
        // Keyed so "Load more" resets to 20 rows when the tab or filters change.
        <DecidedList key={`${status}|${type}|${project}|${funder}`} approvals={approvals} onOpen={openPanel} showProject={!project} />
      )}

      <ApprovalPanel key={openId ?? 'closed'} approvalId={openId} onClose={closePanel} onDecided={handleDecided} />
    </>
  );
}
