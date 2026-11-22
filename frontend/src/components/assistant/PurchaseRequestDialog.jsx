import { useEffect, useState } from 'react';
import { nextStep } from '../../lib/submitMessage';
import FileField from '../FileField';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fetchDocuments, fieldErrors } from '../../api/leader';
import { uploadDocument } from '../../api/assistant';
import { useAuth } from '../../context/AuthContext';
import { useApiData } from '../../lib/useApiData';
import { peso } from '../money';
import MoneyInput from '../MoneyInput';
import { moneyError, moneyNumber, moneyText } from '../../lib/moneyInput';

const GENERIC_MONEY = ['Enter an amount.', 'The amount must be more than zero.'];

const EMPTY = { lib_category_id: '', description: '', quantity: '1', estimated_amount: '', supplier: '' };
const FIELDS = Object.keys(EMPTY);
const SUPPORTING = ['quotation', 'invoice'];
const TYPE_LABEL = { quotation: 'Quotation', invoice: 'Invoice', other: 'Other' };

function toForm(values) {
  const form = Object.fromEntries(FIELDS.map((key) => [key, values?.[key] == null ? '' : String(values[key])]));
  return { ...form, estimated_amount: moneyText(values?.estimated_amount) };
}

function payload(form, before = null) {
  const value = {
    lib_category_id: Number(form.lib_category_id),
    description: form.description.trim(),
    quantity: Number(form.quantity),
    estimated_amount: moneyNumber(form.estimated_amount),
    supplier: form.supplier.trim() || null,
  };
  if (!before) return value;
  const old = payload(before);
  return Object.fromEntries(Object.entries(value).filter(([key, v]) => v !== old[key]));
}

/**
 * Create a purchase request, or edit a draft / returned one (`request` with
 * `edit` values). Save as draft, or save + attach + submit in one click.
 * Submitting needs a quotation or invoice (the server checks it too). If a
 * step fails, "Try again" resumes from that step; a new draft is kept
 * unless "Discard draft" is chosen.
 */
