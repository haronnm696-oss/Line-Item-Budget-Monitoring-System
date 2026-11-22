import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { SUBMISSION_TABS, SUBMISSION_TYPES, parseOpen } from '../../lib/assistantStatus';
import LoadState from '../../components/LoadState';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import SubmissionTable from '../../components/assistant/SubmissionTable';
import { useRecordActions } from '../../components/assistant/useRecordActions';

const DEFAULTS = { tab: 'waiting' };

const EMPTY = {
  drafts: 'No drafts. Everything you saved has been submitted.',
  waiting: 'Nothing is waiting for the Project Leader.',
  returned: 'Nothing has been returned to you.',
  approved: 'Nothing is approved and waiting for the next step.',
  completed: 'Nothing is completed yet: no verified expense or payroll, and no fulfilled or cancelled purchase request.',
};

/**
 * My submissions (Research Assistant): every expense, purchase request and
 * payroll batch they encoded, by tab and type. Filters live in the URL
 * (?project=&tab=&type=&open=); ?open= shows the record's side panel.
 */
export default function MySubmissions() {
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get, update } = useUrlQuery(DEFAULTS);
  const tab = SUBMISSION_TABS.some(([value]) => value === get('tab')) ? get('tab') : 'waiting';
  const type = SUBMISSION_TYPES.some(([value]) => value === get('type')) ? get('type') : '';
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);

  const query = new URLSearchParams({ tab, ...(type ? { type } : {}) }).toString();
  const list = useApiData(picker.projectId ? `${ENDPOINTS.mySubmissions(picker.projectId)}?${query}` : null);
  const actions = useRecordActions({
    projectId: picker.projectId,
    onChanged: () => { list.retry(); setRefreshKey((value) => value + 1); },
  });
  const counts = list.data?.counts;
  const items = Array.isArray(list.data?.items) ? list.data.items : [];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Track · Research Assistant</div>
          <h1 className="program-h1">My submissions</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Every record you encoded, and who has it now
          </p>
        </div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <div className="program-filter-row ld-controls">
          <div className="program-tabs" role="tablist" aria-label="My submissions">
            {SUBMISSION_TABS.map(([value, label]) => (
              <button
                key={value}
                type="button"
                role="tab"
                aria-selected={tab === value}
                className={`program-tab${tab === value ? ' active' : ''}${value === 'returned' && counts?.returned > 0 ? ' ra-tab-alert' : ''}`}
                onClick={() => update({ tab: value })}
              >
                {label}{counts ? ` ${counts[value] ?? 0}` : ''}
              </button>
            ))}
          </div>
          <div className="spacer" />
          <div className="program-field">
            <label htmlFor="ra-submissions-type">Type</label>
            <select id="ra-submissions-type" className="program-select" value={type} onChange={(event) => update({ type: event.target.value })}>
              {SUBMISSION_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>
        </div>

        {actions.feedback && (
          <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
        )}

        <LoadState state={list} loadingText="Loading your submissions…" emptyText={EMPTY[tab]} isEmpty={items.length === 0}>
          <section className="program-card ld-list-card">
            <SubmissionTable items={items} showType caption="My submissions" onOpen={(item) => update({ open: item.key })} onResubmit={actions.resubmit} busyKey={actions.busyKey} />
          </section>
        </LoadState>

        {picker.projectId && (
          <AssistantRecordPanel projectId={picker.projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '' })} />
        )}
      </LoadState>

      {actions.dialog}
    </div>
  );
}
