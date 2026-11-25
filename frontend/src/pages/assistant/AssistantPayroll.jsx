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
import { formatDate, todayInManila } from '../../lib/datetime';
import { downloadPayrollRegisterCsv, fetchPayrollRegister } from '../../api/reports';
import { printHtmlDocument, saveBlob } from '../../lib/download';
import { registerDocumentHtml } from '../../lib/payrollRegister';
import LoadState from '../../components/LoadState';
import ProjectLine from '../../components/ProjectLine';
import DocumentList from '../../components/leader/DocumentList';
import AssistantRecordPanel from '../../components/assistant/AssistantRecordPanel';
import StagePill from '../../components/assistant/StagePill';
import { useRecordActions } from '../../components/assistant/useRecordActions';
import { peso } from '../../components/money';
import PersonnelSection from '../../components/personnel/PersonnelSection';
import MoneyInput from '../../components/MoneyInput';
import { moneyNumber, moneyText, parseMoney } from '../../lib/moneyInput';
import { MONTH_BOUNDS, MONTH_MAX, inDateRange } from '../../lib/dateRange';

const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;
// A pay period month, limited to 2000-2100 like every date input.
const MONTH_OK = { test: (value) => MONTH_RE.test(value) && inDateRange(value) };

/**
 * Payroll (Research Assistant): prepare the batch for a pay period of
 * consecutive months (one month or a quarter) for the project. The server
 * computes each row from Personnel: monthly honorarium x months the
 * appointment is effective in the period, e.g. "(8,800 x 3 months) =
 * 26,400.00". Project honoraria have no deductions and no allowances, so
 * net = gross. Review the rows, upload the payroll file and submit; edit and
 * resubmit when it is returned. Nothing is computed here.
 */
