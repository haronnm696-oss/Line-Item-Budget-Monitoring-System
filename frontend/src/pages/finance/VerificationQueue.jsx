import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen } from '../../lib/assistantStatus';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';
import FinanceRecordPanel from '../../components/finance/FinanceRecordPanel';
import LateReceipts from '../../components/finance/LateReceipts';
import QueueTable from '../../components/finance/QueueTable';
import { useVerifyActions } from '../../components/finance/useVerifyActions';

const TABS = [
  ['waiting', 'Waiting for you'],
  ['verified', 'Verified by you'],
  ['rejected', 'Rejected by you'],
];
const TYPES = [
  ['', 'All types'],
  ['expense', 'Expense'],
  ['payroll', 'Payroll'],
];
const EMPTY = {
  waiting: 'Nothing is waiting for verification.',
  verified: 'You have not verified anything on this project yet.',
  rejected: 'You have not rejected anything on this project.',
};
const DEFAULTS = { tab: 'waiting' };

/**
 * Verification queue (Finance/Admin): expenses and payroll batches approved
 * by the Project Leader or the Program Leader. Filters live in the URL
 * (?project=&tab=&type=&open=); ?open= shows the side panel.
 */
export default function VerificationQueue() {
  const picker = useAssignedProject({ role: 'finance-admin' });
  const { get, update } = useUrlQuery(DEFAULTS);
  const tab = TABS.some(([value]) => value === get('tab')) ? get('tab') : 'waiting';
  const type = TYPES.some(([value]) => value === get('type')) ? get('type') : '';
  const open = parseOpen(get('open'));
  const [refreshKey, setRefreshKey] = useState(0);

  const query = new URLSearchParams({ tab, ...(type ? { type } : {}) }).toString();
  const list = useApiData(picker.projectId ? `${ENDPOINTS.verificationQueue(picker.projectId)}?${query}` : null);
  const actions = useVerifyActions({
    projectId: picker.projectId,
    onChanged: () => { list.retry(); setRefreshKey((value) => value + 1); },
  });
  const counts = list.data?.counts;
  const items = Array.isArray(list.data?.items) ? list.data.items : [];
  const late = Array.isArray(list.data?.late_receipts) ? list.data.late_receipts : [];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Verify · Finance/Admin</div>
          <h1 className="program-h1">Verification queue</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Spent = verified expenses and verified payroll
          </p>
        </div>
        <div className="pl-head-actions"><ProjectSelect picker={picker} id="fin-queue-project" /></div>
      </div>

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <div className="program-filter-row ld-controls">
          <div className="program-tabs" role="tablist" aria-label="Verification queue">
            {TABS.map(([value, label]) => (
              <button
                key={value}
                type="button"
                role="tab"
                aria-selected={tab === value}
                className={`program-tab${tab === value ? ' active' : ''}`}
                onClick={() => update({ tab: value })}
              >
                {label}{counts ? ` ${counts[value] ?? 0}` : ''}
              </button>
            ))}
          </div>
          <div className="spacer" />
          <div className="program-field">
            <label htmlFor="fin-queue-type">Type</label>
            <select id="fin-queue-type" className="program-select" value={type} onChange={(event) => update({ type: event.target.value })}>
              {TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>
        </div>

        {actions.feedback && (
          <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
        )}

        <LoadState state={list} loadingText="Loading the queue…" emptyText={EMPTY[tab]} isEmpty={items.length === 0}>
          <section className="program-card ld-list-card">
            <QueueTable items={items} showActions={tab === 'waiting'} caption="Verification queue" onOpen={(item) => update({ open: item.key })} />
          </section>
        </LoadState>

        {tab === 'waiting' && late.length > 0 && (
          <section className="program-card">
            <LateReceipts items={late} onOpen={(row) => update({ open: row.key })} />
          </section>
        )}

        {picker.projectId && (
          <FinanceRecordPanel projectId={picker.projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '' })} />
        )}
      </LoadState>

      {actions.dialog}
    </div>
  );
}
