import { useEffect, useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fetchDocuments, fieldErrors } from '../../api/leader';
import client from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { performWrite } from '../../lib/apiResult';
import { useApiData } from '../../lib/useApiData';
import { todayInManila } from '../../lib/datetime';
import { peso } from '../money';

const EMPTY = { lib_category_id: '', description: '', amount: '', expense_date: '', payee: '', reference_no: '' };
const FIELDS = Object.keys(EMPTY);

/** The stored values as form strings. */
function toForm(values) {
  return Object.fromEntries(FIELDS.map((key) => [key, values?.[key] == null ? '' : String(values[key])]));
}

/** Request body for the fields (only the changed ones when `before` is given). */
function payload(form, before = null) {
  const value = {
    lib_category_id: Number(form.lib_category_id),
    description: form.description.trim(),
    amount: Number(form.amount),
    expense_date: form.expense_date,
    payee: form.payee.trim() || null,
    reference_no: form.reference_no.trim() || null,
  };
  if (!before) return value;
  const old = payload(before);
  return Object.fromEntries(Object.entries(value).filter(([key, v]) => v !== old[key]));
}

async function uploadReceipt(expenseId, file) {
  const body = new FormData();
  body.append('file', file);
  body.append('document_type', 'receipt');
  return performWrite(() => client.post(ENDPOINTS.expenseDocuments(expenseId), body));
}

/**
 * Record transaction (Project Leader), or "Edit and resubmit" when
 * `expense` (a returned queue item they recorded) is given.
 *
 * New: save the expense, attach the receipt, submit.
 * Edit: save the changed fields, remove / add receipts, submit again.
 * Either way the submit skips the Project Leader approval and goes straight
 * to Finance/Admin verification (the server decides this). If a step fails,
 * "Try again" resumes from that step, so nothing is done twice; a new draft
 * left by a failed attempt can be discarded.
 */
