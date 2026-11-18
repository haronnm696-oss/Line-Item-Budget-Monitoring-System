import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { SUBMISSION_TABS, parseOpen } from '../../lib/assistantStatus';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import SubmissionTable from '../../components/assistant/SubmissionTable';
import { useRecordActions } from '../../components/assistant/useRecordActions';
import { peso } from '../../components/money';

const STATUS = [['all', 'All'], ...SUBMISSION_TABS];
const DEFAULTS = { status: 'all' };

/**
 * Expenses and receipts (Research Assistant): record an expense (draft, or
 * save + attach + submit), the expenses they recorded with their next step,
 * and the project's verified expenses still missing a receipt. ?open=
 * shows an expense's side panel (edit and resubmit, delete, submit).
 */
export default function AssistantExpenses() {
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get, update } = useUrlQuery(DEFAULTS);
  const status = STATUS.some(([value]) => value === get('status')) ? get('status') : 'all';
  // Older notification links use ?expense=ID.
  const open = parseOpen(get('open')) ?? (get('expense') ? { type: 'expense', id: Number(get('expense')) } : null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [recording, setRecording] = useState(false);

  const projectId = picker.projectId;
  const list = useApiData(projectId ? `${ENDPOINTS.mySubmissions(projectId)}?tab=${status}&type=expense` : null);
  const compliance = useApiData(projectId ? ENDPOINTS.projectCompliance(projectId) : null);
  const reload = () => { list.retry(); compliance.retry(); setRefreshKey((value) => value + 1); };
  const actions = useRecordActions({ projectId, onChanged: reload });
  const items = Array.isArray(list.data?.items) ? list.data.items : [];
  const missing = compliance.data?.missing_items ?? [];
  const missingCount = Number(compliance.data?.missing_receipt) || 0;

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Encode · Research Assistant</div>
          <h1 className="program-h1">Expenses and receipts</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Only verified expenses count as spent; each one needs a receipt
          </p>
        </div>
        {projectId && (
          <div className="pl-head-actions">
            <ProjectSelect picker={picker} id="ra-expenses-project" />
            <button type="button" className="program-btn primary" onClick={() => { actions.setFeedback(null); setRecording(true); }}>Record expense</button>
          </div>
        )}
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <div className="pl-row pl-row-main">
          <section className="program-card">
            <div className="program-card-head">
              <div>
                <div className="program-card-title">My expenses</div>
                <div className="program-card-sub">Expenses you recorded, newest first</div>
              </div>
              <div className="program-field ra-inline-field">
                <label htmlFor="ra-expenses-status">Status</label>
                <select id="ra-expenses-status" className="program-select" value={status} onChange={(event) => update({ status: event.target.value })}>
                  {STATUS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </div>
            </div>
            {list.status === 'error' && (
              <div className="program-banner error" role="alert">
                {list.error?.message} <button type="button" className="program-btn sm" onClick={list.retry}>Try again</button>
              </div>
            )}
            {(list.status === 'loading' || list.status === 'idle') && <p className="ld-muted" aria-busy="true">Loading your expenses…</p>}
            {list.status === 'ready' && items.length === 0 && (
              <p className="ld-empty">{status === 'all' ? 'You have not recorded an expense yet.' : 'No expense in this status.'}</p>
            )}
            {items.length > 0 && <SubmissionTable items={items} caption="My expenses" onOpen={(item) => update({ open: item.key, expense: '' })} onResubmit={actions.resubmit} busyKey={actions.busyKey} />}
          </section>

          <section className="program-card">
            <div className="program-card-head">
              <div>
                <div className="program-card-title">Missing receipts</div>
                <div className="program-card-sub">Verified expenses of the project without a receipt</div>
              </div>
            </div>
            {compliance.status === 'error' && (
              <div className="program-banner error" role="alert">
                {compliance.error?.message} <button type="button" className="program-btn sm" onClick={compliance.retry}>Try again</button>
              </div>
            )}
            {compliance.status === 'ready' && missing.length === 0 && <p className="ld-empty">✓ Every verified expense has a receipt.</p>}
            {missing.length > 0 && (
              <ul className="ld-mini-list">
                {missing.map((row) => (
                  <li key={row.id}>
                    <div>
                      <b>{row.reference_no || `EXP-${row.id}`} · {peso(row.amount)}</b>
                      <span>{row.description}{row.category ? ` · ${row.category}` : ''}</span>
                    </div>
                    <button
                      type="button"
                      className="program-btn sm"
                      onClick={() => actions.upload({
                        key: `expense-${row.id}`, type: 'expense', id: row.id, type_label: 'Expense',
                        reference: row.reference_no || `EXP-${row.id}`, title: row.description, amount: row.amount,
                      }, { types: ['receipt'], title: 'Upload missing receipt' })}
                    >
                      Upload
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {missingCount > missing.length && <p className="ld-muted">{missingCount - missing.length} more not shown; the oldest are listed last.</p>}
          </section>
        </div>

        {projectId && (
          <AssistantRecordPanel projectId={projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '', expense: '' })} />
        )}
      </LoadState>

      {actions.dialog}
      {recording && projectId && (
        <RecordExpenseDialog
          assistant
          projectId={projectId}
          onClose={() => { setRecording(false); reload(); }}
          onDone={(text) => { setRecording(false); actions.setFeedback({ tone: 'ok', text }); reload(); }}
        />
      )}
    </div>
  );
}