export default function AssistantPayroll() {
  const { user } = useAuth();
  const picker = useAssignedProject({ role: 'research-assistant' });
  const { get, update } = useUrlQuery();
  const month = MONTH_OK.test(get('month')) ? get('month') : todayInManila().slice(0, 7);
  const toParam = MONTH_OK.test(get('to')) && get('to') >= month ? get('to') : month;
  const open = parseOpen(get('open'));
  const projectId = picker.projectId;

  const payroll = useApiData(projectId ? `${ENDPOINTS.payroll(projectId)}?month=${month}&to=${toParam}` : null);
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
  const docs = Array.isArray(documents.data?.documents) ? documents.data.documents : [];
  const hasPayrollFile = docs.some((doc) => doc.document_type === 'payroll_file');
  const editable = batch ? batch.editable : true;
  const record = item.data?.item ?? null;
  const chosenLine = lineItemId || (psItems[0] ? String(psItems[0].id) : '');
  const label = run?.month_label ?? month;
  // The period shown: the batch's own period, or the chosen months.
  const from = run?.month ?? month;
  const to = run?.to ?? toParam;
  const missingPeople = Math.max(0, (Number(run?.rated_members) || 0) - rows.length);
  const notIncluded = Array.isArray(run?.not_included) ? run.not_included : [];
  const notEffective = notIncluded.filter((person) => person.reason === 'not_effective');
  const zeroPay = notIncluded.filter((person) => person.reason === 'zero');
  const legacy = Boolean(run?.legacy_amounts);
  const needsRecompute = Boolean(batch?.needs_recompute);
  // Line items this batch would overspend: submit is refused until it fits.
  const overBalance = Array.isArray(batch?.over_balance) ? batch.over_balance : [];

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

  // The server's message names anyone left out ("Not included: appointment not effective: …").
  const prepare = () => act('prepare', () => apiWrite('post', ENDPOINTS.payroll(projectId), { month: from, to, lib_category_id: Number(chosenLine) }), (data) => `${data?.message ?? `Payroll for ${label} prepared and computed.`} Review the rows, upload the payroll file, then submit.`);
  const recompute = () => act('compute', () => apiWrite('post', ENDPOINTS.computePayroll(projectId), { month: from }), (data) => data?.message ?? `Payroll for ${label} recomputed from Personnel.`);
  const submit = () => act('submit', () => apiWrite('post', ENDPOINTS.payrollBatchStep(batch.id, 'submit')), `Payroll for ${label} submitted.`);
  // Payroll register of this month: offered only once the batch is verified
  // (the server lists verified batches only).
  const verified = batch?.workflow_status === 'verified';
  const escapeHtml = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  async function download(key, send, okText) {
    setBusy(key);
    actions.setFeedback(null);
    try {
      await send();
      actions.setFeedback({ tone: 'ok', text: okText });
    } catch {
      actions.setFeedback({ tone: 'error', text: 'Could not download the payroll register. Please try again.' });
    } finally {
      setBusy(null);
    }
  }
  const registerPdf = () => download('register-pdf', async () => {
    const register = await fetchPayrollRegister(projectId, { month: from });
    printHtmlDocument(registerDocumentHtml(register, { escape: escapeHtml, money: peso, generated: formatDate(new Date()) }));
  }, `Payroll register for ${label} opened for printing. Choose "Save as PDF" to keep a copy.`);
  const registerCsv = () => download('register-csv', async () => {
    const { blob, filename } = await downloadPayrollRegisterCsv(projectId, { month: from });
    saveBlob(blob, filename);
  }, `Payroll register for ${label} downloaded (CSV).`);

  const batchRecord = batch ? { key: `payroll-${batch.id}`, type: 'payroll', id: batch.id, type_label: 'Payroll', reference: batch.reference ?? `PAY-${from}`, title: `Payroll ${label}` } : null;

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <h1 className="program-h1">Payroll</h1>
          <ProjectLine picker={picker} />
        </div>
        {projectId && (
          <div className="pl-head-actions">
            <div className="program-field">
              <label htmlFor="ra-payroll-month">First month</label>
              <input id="ra-payroll-month" className="program-input" type="month" {...MONTH_BOUNDS} value={month}
                onChange={(event) => { if (MONTH_OK.test(event.target.value)) { actions.setFeedback(null); update({ month: event.target.value, to: event.target.value > toParam ? event.target.value : toParam }); } }} />
            </div>
            <div className="program-field">
              <label htmlFor="ra-payroll-to">Last month</label>
              <input id="ra-payroll-to" className="program-input" type="month" value={toParam} min={month} max={MONTH_MAX}
                onChange={(event) => { if (MONTH_OK.test(event.target.value) && event.target.value >= month) { actions.setFeedback(null); update({ to: event.target.value }); } }} />
            </div>
          </div>
        )}
      </div>

      {actions.feedback && (
        <div className={`program-banner ${actions.feedback.tone}`} role="status" aria-live="polite">{actions.feedback.text}</div>
      )}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={payroll} loadingText="Loading payroll…">
          {run && (
            <>
              {notEffective.length > 0 && (
                <div className="program-banner info" role="status">
                  <b><span aria-hidden="true">ⓘ</span> Not included (appointment not effective in {label}):</b> {notEffective.map((person) => person.name).join(', ')}
                </div>
              )}

              {zeroPay.length > 0 && (
                <div className="program-banner info" role="status">
                  <b><span aria-hidden="true">ⓘ</span> Not included (no honorarium set):</b> {zeroPay.map((person) => person.name).join(', ')}. Ask the Program Leader.
                </div>
              )}

              {overBalance.length > 0 && (
                <div className="program-banner error" role="alert">
                  <b><span aria-hidden="true">⚠</span> This payroll is more than the line item has available.</b>
                  {overBalance.map((text) => <div key={text}>{text}</div>)}
                </div>
              )}

              {needsRecompute && (
                <div className="program-banner error" role="alert">
                  <b><span aria-hidden="true">⚠</span> Recompute this payroll before submitting.</b> It was computed with deductions or allowances, which project honoraria do not have.
                </div>
              )}

              {!batch ? (
                <section className="program-card">
                  <div className="program-card-head">
                    <div>
                      <div className="program-card-title">Prepare the {label} payroll</div>
                      <div className="program-card-sub">Honorarium × months effective</div>
                    </div>
                  </div>
                  {run.overlap_text ? (
                    <p className="ld-empty" role="alert"><span aria-hidden="true">⊘</span> {run.overlap_text}</p>
                  ) : Number(run.rated_members) === 0 ? (
                    <p className="ld-empty">No one on this project has an appointment effective in {label} with a monthly honorarium. Ask your Program Leader to add personnel.</p>
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
                        <div className="program-card-sub">{rows.length} {rows.length === 1 ? 'person' : 'people'} · {batch.months} {batch.months === 1 ? 'month' : 'months'} · {batch.reference}</div>
                      </div>
                      <StagePill status={batch.workflow_status} />
                    </div>

                    {record && returnedLine(record) ? (
                      <div className="program-banner error ld-return-banner" role="note"><b>Returned.</b> {returnedLine(record)}</div>
                    ) : (record?.next_step || batch.waiting_text) && (
                      <p className="ld-waiting"><span aria-hidden="true">◷</span> {batch.escalated_step ? batch.waiting_text : (record?.next_step ?? batch.waiting_text)}</p>
                    )}

                    {verified && (
                      <div className="ra-inline-form" role="group" aria-label="Download payroll register">
                        <span className="ld-muted">Download payroll register</span>
                        <button type="button" className="program-btn" disabled={busy !== null} onClick={registerPdf}>
                          {busy === 'register-pdf' ? 'Preparing…' : 'PDF'}
                        </button>
                        <button type="button" className="program-btn" disabled={busy !== null} onClick={registerCsv}>
                          {busy === 'register-csv' ? 'Downloading…' : 'CSV'}
                        </button>
                      </div>
                    )}
                    {!verified && <p className="ld-hint">Payroll register: available once verified.</p>}

                    <div className="ra-payroll-totals">
                      <div className="ra-balance"><span>Gross</span><b>{peso(run.totals.gross)}</b></div>
                      {legacy && <div className="ra-balance"><span>Recorded deductions</span><b>{peso(run.totals.recorded_deductions)}</b></div>}
                      <div className="ra-balance is-available"><span>Net pay</span><b>{peso(run.totals.net)}</b></div>
                    </div>

                    <div className="ra-table-scroll">
                      <table className="program-table ld-table">
                        <caption className="pa-sr-only">Payroll rows</caption>
                        <thead>
                          <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Computation</th>
                            <th scope="col" className="num">Gross</th>
                            {legacy && <th scope="col" className="num">Recorded deductions</th>}
                            <th scope="col" className="num">Net</th>
                            {editable && <th scope="col"><span className="pa-sr-only">Actions</span></th>}
                          </tr>
                        </thead>
                        <tbody>
                          {rows.map((row) => (
                            <tr key={row.id}>
                              <td>
                                <div className="project-name">{row.name}</div>
                                <div className="project-code">{[row.position, row.category].filter(Boolean).join(' · ')}</div>
                              </td>
                              <td>{row.computation}</td>
                              <td className="num">{peso(row.gross)}</td>
                              {legacy && <td className="num">{peso(row.recorded_deductions)}</td>}
                              <td className="num ld-amount">{peso(row.net)}</td>
                              {editable && (
                                <td><button type="button" className="program-btn sm" onClick={() => setEditingRow(row)}>Edit</button></td>
                              )}
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {legacy && (
                      <p className="ld-muted">Recorded before deductions and allowances were removed; a verified payroll stays as recorded.</p>
                    )}

                    {editable && (
                      <div className="ld-panel-actions ra-panel-actions">
                        <button type="button" className="program-btn" disabled={busy !== null} onClick={recompute}>{busy === 'compute' ? 'Computing…' : 'Recompute'}</button>
                        {missingPeople > 0 && psItems.length > 0 && (
                          <button type="button" className="program-btn" disabled={busy !== null} onClick={prepare}>
                            Add {missingPeople} {missingPeople === 1 ? 'person' : 'people'} effective in this period
                          </button>
                        )}
                        <button type="button" className="program-btn" onClick={() => actions.upload(batchRecord, { title: 'Upload payroll file' })}>Upload payroll file</button>
                        <button
                          type="button"
                          className="program-btn primary"
                          disabled={busy !== null || needsRecompute || overBalance.length > 0 || !hasPayrollFile || rows.length === 0}
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

        {projectId && <PersonnelSection key={projectId} projectId={projectId} />}

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

/** Edit one row's monthly honorarium; the server recomputes honorarium x months (net = gross). */
function RowDialog({ row, onClose, onDone }) {
  const [basic, setBasic] = useState(moneyText(row.basic));
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const months = Number(row.months) || 1;

  async function save(event) {
    event.preventDefault();
    if (parseMoney(basic).error || !(moneyNumber(basic) > 0)) {
      setError(parseMoney(basic).error ?? 'Enter a monthly honorarium above zero.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await apiWrite('patch', ENDPOINTS.payrollEntry(row.id), { basic_rate: moneyNumber(basic) });
      onDone(`Updated ${row.name}'s payroll row; the amount was recomputed (honorarium x ${months} ${months === 1 ? 'month' : 'months'}).`);
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
            <div className="program-card-sub">{row.name}{row.position ? ` · ${row.position}` : ''} · {row.computation}</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>
        <div className="program-form-grid">
          <div className="program-field">
            <label htmlFor="ra-row-basic">Monthly honorarium (₱), for {months} {months === 1 ? 'month' : 'months'}</label>
            <MoneyInput id="ra-row-basic" value={basic} onChange={(event) => setBasic(event.target.value)} disabled={busy} />
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
