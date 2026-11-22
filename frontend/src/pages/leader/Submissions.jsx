import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import LoadState from '../../components/LoadState';
import ReviewRow from '../../components/leader/ReviewRow';
import RecordPanel from '../../components/leader/RecordPanel';
import { useReviewActions } from '../../components/leader/useReviewActions';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import { useRoleBadges } from '../../layouts/roleBadges';

const TABS = [
  ['waiting', 'Waiting for you'],
  ['returned', 'Returned'],
  ['approved', 'Approved by you'],
];

const TYPES = [
  ['', 'All types'],
  ['expense', 'Expense'],
  ['payroll', 'Payroll'],
  ['purchase_request', 'Purchase request'],
];

const EMPTY = {
  waiting: 'Nothing is waiting for you.',
  returned: 'Nothing has been returned.',
  approved: 'You have not approved anything yet.',
};

/** ?open=expense-12 -> { type: 'expense', id: 12 } */
function parseOpen(value) {
  const match = /^(expense|payroll|purchase_request)-(\d+)$/.exec(value ?? '');
  return match ? { type: match[1], id: Number(match[2]) } : null;
}

/**
 * Submissions (Project Leader): expenses, payroll batches and purchase
 * requests of the project, by tab. Filters live in the URL query
 * (?project=&tab=&type=&open=).
 */
export default function Submissions() {
  const picker = useAssignedProject({ role: 'project-leader' });
  const [searchParams, setSearchParams] = useSearchParams();
  const rawTab = searchParams.get('tab');
  const tab = TABS.some(([value]) => value === rawTab) ? rawTab : 'waiting';
  const rawType = searchParams.get('type') ?? '';
  const type = TYPES.some(([value]) => value === rawType) ? rawType : '';
  const open = parseOpen(searchParams.get('open'));
  const [refreshKey, setRefreshKey] = useState(0);

  const query = new URLSearchParams({ tab, ...(type ? { type } : {}) }).toString();
  const url = picker.projectId ? `${ENDPOINTS.reviewQueue(picker.projectId)}?${query}` : null;
  const queue = useApiData(url);
  // After a decision: reload the list and the open panel.
  const actions = useReviewActions(async () => {
    queue.retry();
    setRefreshKey((value) => value + 1);
  });
  const { refreshBadges } = useRoleBadges();
  const [editing, setEditing] = useState(null); // a returned expense this leader recorded

  function updateQuery(changes) {
    const next = new URLSearchParams(searchParams);
    Object.entries(changes).forEach(([key, value]) => {
      const isDefault = value === '' || value == null || (key === 'tab' && value === 'waiting');
      if (isDefault) next.delete(key);
      else next.set(key, value);
    });
    setSearchParams(next, { replace: replaceHistory(changes) });
  }

  const counts = queue.data?.counts;
  const items = Array.isArray(queue.data?.items) ? queue.data.items : [];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Review · Project Leader</div>
          <h1 className="program-h1">Submissions</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code}` : 'Expenses, payroll and purchase requests of your project'}
          </p>
        </div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <div className="program-filter-row ld-controls">
          <div className="program-tabs" role="tablist" aria-label="Submissions">
            {TABS.map(([value, label]) => (
              <button
                key={value}
                type="button"
                role="tab"
                aria-selected={tab === value}
                className={`program-tab${tab === value ? ' active' : ''}`}
                onClick={() => updateQuery({ tab: value })}
              >
                {label}{counts ? ` ${counts[value] ?? 0}` : ''}
              </button>
            ))}
          </div>
          <div className="spacer" />
          <div className="program-field">
            <label htmlFor="submissions-type">Type</label>
            <select id="submissions-type" className="program-select" value={type} onChange={(event) => updateQuery({ type: event.target.value })}>
              {TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>
        </div>

        {actions.feedback && (
          <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
        )}

        <LoadState state={queue} loadingText="Loading submissions…" emptyText={EMPTY[tab]} isEmpty={items.length === 0}>
          <section className="program-card ld-list-card">
            {items.map((item) => (
              <ReviewRow
                key={item.key}
                item={item}
                showStage={tab !== 'waiting'}
                onOpen={(row) => updateQuery({ open: row.key })}
              />
            ))}
          </section>
        </LoadState>

        {picker.projectId && (
          <RecordPanel
            projectId={picker.projectId}
            target={open}
            refreshKey={refreshKey}
            busyKey={actions.busyKey}
            onApprove={actions.approve}
            onReturn={actions.openReturn}
            onEdit={(item) => { actions.setFeedback(null); setEditing(item); }}
            onCancel={actions.openCancel}
            onClose={() => updateQuery({ open: '' })}
          />
        )}
      </LoadState>

      {actions.dialog}

      {editing && picker.projectId && (
        <RecordExpenseDialog
          key={editing.key}
          projectId={picker.projectId}
          expense={editing}
          onClose={() => { setEditing(null); queue.retry(); setRefreshKey((value) => value + 1); }}
          onDone={(text) => {
            setEditing(null);
            actions.setFeedback({ tone: 'ok', text });
            queue.retry();
            setRefreshKey((value) => value + 1);
            refreshBadges();
          }}
        />
      )}
    </div>
  );
}

/** Opening / closing the panel adds a history entry; filter changes replace it. */
function replaceHistory(changes) {
  return !Object.prototype.hasOwnProperty.call(changes, 'open');
}
