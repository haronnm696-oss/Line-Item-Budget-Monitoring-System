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
import { expenseLineItems } from '../../lib/expenseForm';
import { COUNTERPART, FUNDER, effectiveSource, hasCounterpart, sourceAvailable, sourceName } from '../../lib/fundingSource';
import {
  UNITS, emptyItem, itemCost, itemErrors, itemsPayload, itemsToForm, itemsTotal, totalsByLine,
} from '../../lib/purchaseRequestItems';

const SUPPORTING = ['quotation', 'invoice'];
const TYPE_LABEL = { quotation: 'Quotation', invoice: 'Invoice', other: 'Other' };

function toForm(values) {
  return {
    purpose: values?.purpose == null ? '' : String(values.purpose),
    supplier: values?.supplier == null ? '' : String(values.supplier),
    funding_source: values?.funding_source || FUNDER,
    items: itemsToForm(values?.items),
  };
}

function payload(form, before = null) {
  const value = {
    purpose: form.purpose.trim(),
    supplier: form.supplier.trim() || null,
    funding_source: form.funding_source || FUNDER,
    items: itemsPayload(form.items),
  };
  if (!before) return value;
  const old = payload(before);
  return Object.fromEntries(Object.entries(value).filter(([key, v]) => JSON.stringify(v) !== JSON.stringify(old[key])));
}

/**
 * Create a purchase request, or edit a draft / returned one (`request` with
 * `edit` values), as MSU's Purchase Request form: purpose, supplier, funding
 * source and one or more items, each charged to its own line item
 * (honoraria lines are paid through Payroll and not offered). Estimated
 * cost = quantity x unit cost, read-only; the total shows live. Save as
 * draft, or save + attach + submit in one click. Submitting needs a
 * quotation or invoice (the server checks it and each line item's balance
 * too). If a step fails, "Try again" resumes from that step; a new draft is
 * kept unless "Discard draft" is chosen.
 */
