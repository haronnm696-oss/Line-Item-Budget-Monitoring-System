import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { canResubmit, parseOpen } from '../../lib/assistantStatus';
import { formatDate } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';
import RecordExpenseDialog from '../../components/leader/RecordExpenseDialog';
import StagePill from '../../components/assistant/StagePill';
import FinanceRecordPanel from '../../components/finance/FinanceRecordPanel';
import { useVerifyActions } from '../../components/finance/useVerifyActions';
import { peso } from '../../components/money';

const STATUS = [
  ['', 'All stages'],
  ['draft', 'Draft'],
  ['submitted', 'Waiting for the Project Leader'],
  ['endorsed', 'Approved, to verify'],
  ['returned', 'Returned'],
  ['verified', 'Verified'],
];

/**
 * Expenses and receipts (Finance/Admin): every expense of the project with
 * its stage and receipts, the missing receipts (Notify RA), and Record
 * expense. An expense Finance/Admin records goes to the Project Leader for
 * approval like a Research Assistant's (the backend rule), and another
 * Finance/Admin verifies it.
 */
export default function FinanceExpenses() {
  const picker = useAssignedProject({ role: 'finance-admin' });
  const { get, update } = useUrlQuery();
  const status = STATUS.some(([value]) => value === get('status')) ? get('status') : '';
  const open = parseOpen(get('open')) ?? (get('expense') ? { type: 'expense', id: Number(get('expense')) } : null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [recording, setRecording] = useState(false);

  const projectId = picker.projectId;
  const list = useApiData(projectId ? `${ENDPOINTS.expenses(projectId)}${status ? `?workflow_status=${status}` : ''}` : null);
  const compliance = useApiData(projectId ? ENDPOINTS.projectCompliance(projectId) : null);
  const reload = () => { list.retry(); compliance.retry(); setRefreshKey((value) => value + 1); };
  const actions = useVerifyActions({ projectId, onChanged: reload });
  const rows = Array.isArray(list.data?.expenses) ? list.data.expenses : [];
  const missing = compliance.data?.missing_items ?? [];

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Verify · Finance/Admin</div>
          <h1 className="program-h1">Expenses and receipts</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Expenses you record go to the Project Leader, then another Finance/Admin verifies them
          </p>
        </div>
        {projectId && (
          <div className="pl-head-actions">
            <ProjectSelect picker={picker} id="fin-expenses-project" />
            <div className="program-field">
              <label htmlFor="fin-expenses-status">Stage</label>
              <select id="fin-expenses-status" className="program-select" value={status} onChange={(event) => update({ status: event.target.value })}>
                {STATUS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </div>
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
                <div className="program-card-title">Expenses</div>
                <div className="program-card-sub">Newest first</div>
              </div>
            </div>
            <LoadState state={list} loadingText="Loading expenses…" emptyText={status ? 'No expense at this stage.' : 'No expenses recorded yet.'} isEmpty={rows.length === 0}>
              <div className="ra-table-wrap">
                <table className="program-table ld-table ra-table">
                  <caption className="pa-sr-only">Expenses</caption>
                  <thead>
                    <tr>
                      <th scope="col">Reference</th>
                      <th scope="col">Description</th>
                      <th scope="col" className="num">Amount</th>
                      <th scope="col">Stage</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row) => (
                      <tr key={row.id}>
                        <td data-label="Reference">
                          <button type="button" className="ld-link" onClick={() => update({ open: `expense-${row.id}`, expense: '' })}>{row.reference_no || `EXP-${row.id}`}</button>
                          <div className="ra-type">{formatDate(row.expense_date)}</div>
                        </td>
                        <td data-label="Description">
                          <div className="ra-desc">{row.description}</div>
                          <div className="ra-details">{[row.line_item, row.recorded_by?.name ? `recorded by ${row.recorded_by.name}` : null].filter(Boolean).join(' · ')}</div>
                        </td>
                        <td data-label="Amount" className="num ld-amount">{peso(row.amount)}</td>
                        <td data-label="Stage">
                          <StagePill status={row.workflow_status} />
                          <div className={`ra-next${row.receipts_count === 0 ? ' fin-blocked' : ''}`}>
                            {row.receipts_count === 0 ? '⚠ No receipt' : `${row.receipts_count} receipt${row.receipts_count === 1 ? '' : 's'}`}
                          </div>
                          {canResubmit(row) && (
                            <div className="fin-row-actions ra-row-resubmit">
                              <button type="button" className="program-btn sm primary" disabled={actions.busyKey === `expense-${row.id}`} onClick={() => actions.resubmit(row)}>
                                {actions.busyKey === `expense-${row.id}` ? 'Opening…' : 'Edit and resubmit'}
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </LoadState>
          </section>

          <section className="program-card">
            <div className="program-card-head">
              <div>
                <div className="program-card-title">Missing receipts</div>
                <div className="program-card-sub">Verified expenses without a receipt</div>
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
                      <span>{row.description}</span>
                    </div>
                    <button type="button" className="program-btn sm" disabled={actions.busyKey === `notify-${row.id}`} onClick={() => actions.notifyRa(row)}>
                      {actions.busyKey === `notify-${row.id}` ? 'Sending…' : 'Notify RA'}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>

        {projectId && (
          <FinanceRecordPanel projectId={projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '', expense: '' })} />
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
