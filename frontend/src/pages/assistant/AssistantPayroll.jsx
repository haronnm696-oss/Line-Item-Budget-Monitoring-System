import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText } from '../../api/leader';
import { useAuth } from '../../context/AuthContext';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { parseOpen, returnedLine } from '../../lib/assistantStatus';
import { todayInManila } from '../../lib/datetime';
import LoadState from '../../components/LoadState';
import ProjectSelect from '../../components/leader/ProjectSelect';
import DocumentList from '../../components/leader/DocumentList';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import StagePill from '../../components/assistant/StagePill';
import { useRecordActions } from '../../components/assistant/useRecordActions';
import { peso } from '../../components/money';
import { PayRatesCard } from '../../components/payroll/PayRates';
import RatesLabelBanner from '../../components/payroll/RatesLabelBanner';

const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;
const RATES_NOT_SET = 'Deduction rates are not set yet. Ask your Program Leader or Finance/Admin.';

/**
 * Payroll (Research Assistant): prepare the month's batch for the project
 * (rows are computed by the server from the pay rates and the deduction
 * rules), review the rows, upload the payroll file and submit the batch;
 * edit and resubmit when it is returned. Nothing is computed here.
 */
export default function AssistantPayroll() {
  const { user } = useAuth();
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get, update } = useUrlQuery();
  const month = MONTH_RE.test(get('month')) ? get('month') : todayInManila().slice(0, 7);
  const open = parseOpen(get('open'));
  const projectId = picker.projectId;

  const payroll = useApiData(projectId ? `${ENDPOINTS.payroll(projectId)}?month=${month}` : null);
  const batch = payroll.data?.batch ?? null;
  const item = useApiData(projectId && batch ? ENDPOINTS.mySubmission(projectId, 'payroll', batch.id) : null);
  const documents = useApiData(batch ? ENDPOINTS.payrollBatchDocuments(batch.id) : null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [busy, setBusy] = useState(null);
  const [lineItemId, setLineItemId] = useState('');
  const [editingRow, setEditingRow] = useState(null);

  const reload = () => { payroll.retry(); item.retry(); documents.retry(); setRefreshKey((value) => value + 1); };
  const actions = useRecordActions({ projectId, onChanged: reload });

  const run = payroll.data;
  const rows = Array.isArray(run?.rows) ? run.rows : [];
  const psItems = Array.isArray(run?.ps_line_items) ? run.ps_line_items : [];
  const unsetRules = Array.isArray(run?.unset_rules) ? run.unset_rules : [];
  const ratesMissing = unsetRules.length > 0;
  const docs = Array.isArray(documents.data?.documents) ? documents.data.documents : [];
  const hasPayrollFile = docs.some((doc) => doc.document_type === 'payroll_file');
  const editable = batch ? batch.editable : true;
  const record = item.data?.item ?? null;
  const chosenLine = lineItemId || (psItems[0] ? String(psItems[0].id) : '');
  const label = run?.month_label ?? month;
  const missingPeople = Math.max(0, (Number(run?.rated_members) || 0) - rows.length);
  const unrated = Array.isArray(run?.unrated_members) ? run.unrated_members : [];

  async function act(key, send, okText) {
    setBusy(key);
    actions.setFeedback(null);
    try {
      const data = await send();
      actions.setFeedback({ tone: 'ok', text: typeof okText === 'function' ? okText(data) : okText });
      reload();
    } catch (error) {
      actions.setFeedback({ tone: 'error', text: errorText(error, 'Could not save. Please try again.') });
    } finally {
      setBusy(null);
    }
  }

  // The server's message names anyone left out ("Not included: no pay rate set for …").
  const prepare = () => act('prepare', () => apiWrite('post', ENDPOINTS.payroll(projectId), { month, lib_category_id: Number(chosenLine) }), (data) => `${data?.message ?? `Payroll for ${label} prepared and computed.`} Review the rows, upload the payroll file, then submit.`);
  const recompute = () => act('compute', () => apiWrite('post', ENDPOINTS.computePayroll(projectId), { month }), (data) => data?.message ?? `Payroll for ${label} recomputed from the current pay rates.`);
  const submit = () => act('submit', () => apiWrite('post', ENDPOINTS.payrollBatchStep(batch.id, 'submit')), `Payroll for ${label} submitted.`);
  const batchRecord = batch ? { key: `payroll-${batch.id}`, type: 'payroll', id: batch.id, type_label: 'Payroll', reference: `PAY-${month}`, title: `Payroll ${label}` } : null;

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Encode · Research Assistant</div>
          <h1 className="program-h1">Payroll</h1>
          <p className="program-sub">
            {picker.project ? `${picker.project.title} · ${picker.project.code} · ` : ''}Computed from the pay rates and deduction rules; payroll is not part of LIB spent
          </p>
        </div>
        {projectId && (
          <div className="pl-head-actions">
            <ProjectSelect picker={picker} id="ra-payroll-project" />
            <div className="program-field">
              <label htmlFor="ra-payroll-month">Month</label>
              <input id="ra-payroll-month" className="program-input" type="month" value={month}
                onChange={(event) => { if (MONTH_RE.test(event.target.value)) { actions.setFeedback(null); update({ month: event.target.value }); } }} />
            </div>
          </div>
        )}
      </div>

      <RatesLabelBanner label={run?.rates_label} />

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={payroll} loadingText="Loading payroll…">
          {run && (
            <>
              {ratesMissing && (
                <div className="program-banner error" role="alert">
                  <b>⚠ {RATES_NOT_SET}</b> Not set: {unsetRules.join(', ')}. You can prepare and review the rows, but the batch cannot be submitted until the rates are set.
                </div>
              )}

              {unrated.length > 0 && (
                <div className="program-banner info" role="status">
                  <b>ⓘ Not included: no pay rate set for {unrated.map((person) => person.name).join(', ')}.</b> They get no payroll row (never ₱0.00) until Finance/Admin or the Program Leader sets a monthly rate.
                </div>
              )}

              {!batch ? (
                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Prepare the {label} payroll</div>
                      <div className="program-card-sub">One row per project member with a monthly rate, computed by the system</div>
                    </div>
                  </div>
                  {Number(run.rated_members) === 0 ? (
                    <p className="ld-empty">No one on this project has a monthly rate yet. Ask Finance/Admin or your Program Leader to set pay rates.</p>
                  ) : psItems.length === 0 ? (
                    <p className="ld-empty">This project has no Personal Services (PS) line item to charge payroll to.</p>
                  ) : (
                    <div className="ra-inline-form">
                      <div className="program-field">
                        <label htmlFor="ra-payroll-line">PS line item</label>
                        <select id="ra-payroll-line" className="program-select" value={chosenLine} onChange={(event) => setLineItemId(event.target.value)}>
                          {psItems.map((line) => <option key={line.id} value={line.id}>{line.name}</option>)}
                        </select>
                      </div>
                      <button type="button" className="program-btn primary" disabled={busy !== null} onClick={prepare}>
                        {busy === 'prepare' ? 'Preparing…' : `Prepare ${run.rated_members} ${Number(run.rated_members) === 1 ? 'person' : 'people'}`}
                      </button>
                    </div>
                  )}
                </section>
              ) : (
                <>
                  <section className="program-card">
                    <div className="program-card-head">
                      <div>
                        <div className="program-card-title">{label} payroll</div>
                        <div className="program-card-sub">{rows.length} {rows.length === 1 ? 'person' : 'people'} · PAY-{month}</div>
                      </div>
                      <StagePill status={batch.workflow_status} />
                    </div>

                    {record && returnedLine(record) ? (
                      <div className="program-banner error ld-return-banner" role="note"><b>Returned.</b> {returnedLine(record)}</div>
                    ) : (record?.next_step || batch.waiting_text) && (
                      <p className="ld-waiting"><span aria-hidden="true">◷</span> {batch.escalated_step ? batch.waiting_text : (record?.next_step ?? batch.waiting_text)}</p>
                    )}

                    <div className="ra-payroll-totals">
                      <div className="ra-balance"><span>Gross</span><b>{peso(run.totals.gross)}</b></div>
                      <div className="ra-balance"><span>Deductions</span><b>{peso(run.totals.deductions)}</b></div>
                      <div className="ra-balance is-available"><span>Net pay</span><b>{peso(run.totals.net)}</b></div>
                    </div>

                    <div className="ra-table-scroll">
                      <table className="program-table ld-table">
                        <caption className="pa-sr-only">Payroll rows</caption>
                        <thead>
                          <tr>
                            <th scope="col">Name</th>
                            <th scope="col" className="num">Basic</th>
                            <th scope="col" className="num">Allowance</th>
                            <th scope="col" className="num">Gross</th>
                            <th scope="col" className="num">Deductions</th>
                            <th scope="col" className="num">Net</th>
                            {editable && <th scope="col"><span className="pa-sr-only">Actions</span></th>}
                          </tr>
                        </thead>
                        <tbody>
                          {rows.map((row) => (
                            <tr key={row.id}>
                              <td>
                                <div className="project-name">{row.name ?? '—'}</div>
                                <div className="project-code">{[row.position, row.category].filter(Boolean).join(' · ')}</div>
                              </td>
                              <td className="num">{peso(row.basic)}</td>
                              <td className="num">{peso(row.allowance)}</td>
                              <td className="num">{peso(row.gross)}</td>
                              <td className="num" title={row.deduction_items.map((d) => `${d.type}: ${peso(d.amount)}`).join('\n')}>{peso(row.deductions)}</td>
                              <td className="num ld-amount">{peso(row.net)}</td>
                              {editable && (
                                <td><button type="button" className="program-btn sm" onClick={() => setEditingRow(row)}>Edit</button></td>
                              )}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {run.deductionBreakdown.length > 0 && (
                      <p className="ld-muted">Deductions: {run.deductionBreakdown.map((d) => `${d.type} ${peso(d.amount)}`).join(' · ')}</p>
                    )}

                    {editable && (
                      <div className="ld-panel-actions ra-panel-actions">
                        <button type="button" className="program-btn" disabled={busy !== null} onClick={recompute}>{busy === 'compute' ? 'Computing…' : 'Recompute'}</button>
                        {missingPeople > 0 && psItems.length > 0 && (
                          <button type="button" className="program-btn" disabled={busy !== null} onClick={prepare}>
                            Add {missingPeople} {missingPeople === 1 ? 'person' : 'people'} with a pay rate
                          </button>
                        )}
                        <button type="button" className="program-btn" onClick={() => actions.upload(batchRecord, { title: 'Upload payroll file' })}>Upload payroll file</button>
                        <button
                          type="button"
                          className="program-btn primary"
                          disabled={busy !== null || ratesMissing || !hasPayrollFile || rows.length === 0}
                          onClick={submit}
                        >
                          {busy === 'submit' ? 'Submitting…' : batch.workflow_status === 'returned' ? 'Resubmit batch' : 'Submit batch'}
                        </button>
                      </div>
                    )}
                    {editable && !hasPayrollFile && documents.status === 'ready' && (
                      <p className="ld-hint">Upload the payroll file before submitting.</p>
                    )}
                  </section>

                  <section className="program-card">
                    <div className="program-card-head">
                      <div>
                        <div className="program-card-title">Payroll documents</div>
                        <div className="program-card-sub">The signed payroll file and supporting documents</div>
                      </div>
                    </div>
                    <DocumentList key={`${batch.id}#${refreshKey}`} type="payroll" id={batch.id} />
                    {editable && docs.some((doc) => Number(doc.uploaded_by?.id) === Number(user?.id)) && (
                      <OwnDocuments docs={docs.filter((doc) => Number(doc.uploaded_by?.id) === Number(user?.id))} onRemoved={(text) => { actions.setFeedback({ tone: 'ok', text }); reload(); }} onError={(text) => actions.setFeedback({ tone: 'error', text })} />
                    )}
                  </section>
                </>
              )}
            </>
          )}
        </LoadState>

        {projectId && <PayRatesCard key={projectId} projectId={projectId} editable={false} />}

        {projectId && (
          <AssistantRecordPanel projectId={projectId} target={open} refreshKey={refreshKey} actions={actions} onClose={() => update({ open: '' })} />
        )}
      </LoadState>

      {actions.dialog}
      {editingRow && (
        <RowDialog row={editingRow} onClose={() => setEditingRow(null)} onDone={(text) => { setEditingRow(null); actions.setFeedback({ tone: 'ok', text }); reload(); }} />
      )}
    </div>
  );
}

/** Remove one of your own payroll documents while the batch is a draft or returned. */
function OwnDocuments({ docs, onRemoved, onError }) {
  const [busy, setBusy] = useState(null);

  async function remove(doc) {
    setBusy(doc.id);
    try {
      await apiWrite('delete', ENDPOINTS.document(doc.id));
      onRemoved(`Removed ${doc.file_name}.`);
    } catch (error) {
      onError(errorText(error, 'Could not remove the document. Please try again.'));
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="ra-own-docs">
      <span className="program-label">Remove a document you uploaded</span>
      <ul className="ld-doc-list">
        {docs.map((doc) => (
          <li key={doc.id} className="ld-doc">
            <div className="ld-doc-main"><b>{doc.file_name}</b></div>
            <div className="ld-doc-actions">
              <button type="button" className="program-btn sm danger" disabled={busy !== null} onClick={() => remove(doc)}>{busy === doc.id ? 'Removing…' : 'Remove'}</button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

/** Edit one row's basic rate and allowance; the server recomputes the deductions. */
function RowDialog({ row, onClose, onDone }) {
  const [basic, setBasic] = useState(String(row.basic));
  const [allowance, setAllowance] = useState(String(row.allowance));
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function save(event) {
    event.preventDefault();
    if (!(Number(basic) > 0) || !(Number(allowance) >= 0)) {
      setError('Enter a basic rate above zero and an allowance of zero or more.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await apiWrite('patch', ENDPOINTS.payrollEntry(row.id), { basic_rate: Number(basic), allowance: Number(allowance) });
      onDone(`Updated ${row.name}'s payroll row; deductions and net pay were recomputed.`);
    } catch (failure) {
      setError(errorText(failure, 'Could not save. Please try again.'));
      setBusy(false);
    }
  }

  return (
    <div className="program-modal-overlay" role="presentation" onClick={() => { if (!busy) onClose(); }}>
      <form className="program-modal" role="dialog" aria-modal="true" aria-labelledby="ra-row-title" onClick={(event) => event.stopPropagation()} onSubmit={save} noValidate>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ra-row-title">Edit payroll row</div>
            <div className="program-card-sub">{row.name}{row.position ? ` · ${row.position}` : ''}</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>
        <div className="program-form-grid">
          <div className="program-field">
            <label htmlFor="ra-row-basic">Basic rate (₱)</label>
            <input id="ra-row-basic" className="program-input" type="number" min="0.01" step="0.01" value={basic} onChange={(event) => setBasic(event.target.value)} disabled={busy} />
          </div>
          <div className="program-field">
            <label htmlFor="ra-row-allowance">Allowance (₱)</label>
            <input id="ra-row-allowance" className="program-input" type="number" min="0" step="0.01" value={allowance} onChange={(event) => setAllowance(event.target.value)} disabled={busy} />
          </div>
        </div>
        {error && <div className="field-err" role="alert">{error}</div>}
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : 'Save and recompute'}</button>
        </div>
      </form>
    </div>
  );
}