export default function RecordExpenseDialog({ projectId, expense = null, onClose, onDone }) {
  const editing = Boolean(expense);
  const { user } = useAuth();
  const balancing = useApiData(ENDPOINTS.balancing(projectId));
  const lineItems = balancing.data?.line_items ?? [];
  const [form, setForm] = useState(editing ? toForm(expense.edit) : { ...EMPTY, expense_date: todayInManila() });
  const [receipt, setReceipt] = useState(null);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  // Progress, so a retry continues where it stopped: { id, patched, removed: [], uploaded }.
  const [saved, setSaved] = useState(editing ? { id: expense.id, patched: false, removed: [], uploaded: false } : null);
  const [documents, setDocuments] = useState(null); // edit: the expense's current documents
  const [toRemove, setToRemove] = useState([]);

  useEffect(() => {
    if (!editing) return undefined;
    let alive = true;
    fetchDocuments('expense', expense.id)
      .then((data) => { if (alive) setDocuments(Array.isArray(data?.documents) ? data.documents : []); })
      .catch(() => { if (alive) setDocuments([]); });
    return () => { alive = false; };
  }, [editing, expense?.id]);

  const chosen = lineItems.find((item) => String(item.id) === form.lib_category_id);
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });
  const keptReceipts = (documents ?? []).filter((doc) => doc.document_type === 'receipt' && !toRemove.includes(doc.id));

  function validate() {
    const next = {};
    if (!form.lib_category_id) next.lib_category_id = 'Choose a line item.';
    if (!form.description.trim()) next.description = 'Describe the expense.';
    if (!(Number(form.amount) > 0)) next.amount = 'Enter an amount greater than zero.';
    if (!form.expense_date) next.expense_date = 'Enter the expense date.';
    const hasReceipt = receipt || saved?.uploaded || (editing && keptReceipts.length > 0);
    if (!hasReceipt) next.file = editing ? 'Keep a receipt or attach a new one (PDF, JPG or PNG).' : 'Attach the receipt (PDF, JPG or PNG).';
    return next;
  }

  async function submit(event) {
    event.preventDefault();
    const next = validate();
    setErrors(next);
    setFormError('');
    if (Object.keys(next).length > 0 || busy) return;

    setBusy(true);
    let step = saved;
    try {
      if (!step) {
        const data = await apiWrite('post', ENDPOINTS.expenses(projectId), payload(form));
        step = { id: data.expense.id, patched: true, removed: [], uploaded: false };
        setSaved(step);
      }
      if (editing && !step.patched) {
        const changes = payload(form, toForm(expense.edit));
        if (Object.keys(changes).length > 0) await apiWrite('patch', ENDPOINTS.expense(step.id), changes);
        step = { ...step, patched: true };
        setSaved(step);
      }
      for (const id of toRemove.filter((docId) => !step.removed.includes(docId))) {
        await apiWrite('delete', ENDPOINTS.document(id));
        step = { ...step, removed: [...step.removed, id] };
        setSaved(step);
      }
      if (receipt && !step.uploaded) {
        await uploadReceipt(step.id, receipt);
        step = { ...step, uploaded: true };
        setSaved(step);
      }
      await apiWrite('post', ENDPOINTS.submitExpense(step.id));
      const what = `“${form.description.trim()}” (${peso(form.amount)})`;
      onDone?.(`${editing ? 'Resubmitted' : 'Recorded'} ${what}. It is waiting for Finance/Admin verification.`);
    } catch (error) {
      setErrors(fieldErrors(error));
      const message = errorText(error, 'Could not save. Please try again.');
      if (editing) setFormError(`${message} “Try again” continues from here.`);
      else setFormError(step
        ? `${message} The expense is saved as a draft: “Try again” continues from here, “Discard draft” deletes it.`
        : message);
    } finally {
      setBusy(false);
    }
  }

  /** Close. A new draft saved by a failed attempt is deleted so none is left behind; an edited expense stays returned. */
  async function close() {
    if (busy) return;
    if (editing || !saved) {
      onClose();
      return;
    }
    setBusy(true);
    try {
      await apiWrite('delete', ENDPOINTS.expense(saved.id));
      onClose();
    } catch (error) {
      setFormError(errorText(error, 'Could not discard the draft. Please try again.'));
      setBusy(false);
    }
  }

  const err = (name) => errors[name] && <div className="field-err" role="alert">{errors[name]}</div>;
  // Once the fields are saved on the server, a retry does not change them again.
  const locked = busy || (editing ? Boolean(saved?.patched) : Boolean(saved));
  const discardable = !editing && Boolean(saved);

  return (
    <div className="program-modal-overlay" role="presentation" onClick={close}>
      <form
        className="program-modal wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ld-expense-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={submit}
        noValidate
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ld-expense-title">{editing ? 'Edit and resubmit' : 'Record transaction'}</div>
            <div className="program-card-sub">Your expense goes straight to Finance/Admin for verification</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={close} aria-label={discardable ? 'Discard draft and close' : 'Close'} disabled={busy}>×</button>
        </div>

        {editing && expense.return_reason && (
          <div className="program-banner error ld-return-banner" role="note">
            <b>Returned:</b> “{expense.return_reason}”
          </div>
        )}
        {balancing.status === 'error' && (
          <div className="program-banner error" role="alert">
            Could not load the line items. <button type="button" className="program-btn sm" onClick={balancing.retry}>Try again</button>
          </div>
        )}
        {formError && <div className="program-banner error" role="alert">{formError}</div>}

        <div className="program-form-grid">
          <div className="program-field full">
            <label htmlFor="ld-exp-line">Line item</label>
            <select id="ld-exp-line" className="program-select" value={form.lib_category_id} onChange={set('lib_category_id')} disabled={locked || balancing.status !== 'ready'}>
              <option value="">{balancing.status === 'ready' ? 'Choose a line item' : 'Loading…'}</option>
              {lineItems.map((item) => (
                <option key={item.id} value={item.id}>{item.type} · {item.name} — {peso(item.available)} available</option>
              ))}
            </select>
            {chosen && Number(form.amount) > Number(chosen.available) && (
              <div className="ld-hint is-risk">More than the {peso(chosen.available)} available on this line item.</div>
            )}
            {err('lib_category_id')}
          </div>
          <div className="program-field full">
            <label htmlFor="ld-exp-desc">Description</label>
            <input id="ld-exp-desc" className="program-input" maxLength={500} value={form.description} onChange={set('description')} disabled={locked} />
            {err('description')}
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-amount">Amount (₱)</label>
            <input id="ld-exp-amount" className="program-input" type="number" min="0.01" step="0.01" value={form.amount} onChange={set('amount')} disabled={locked} />
            {err('amount')}
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-date">Expense date</label>
            <input id="ld-exp-date" className="program-input" type="date" value={form.expense_date} onChange={set('expense_date')} disabled={locked} />
            {err('expense_date')}
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-payee">Payee <span className="ld-muted">optional</span></label>
            <input id="ld-exp-payee" className="program-input" maxLength={255} value={form.payee} onChange={set('payee')} disabled={locked} />
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-ref">Reference no. (OR / voucher) <span className="ld-muted">optional</span></label>
            <input id="ld-exp-ref" className="program-input" maxLength={100} value={form.reference_no} onChange={set('reference_no')} disabled={locked} />
          </div>

          {editing && (
            <div className="program-field full">
              <span className="program-label">Attached documents</span>
              {documents === null ? (
                <p className="ld-muted" aria-busy="true">Loading documents…</p>
              ) : documents.length === 0 ? (
                <p className="ld-muted">No documents attached.</p>
              ) : (
                <ul className="ld-doc-list">
                  {documents.map((doc) => {
                    const removing = toRemove.includes(doc.id);
                    const mine = Number(doc.uploaded_by?.id) === Number(user?.id);
                    return (
                      <li key={doc.id} className="ld-doc">
                        <div className="ld-doc-main">
                          <b className={removing ? 'ld-struck' : ''}>{doc.file_name}</b>
                          <span>{doc.document_type === 'receipt' ? 'Receipt' : doc.document_type}{removing ? ' · will be removed' : ''}</span>
                        </div>
                        {mine && !saved?.removed.includes(doc.id) && (
                          <div className="ld-doc-actions">
                            <button
                              type="button"
                              className="program-btn sm"
                              disabled={busy}
                              onClick={() => setToRemove(removing ? toRemove.filter((id) => id !== doc.id) : [...toRemove, doc.id])}
                            >
                              {removing ? 'Keep' : 'Remove'}
                            </button>
                          </div>
                        )}
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          )}

          <div className="program-field full">
            <label htmlFor="ld-exp-file">
              {editing ? 'Add or replace the receipt' : 'Receipt'}{' '}
              {editing ? <span className="ld-muted">optional when a receipt is kept</span> : <span className="ld-required">required</span>}
            </label>
            <input
              id="ld-exp-file"
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              onChange={(event) => setReceipt(event.target.files?.[0] ?? null)}
              disabled={busy || saved?.uploaded}
            />
            {saved?.uploaded && <div className="ld-hint">Receipt attached.</div>}
            {err('file')}
            {err('documents')}
          </div>
        </div>

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={close} disabled={busy}>{discardable ? 'Discard draft' : 'Cancel'}</button>
          <button type="submit" className="program-btn primary" disabled={busy || (editing && documents === null)}>
            {busy ? 'Saving…' : (editing ? (saved?.patched ? 'Try again' : 'Resubmit') : (saved ? 'Try again' : 'Record and submit'))}
          </button>
        </div>
      </form>
    </div>
  );
}