export default function PurchaseRequestDialog({ projectId, request = null, onClose, onDone }) {
  const editing = Boolean(request);
  const { user } = useAuth();
  const balancing = useApiData(ENDPOINTS.balancing(projectId));
  const lineItems = expenseLineItems(balancing.data?.line_items ?? []);
  const labels = balancing.data?.labels;
  const [form, setForm] = useState(editing ? toForm(request.edit) : toForm(null));
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

  const keptSupporting = (documents ?? []).filter((doc) => SUPPORTING.includes(doc.document_type) && !toRemove.includes(doc.id));
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });
  const setItem = (index, key) => (event) => {
    const items = form.items.map((item, i) => (i === index ? { ...item, [key]: event.target.value } : item));
    setForm({ ...form, items });
  };
  const addItem = () => setForm({ ...form, items: [...form.items, emptyItem()] });
  const removeItem = (index) => {
    setForm({ ...form, items: form.items.filter((_, i) => i !== index) });
    setErrors({});
  };

  const total = itemsTotal(form.items);
  const byLine = totalsByLine(form.items);
  const chosenLines = lineItems.filter((item) => byLine[String(item.id)] !== undefined);
  const fundedByShown = chosenLines.some((item) => hasCounterpart(item));
  // Line items whose items add up to more than is available (the server checks too).
  const overBudget = chosenLines
    .map((item) => ({ item, amount: byLine[String(item.id)], available: sourceAvailable(item, effectiveSource(item, form.funding_source)) }))
    .filter((row) => row.amount > row.available);

  function validate(submitting) {
    const next = {};
    if (!form.purpose.trim()) next.description = 'Enter the purpose of this request.';
    if (form.supplier.trim().length > 255) next.supplier = 'Use at most 255 characters.';
    if (form.items.length === 0) next.items = 'Add at least one item.';
    itemErrors(form.items).forEach((rowErrors, index) => {
      for (const [key, message] of Object.entries(rowErrors)) next[`items.${index}.${key}`] = message;
    });
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
      const count = form.items.length;
      const what = `“${form.purpose.trim()}” (${count} ${count === 1 ? 'item' : 'items'}, ${peso(total)})`;
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
            <div className="program-card-sub">The Project Leader approves it; then record an expense per line item and link it</div>
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
            <label htmlFor="ra-pr-purpose">Purpose / remarks</label>
            <input id="ra-pr-purpose" className="program-input" autoComplete="off" maxLength={500} value={form.purpose} onChange={set('purpose')} disabled={locked} />
            {err('description')}
          </div>
          <div className="program-field full">
            <label htmlFor="ra-pr-supplier">Supplier / payee <span className="ld-muted">optional</span></label>
            <input id="ra-pr-supplier" className="program-input" autoComplete="off" maxLength={255} value={form.supplier} onChange={set('supplier')} disabled={locked} />
            <div className="ld-hint">Fills the payee when an expense is linked to this request.</div>
            {err('supplier')}
          </div>
          {fundedByShown && (
            <fieldset className="program-field full ld-funded-by">
              <legend className="program-label">Funded by</legend>
              {[FUNDER, COUNTERPART].map((value) => (
                <label key={value} className="ld-radio">
                  <input type="radio" name="ra-pr-source" value={value} checked={form.funding_source === value}
                    onChange={() => setForm({ ...form, funding_source: value })} disabled={locked} />
                  <span>{sourceName(value, labels)}</span>
                </label>
              ))}
              <div className="ld-hint">Items on a line item without a counterpart are charged to the funder.</div>
              {err('funding_source')}
            </fieldset>
          )}

          <div className="program-field full">
            <span className="program-label" id="ra-pr-items-label">Items</span>
            <ol className="pr-item-list" aria-labelledby="ra-pr-items-label">
              {form.items.map((item, index) => {
                const id = (key) => `ra-pr-${key}-${index}`;
                const e = (key) => err(`items.${index}.${key}`);
                return (
                  <li key={index}>
                    <fieldset className="pr-item">
                      <legend>Item {index + 1}</legend>
                      <div className="pr-item-grid">
                        <div className="program-field full">
                          <label htmlFor={id('line')}>Line item</label>
                          <select id={id('line')} className="program-select" value={item.lib_category_id} onChange={setItem(index, 'lib_category_id')} disabled={locked || balancing.status !== 'ready'}>
                            <option value="">{balancing.status === 'ready' ? 'Choose a line item' : 'Loading…'}</option>
                            {lineItems.map((line) => (
                              <option key={line.id} value={line.id}>{line.type} · {line.name} — {peso(sourceAvailable(line, effectiveSource(line, form.funding_source)))} available</option>
                            ))}
                          </select>
                          {e('lib_category_id')}
                        </div>
                        <div className="program-field full">
                          <label htmlFor={id('desc')}>Description</label>
                          <input id={id('desc')} className="program-input" autoComplete="off" maxLength={500} value={item.description} onChange={setItem(index, 'description')} disabled={locked} />
                          {e('description')}
                        </div>
                        <div className="program-field">
                          <label htmlFor={id('qty')}>Quantity</label>
                          <input id={id('qty')} className="program-input" autoComplete="off" type="number" min="1" step="1" value={item.quantity} onChange={setItem(index, 'quantity')} disabled={locked} />
                          {e('quantity')}
                        </div>
                        <div className="program-field">
                          <label htmlFor={id('unit')}>Unit</label>
                          <select id={id('unit')} className="program-select" value={item.unit} onChange={setItem(index, 'unit')} disabled={locked}>
                            {UNITS.map((unit) => <option key={unit} value={unit}>{unit}</option>)}
                          </select>
                          {e('unit')}
                        </div>
                        <div className="program-field">
                          <label htmlFor={id('cost')}>Est. unit cost (₱)</label>
                          <MoneyInput id={id('cost')} value={item.unit_cost} onChange={setItem(index, 'unit_cost')} disabled={locked} />
                          {e('unit_cost')}
                        </div>
                        <div className="program-field pr-item-cost">
                          <span className="program-label" id={id('est-label')}>Est. cost</span>
                          <output aria-labelledby={id('est-label')}>{peso(itemCost(item))}</output>
                        </div>
                      </div>
                      {form.items.length > 1 && (
                        <div className="pr-item-foot">
                          <button type="button" className="program-btn" onClick={() => removeItem(index)} disabled={locked} aria-label={`Remove item ${index + 1}`}>Remove item</button>
                        </div>
                      )}
                    </fieldset>
                  </li>
                );
              })}
            </ol>
            <div className="pr-items-total">
              <button type="button" className="program-btn" onClick={addItem} disabled={locked || form.items.length >= 100}>+ Add item</button>
              <div>
                <span className="program-label" id="ra-pr-total-label">Total </span>
                <output aria-labelledby="ra-pr-total-label" aria-live="polite">{peso(total)}</output>
              </div>
            </div>
            {overBudget.map(({ item, amount, available }) => (
              <div key={item.id} className="ld-hint is-risk" role="status">
                ⚠ Items on {item.name} total {peso(amount)}, more than the {peso(available)} available.
              </div>
            ))}
            {err('items')}
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
                              onClick={() => setToRemove(removing ? toRemove.filter((docId) => docId !== doc.id) : [...toRemove, doc.id])}>
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
