import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { SUBMISSION_TABS, parseOpen } from '../../lib/assistantStatus';
import LoadState from '../../components/LoadState';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import PurchaseRequestDialog from '../../components/assistant/PurchaseRequestDialog';
import SubmissionTable from '../../components/assistant/SubmissionTable';
import { useRecordActions } from '../../components/assistant/useRecordActions';

const STATUS = [
  ['all', 'All'],
  ...SUBMISSION_TABS.map(([value, label]) => (value === 'completed' ? [value, 'Fulfilled or cancelled'] : [value, label])),
];
const DEFAULTS = { status: 'all' };

/**
 * Purchase requests (Research Assistant): create (draft, or save + attach
 * + submit), edit and resubmit when returned, cancel when approved but not
 * fulfilled. ?open= (also from a notification) shows the side panel.
 */
export default function PurchaseRequests() {
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get, update } = useUrlQuery(DEFAULTS);
  const status = STATUS.some(([value]) => value === get('status')) ? get('status') : 'all';
  // Older notification links use ?request=ID.
  const open = parseOpen(get('open')) ?? (get('request') ? { type: 'purchase_request', id: Number(get('request')) } : null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [creating, setCreating] = useState(false);

  const projectId = picker.projectId;
  const list = useApiData(projectId ? `${ENDPOINTS.mySubmissions(projectId)}?tab=${status}&type=purchase_request` : null);
  const reload = () => { list.retry(); setRefreshKey((value) => value + 1); };
  const actions = useRecordActions({ projectId, onChanged: reload });
  const items = Array.isArray(list.data?.items) ? list.data.items : [];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Encode · Research Assistant</div>
          <h1 className="program-h1">Purchase requests</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}An approved request holds its amount on the line item until an expense is linked
          </p>
        </div>
        {projectId && (
          <div className="pl-head-actions">
            <div className="program-field">
              <label htmlFor="ra-pr-status">Status</label>
              <select id="ra-pr-status" className="program-select" value={status} onChange={(event) => update({ status: event.target.value })}>
                {STATUS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </div>
            <button type="button" className="program-btn primary" onClick={() => { actions.setFeedback(null); setCreating(true); }}>New purchase request</button>
          </div>
        )}
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState
          state={list}
          loadingText="Loading your purchase requests…"
          emptyText={status === 'all' ? 'You have not made a purchase request yet.' : 'No purchase request in this status.'}
          isEmpty={items.length === 0}
        >
          <section className="program-card ld-list-card">
            <SubmissionTable items={items} pillType="purchase_request" caption="My purchase requests" onOpen={(item) => update({ open: item.key, request: '' })} onResubmit={actions.resubmit} busyKey={actions.busyKey} />
          </section>
        </LoadState>

        {projectId && (
          <AssistantRecordPanel projectId={projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '', request: '' })} />
        )}
      </LoadState>

      {actions.dialog}
      {creating && projectId && (
        <PurchaseRequestDialog
          projectId={projectId}
          onClose={() => { setCreating(false); reload(); }}
          onDone={(text) => { setCreating(false); actions.setFeedback({ tone: 'ok', text }); reload(); }}
        />
      )}
    </div>
  );
}