export default function PurchaseRequestDialog({ projectId, request = null, onClose, onDone }) {
  const editing = Boolean(request);
  const { user } = useAuth();
  const balancing = useApiData(ENDPOINTS.balancing(projectId));
  const lineItems = balancing.data?.line_items ?? [];
  const [form, setForm] = useState(editing ? toForm(request.edit) : EMPTY);
  const [file, setFile] = useState(null);
  const [fileType, setFileType] = useState('quotation');
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const [saved, setSaved] = useState(editing ? { id: request.id, patched: false, removed: [], uploaded: false } : null);
  const [documents, setDocuments] = useState(null);
  const [toRemove, setToRemove] = useState([]);

  useEffect(() => {
    if (!editing) return undefined;
    let alive = true;
    fetchDocuments('purchase_request', request.id)
      .then((data) => { if (alive) setDocuments(Array.isArray(data?.documents) ? data.documents : []); })
      .catch(() => { if (alive) setDocuments([]); });
    return () => { alive = false; };
  }, [editing, request?.id]);

  const chosen = lineItems.find((item) => String(item.id) === form.lib_category_id);
  const keptSupporting = (documents ?? []).filter((doc) => SUPPORTING.includes(doc.document_type) && !toRemove.includes(doc.id));
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  function validate(submitting) {
    const next = {};
    if (!form.lib_category_id) next.lib_category_id = 'Choose a line item.';
    if (!form.description.trim()) next.description = 'Describe what is needed.';
    if (!(Number.isInteger(Number(form.quantity)) && Number(form.quantity) >= 1)) next.quantity = 'Enter a whole number of at least 1.';
    const amountError = moneyError(form.estimated_amount, { positive: true });
    if (amountError) next.estimated_amount = GENERIC_MONEY.includes(amountError) ? 'Enter an amount greater than zero.' : amountError;
    if (form.supplier.trim().length > 255) next.supplier = 'Use at most 255 characters.';
    const hasSupporting = (file && SUPPORTING.includes(fileType)) || (saved?.uploaded && SUPPORTING.includes(fileType)) || keptSupporting.length > 0;
    if (submitting && !hasSupporting) next.file = 'Attach a quotation or invoice before submitting.';
    return next;
  }

  async function run(submitting) {
    const next = validate(submitting);
    setErrors(next);
    setFormError('');
    if (Object.keys(next).length > 0 || busy) return;

    setBusy(true);
    let step = saved;
    try {
      if (!step) {
        const data = await apiWrite('post', ENDPOINTS.purchaseRequests(projectId), payload(form));
        step = { id: data.purchase_request.id, patched: true, removed: [], uploaded: false };
        setSaved(step);
      }
      if (editing && !step.patched) {
        const changes = payload(form, toForm(request.edit));
        if (Object.keys(changes).length > 0) await apiWrite('patch', ENDPOINTS.purchaseRequest(step.id), changes);
        step = { ...step, patched: true };
        setSaved(step);
      }
      for (const id of toRemove.filter((docId) => !step.removed.includes(docId))) {
        await apiWrite('delete', ENDPOINTS.document(id));
        step = { ...step, removed: [...step.removed, id] };
        setSaved(step);
      }
      if (file && !step.uploaded) {
        await uploadDocument('purchase_request', step.id, file, fileType);
        step = { ...step, uploaded: true };
        setSaved(step);
      }
      const what = `“${form.description.trim()}” (${peso(moneyNumber(form.estimated_amount))})`;
      if (!submitting) {
        onDone?.(editing ? `Saved your changes to ${what}.` : `Saved ${what} as a draft.`);
        return;
      }
      const data = await apiWrite('post', ENDPOINTS.purchaseRequestStep(step.id, 'submit'));
      onDone?.(`${editing && request.workflow_status === 'returned' ? 'Resubmitted' : 'Submitted'} ${what}. ${nextStep(data?.message)}`);
    } catch (error) {
      setErrors(fieldErrors(error));
      const message = errorText(error, 'Could not save. Please try again.');
      setFormError(!editing && step
        ? `${message} The request is saved as a draft: “Try again” continues from here, “Discard draft” deletes it.`
        : `${message}${step ? ' “Try again” continues from here.' : ''}`);
    } finally {
      setBusy(false);
    }
  }

  async function discard() {
    if (busy || !saved || editing) return;
    setBusy(true);
    try {
      await apiWrite('delete', ENDPOINTS.purchaseRequest(saved.id));
      onClose();
    } catch (error) {
      setFormError(errorText(error, 'Could not discard the draft. Please try again.'));
      setBusy(false);
    }
  }

  const close = () => { if (!busy) onClose(); };
  const err = (name) => errors[name] && <div className="field-err" role="alert">{errors[name]}</div>;
  const locked = busy || (editing ? Boolean(saved?.patched) : Boolean(saved));
  const discardable = !editing && Boolean(saved);
  const retrying = editing ? Boolean(saved?.patched) : Boolean(saved);
  const returned = editing && request.workflow_status === 'returned';

  return (
    <div className="program-modal-overlay" role="presentation" onClick={close}>
      <form
        className="program-modal wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ra-pr-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={(event) => { event.preventDefault(); run(true); }}
        noValidate
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ra-pr-title">{returned ? 'Edit and resubmit' : editing ? 'Edit purchase request' : 'New purchase request'}</div>
            <div className="program-card-sub">The Project Leader approves it; then record the expense and link it</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={close} aria-label="Close" disabled={busy}>×</button>
        </div>

        {returned && request.return_reason && (
          <div className="program-banner error ld-return-banner" role="note">
            <b>Returned{request.returned_by?.name ? ` by ${request.returned_by.name}` : ''}:</b> “{request.return_reason}”
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
            <label htmlFor="ra-pr-line">Line item</label>
            <select id="ra-pr-line" className="program-select" value={form.lib_category_id} onChange={set('lib_category_id')} disabled={locked || balancing.status !== 'ready'}>
              <option value="">{balancing.status === 'ready' ? 'Choose a line item' : 'Loading…'}</option>
              {lineItems.map((item) => (
                <option key={item.id} value={item.id}>{item.type} · {item.name} — {peso(item.available)} available</option>
              ))}
            </select>
            {chosen && moneyNumber(form.estimated_amount) > Number(chosen.available) && (
              <div className="ld-hint is-risk" role="status">⚠ More than the {peso(chosen.available)} available on this line item.</div>
            )}
            {err('lib_category_id')}
          </div>
          <div className="program-field full">
            <label htmlFor="ra-pr-desc">Description</label>
            <input id="ra-pr-desc" className="program-input" autoComplete="off" maxLength={500} value={form.description} onChange={set('description')} disabled={locked} />
            {err('description')}
          </div>
          <div className="program-field">
            <label htmlFor="ra-pr-qty">Quantity</label>
            <input id="ra-pr-qty" className="program-input" autoComplete="off" type="number" min="1" step="1" value={form.quantity} onChange={set('quantity')} disabled={locked} />
            {err('quantity')}
          </div>
          <div className="program-field">
            <label htmlFor="ra-pr-amount">Estimated amount (₱)</label>
            <MoneyInput id="ra-pr-amount" value={form.estimated_amount} onChange={set('estimated_amount')} disabled={locked} />
            {err('estimated_amount')}
          </div>
          <div className="program-field full">
            <label htmlFor="ra-pr-supplier">Supplier / payee <span className="ld-muted">optional</span></label>
            <input id="ra-pr-supplier" className="program-input" autoComplete="off" maxLength={255} value={form.supplier} onChange={set('supplier')} disabled={locked} />
            <div className="ld-hint">Fills the payee when an expense is linked to this request.</div>
            {err('supplier')}
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
                          <span>{TYPE_LABEL[doc.document_type] ?? doc.document_type}{removing ? ' · will be removed' : ''}</span>
                        </div>
                        {mine && !saved?.removed.includes(doc.id) && (
                          <div className="ld-doc-actions">
                            <button type="button" className="program-btn sm" disabled={busy}
                              onClick={() => setToRemove(removing ? toRemove.filter((id) => id !== doc.id) : [...toRemove, doc.id])}>
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

          <div className="program-field">
            <label htmlFor="ra-pr-filetype">Document type</label>
            <select id="ra-pr-filetype" className="program-select" value={fileType} onChange={(event) => setFileType(event.target.value)} disabled={busy || saved?.uploaded}>
              {Object.entries(TYPE_LABEL).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </div>
          <div className="program-field">
            <FileField
              id="ra-pr-file"
              label={<>Quotation or invoice {editing && keptSupporting.length > 0 ? <span className="ld-muted">optional, one is kept</span> : <span className="ld-required">required to submit</span>}</>}
              disabled={busy || saved?.uploaded}
              onPreparing={setPreparing}
              onChange={(prepared, problem) => {
                setFile(prepared);
                setErrors((current) => ({ ...current, file: problem ?? undefined }));
              }}
            />
            {saved?.uploaded && <div className="ld-hint">Document attached.</div>}
            {err('file')}
            {err('documents')}
          </div>
        </div>

        <div className="program-modal-actions">
          {discardable ? (
            <button type="button" className="program-btn danger" onClick={discard} disabled={busy}>Discard draft</button>
          ) : (
            <button type="button" className="program-btn" onClick={close} disabled={busy}>Cancel</button>
          )}
          <button type="button" className="program-btn" onClick={() => run(false)} disabled={busy || preparing || (editing && documents === null)}>
            {editing ? 'Save changes' : saved ? 'Save draft' : 'Save as draft'}
          </button>
          <button type="submit" className="program-btn primary" disabled={busy || preparing || (editing && documents === null)}>
            {busy ? 'Saving…' : retrying ? 'Try again' : returned ? 'Resubmit' : 'Save, attach and submit'}
          </button>
        </div>
      </form>
    </div>
  );
}
