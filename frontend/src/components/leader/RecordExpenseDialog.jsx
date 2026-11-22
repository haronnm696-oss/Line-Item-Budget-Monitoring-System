import { useEffect, useState } from 'react';
import FileField from '../FileField';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fetchDocuments, fieldErrors } from '../../api/leader';
import client from '../../api/client';
import { useAuth } from '../../context/AuthContext';
import { performWrite } from '../../lib/apiResult';
import { useApiData } from '../../lib/useApiData';
import { todayInManila } from '../../lib/datetime';
import { peso } from '../money';
import {
  HONORARIA_NOTE, PS_NOTE, defaultLineItemId, expenseLineItems, groupLineItems, honorariaLineItems, lineItemAvailable, prefillFromRequest,
  rememberLineItemId, rememberedLineItemId,
} from '../../lib/expenseForm';
import { COUNTERPART, FUNDER, effectiveSource, hasCounterpart, sourceAvailable, sourceName } from '../../lib/fundingSource';
import MoneyInput from '../MoneyInput';
import { defaultTicks, linkableRequests, tickRows, tickedLink } from '../../lib/purchaseRequestItems';
import { moneyError, moneyNumber, moneyText } from '../../lib/moneyInput';
import { DATE_BOUNDS, dateRangeError } from '../../lib/dateRange';

const GENERIC_MONEY = ['Enter an amount.', 'The amount must be more than zero.'];

/** localStorage, or null when the browser blocks it. */
function browserStorage() {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

const EMPTY = { lib_category_id: '', funding_source: FUNDER, description: '', amount: '', expense_date: '', payee: '', reference_no: '', purchase_request_id: '' };
const FIELDS = Object.keys(EMPTY);

/** The stored values as form strings. */
function toForm(values) {
  const form = Object.fromEntries(FIELDS.map((key) => [key, values?.[key] == null ? '' : String(values[key])]));
  return { ...form, amount: moneyText(values?.amount), funding_source: form.funding_source || FUNDER };
}

/** Request body for the fields (only the changed ones when `before` is given). */
function payload(form, before = null) {
  const value = {
    lib_category_id: Number(form.lib_category_id),
    // Funder unless the counterpart was chosen (offered only when the item has one).
    funding_source: form.funding_source || FUNDER,
    description: form.description.trim(),
    amount: moneyNumber(form.amount),
    expense_date: form.expense_date,
    payee: form.payee.trim() || null,
    reference_no: form.reference_no.trim() || null,
    purchase_request_id: Number(form.purchase_request_id) || null,
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
 * Record an expense, or "Edit and resubmit" when `expense` (a returned or
 * draft expense the user recorded, with `edit` values) is given.
 *
 * Project Leader (default): save, attach the receipt, submit; their submit
 * skips the approval step (the server decides this). A draft left by a
 * failed attempt is deleted on close.
 *
 * Research Assistant (`assistant`): the same steps, plus "Save as draft"
 * (no submit) and an optional link to an approved purchase request: the
 * user ticks the open items this expense pays (all on one line item; items
 * on other line items are disabled with "Record a separate expense for
 * …"). Line item, amount (the ticked items' total, which may be lowered to
 * the receipt amount but never raised), payee and description are
 * prefilled. The request is fulfilled once no item is open. The submit goes to the Project
 * Leader. A draft left by a failed attempt is kept (it is listed under
 * Drafts) unless "Discard draft" is chosen.
 *
 * Either way, if a step fails "Try again" resumes from that step, so the
 * expense is never recorded twice.
 */
export default function RecordExpenseDialog({ projectId, expense = null, assistant = false, onClose, onDone }) {
  const editing = Boolean(expense);
  const { user } = useAuth();
  const balancing = useApiData(ENDPOINTS.balancing(projectId));
  const approved = useApiData(assistant ? `${ENDPOINTS.purchaseRequests(projectId)}?workflow_status=endorsed` : null);
  const allLineItems = balancing.data?.line_items ?? [];
  // Honoraria lines (personnel linked) are paid through Payroll, never as an expense.
  const lineItems = expenseLineItems(allLineItems);
  const honorariaLines = honorariaLineItems(allLineItems);
  // Approved requests that still have an open item.
  const requests = linkableRequests(Array.isArray(approved.data?.purchase_requests) ? approved.data.purchase_requests : []);
  const [form, setForm] = useState(editing ? toForm(expense.edit) : { ...EMPTY, expense_date: todayInManila() });
  // The items of the chosen request this expense pays (ids), all on one line item.
  const [ticked, setTicked] = useState([]);
  const [receipt, setReceipt] = useState(null);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(false);
  // Progress, so a retry continues where it stopped: { id, patched, removed: [], uploaded }.
  const [saved, setSaved] = useState(editing ? { id: expense.id, patched: false, removed: [], uploaded: false } : null);
  const [documents, setDocuments] = useState(null); // edit: the expense's current documents
  const [toRemove, setToRemove] = useState([]);
  const [defaulted, setDefaulted] = useState(editing || !assistant);

  // A new expense starts on the only line item, else the last one chosen
  // for this project in this browser.
  // (Set once, during render, when the line items arrive.)
  if (!defaulted && balancing.status === 'ready') {
    setDefaulted(true);
    const id = defaultLineItemId(lineItems, rememberedLineItemId(browserStorage(), user?.id, projectId, lineItems));
    if (id && !form.lib_category_id) setForm({ ...form, lib_category_id: id });
  }

  useEffect(() => {
    if (!editing) return undefined;
    let alive = true;
    fetchDocuments('expense', expense.id)
      .then((data) => { if (alive) setDocuments(Array.isArray(data?.documents) ? data.documents : []); })
      .catch(() => { if (alive) setDocuments([]); });
    return () => { alive = false; };
  }, [editing, expense?.id]);

  const alreadyLinked = editing && Boolean(expense.edit?.purchase_request_id);
  const chosen = lineItems.find((item) => String(item.id) === form.lib_category_id);
  const chosenRequest = form.purchase_request_id ? requests.find((request) => String(request.id) === form.purchase_request_id) : undefined;
  // The ticked items as one link (line item, their total, source, payee), or undefined.
  const linkedRequest = chosenRequest ? tickedLink(chosenRequest, ticked) ?? undefined : undefined;
  // The most the amount may be: the ticked items' total (or the items an already linked expense pays).
  const itemsCap = alreadyLinked ? Number(expense.edit.purchase_request_items_total) || 0 : Number(linkedRequest?.estimated_amount) || 0;
  // The ticked items' estimate (or an already linked expense) is held in
  // "pending"; it is freed by this expense, so it counts as available here.
  const heldForThis = alreadyLinked ? Number(expense.edit.amount) || 0 : Number(linkedRequest?.estimated_amount) || 0;
  const labels = balancing.data?.labels;
  const fundedByShown = hasCounterpart(chosen);
  const source = effectiveSource(chosen, form.funding_source);
  // The balance check uses the chosen source of the line item.
  const available = chosen ? sourceAvailable(chosen, source) + heldForThis : null;
  // Every approved request is listed; linking one switches to its line item.
  const requestOptions = requests;
  const keptReceipts = (documents ?? []).filter((doc) => doc.document_type === 'receipt' && !toRemove.includes(doc.id));

  const set = (key) => (event) => {
    const value = event.target.value;
    const next = { ...form, [key]: value };
    // The ticked items stay on their own line item: another line item unlinks them.
    if (key === 'lib_category_id' && linkedRequest && String(linkedRequest.lib_category_id) !== value) {
      next.purchase_request_id = '';
      setTicked([]);
    }
    if (key === 'lib_category_id' && assistant) rememberLineItemId(browserStorage(), user?.id, projectId, value);
    // No counterpart on the new line item: back to the funder.
    if (key === 'lib_category_id') {
      next.funding_source = effectiveSource(lineItems.find((item) => String(item.id) === value), next.funding_source);
    }
    setForm(next);
  };

  /**
   * The form after the link changes: line item, amount (the ticked items'
   * total), payee and description follow the ticked items (fields the user
   * typed are kept, see prefillFromRequest).
   */
  function linkForm(request, nextTicks) {
    const nextLink = request ? tickedLink(request, nextTicks) : null;
    const next = prefillFromRequest(form, nextLink, linkedRequest ?? null);
    next.purchase_request_id = request ? String(request.id) : '';
    // A linked expense draws from its items' source.
    if (nextLink) next.funding_source = nextLink.funding_source || FUNDER;
    return next;
  }

  function chooseRequest(event) {
    const request = requests.find((row) => String(row.id) === event.target.value) ?? null;
    const nextTicks = request ? defaultTicks(request) : [];
    setTicked(nextTicks);
    setForm(linkForm(request, nextTicks));
  }

  function toggleItem(itemId) {
    const nextTicks = ticked.includes(itemId) ? ticked.filter((id) => id !== itemId) : [...ticked, itemId];
    setTicked(nextTicks);
    setForm(linkForm(chosenRequest, nextTicks));
  }

  function validate(submitting) {
    const next = {};
    if (!form.lib_category_id) next.lib_category_id = 'Choose a line item.';
    if (!form.description.trim()) next.description = 'Describe the expense.';
    const amountError = moneyError(form.amount, { positive: true });
    if (amountError) next.amount = GENERIC_MONEY.includes(amountError) ? 'Enter an amount greater than zero.' : amountError;
    else if (itemsCap > 0 && moneyNumber(form.amount) > itemsCap) next.amount = `Enter the receipt amount, at most the items’ ${peso(itemsCap)}.`;
    if (chosenRequest && !linkedRequest) next.purchase_request_item_ids = 'Tick the items this expense pays.';
    if (!form.expense_date) next.expense_date = 'Enter the expense date.';
    else if (dateRangeError(form.expense_date)) next.expense_date = dateRangeError(form.expense_date);
    const hasReceipt = receipt || saved?.uploaded || (editing && keptReceipts.length > 0);
    if (submitting && !hasReceipt) next.file = editing ? 'Keep a receipt or attach a new one (PDF, JPG or PNG).' : 'Attach the receipt (PDF, JPG or PNG).';
    return next;
  }

  /** The ticked item ids for a new link (none for an older request without stored items). */
  function itemIds() {
    return linkedRequest?.item_ids ? { purchase_request_item_ids: linkedRequest.item_ids } : {};
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
        const data = await apiWrite('post', ENDPOINTS.expenses(projectId), { ...payload(form), ...itemIds() });
        step = { id: data.expense.id, patched: true, removed: [], uploaded: false };
        setSaved(step);
      }
      if (editing && !step.patched) {
        const changes = payload(form, toForm(expense.edit));
        if (changes.purchase_request_id) Object.assign(changes, itemIds());
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
      const what = `“${form.description.trim()}” (${peso(moneyNumber(form.amount))})`;
      if (!submitting) {
        onDone?.(editing ? `Saved your changes to ${what}. Submit it when it is ready.` : `Saved ${what} as a draft. Attach the receipt and submit it when ready.`);
        return;
      }
      await apiWrite('post', ENDPOINTS.submitExpense(step.id));
      const next = assistant ? 'It is waiting for the Project Leader.' : 'It is waiting for Finance/Admin verification.';
      onDone?.(`${editing ? 'Resubmitted' : 'Recorded'} ${what}. ${next}`);
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

  async function discard() {
    if (busy || !saved || editing) return;
    setBusy(true);
    try {
      await apiWrite('delete', ENDPOINTS.expense(saved.id));
      onClose();
    } catch (error) {
      setFormError(errorText(error, 'Could not discard the draft. Please try again.'));
      setBusy(false);
    }
  }

  /**
   * Close. Project Leader: a new draft saved by a failed attempt is deleted
   * so none is left behind. Research Assistant: the draft is kept (Drafts).
   */
  async function close() {
    if (busy) return;
    if (editing || !saved || assistant) {
      onClose();
      return;
    }
    await discard();
  }

  const err = (name) => errors[name] && <div className="field-err" role="alert">{errors[name]}</div>;
  // Once the fields are saved on the server, a retry does not change them again.
  const locked = busy || (editing ? Boolean(saved?.patched) : Boolean(saved));
  const discardable = !editing && Boolean(saved);
  const retrying = editing ? Boolean(saved?.patched) : Boolean(saved);
  const title = editing
    ? (expense.workflow_status === 'returned' ? 'Edit and resubmit' : 'Edit draft expense')
    : (assistant ? 'Record expense' : 'Record transaction');

  return (
    <div className="program-modal-overlay" role="presentation" onClick={close}>
      <form
        className="program-modal wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ld-expense-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={(event) => { event.preventDefault(); run(true); }}
        noValidate
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ld-expense-title">{title}</div>
            <div className="program-card-sub">
              {assistant
                ? 'Goes to the Project Leader for approval, then Finance/Admin for verification'
                : 'Your expense goes straight to Finance/Admin for verification'}
            </div>
          </div>
          <button type="button" className="program-icon-btn" onClick={close} aria-label={discardable && !assistant ? 'Discard draft and close' : 'Close'} disabled={busy}>×</button>
        </div>

        {editing && expense.return_reason && (
          <div className="program-banner error ld-return-banner" role="note">
            <b>Returned{expense.returned_by?.name ? ` by ${expense.returned_by.name}` : ''}:</b> “{expense.return_reason}”
          </div>
        )}
        {balancing.status === 'error' && (
          <div className="program-banner error" role="alert">
            Could not load the line items. <button type="button" className="program-btn sm" onClick={balancing.retry}>Try again</button>
          </div>
        )}
        {formError && <div className="program-banner error" role="alert">{formError}</div>}

        <div className="program-form-grid">
          {assistant && (
            <div className="program-field full">
              <label htmlFor="ld-exp-pr">Link to approved purchase request <span className="ld-muted">optional</span></label>
              {alreadyLinked ? (
                <p className="ld-muted" id="ld-exp-pr">
                  Linked to PR-{expense.edit.purchase_request_id}{expense.edit.purchase_request_items_text ? `: ${expense.edit.purchase_request_items_text}` : ''}. The line item and items stay the same.
                </p>
              ) : (
                <select id="ld-exp-pr" className="program-select" value={chosenRequest ? String(chosenRequest.id) : ''} onChange={chooseRequest} disabled={locked || approved.status !== 'ready'}>
                  <option value="">
                    {approved.status === 'error' ? 'Could not load the purchase requests' : approved.status !== 'ready' ? 'Loading…' : requestOptions.length === 0 ? 'No approved purchase request with open items' : 'Not linked'}
                  </option>
                  {requestOptions.map((request) => (
                    <option key={request.id} value={request.id}>
                      PR-{request.id} · {request.purpose ?? request.description} — {peso(request.total ?? request.estimated_amount)}
                    </option>
                  ))}
                </select>
              )}
              {err('purchase_request_id')}
            </div>
          )}
          {assistant && chosenRequest && !alreadyLinked && (
            <fieldset className="program-field full pr-tick-list">
              <legend className="program-label">Items this expense pays</legend>
              <div className="ld-hint">Tick items on one line item. Items on other line items need their own expense.</div>
              <ul>
                {tickRows(chosenRequest, ticked).map(({ item, checked, disabled, note }, index) => {
                  const id = `ld-exp-item-${item.id ?? `old-${index}`}`;
                  return (
                    <li key={id}>
                      <label htmlFor={id} className={`pr-tick${disabled ? ' is-disabled' : ''}`}>
                        <input id={id} type="checkbox" checked={checked} disabled={locked || disabled} onChange={() => item.id != null && toggleItem(item.id)} />
                        <span>
                          <b>{item.quantity} {item.unit} · {item.description}</b> — {peso(item.estimated_cost)}
                          <span className="ld-muted"> · {item.line_item ?? '—'}</span>
                          {note && <span className="pr-tick-note">{note}</span>}
                        </span>
                      </label>
                    </li>
                  );
                })}
              </ul>
              {linkedRequest && (
                <div className="ld-hint" role="status">
                  {linkedRequest.item_ids?.length ?? 1} {(linkedRequest.item_ids?.length ?? 1) === 1 ? 'item' : 'items'} on {linkedRequest.line_item}: {peso(linkedRequest.estimated_amount)}. The amount can be lower (the actual receipt), never higher.
                </div>
              )}
              {err('purchase_request_item_ids')}
            </fieldset>
          )}
          <div className="program-field full">
            <label htmlFor="ld-exp-line">Line item</label>
            <select id="ld-exp-line" className="program-select" value={form.lib_category_id} onChange={set('lib_category_id')} disabled={locked || alreadyLinked || balancing.status !== 'ready'}>
              <option value="">{balancing.status === 'ready' ? 'Choose a line item' : 'Loading…'}</option>
              {groupLineItems(lineItems).map((group) => (
                <optgroup key={group.type} label={group.type}>
                  {group.items.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name} — {peso(item === chosen ? available : lineItemAvailable(item))} available{item === chosen && heldForThis > 0 ? ' (incl. amount held for the request)' : ''}
                    </option>
                  ))}
                </optgroup>
              ))}
            </select>
            {chosen?.type === 'PS' && <div className="ld-hint" role="note">{PS_NOTE}</div>}
            {honorariaLines.length > 0 && (
              <div className="ld-hint" role="note">
                <span aria-hidden="true">ⓘ</span> {HONORARIA_NOTE}: {honorariaLines.map((item) => item.name).join(', ')} {honorariaLines.length === 1 ? 'is' : 'are'} not listed here.
              </div>
            )}
            {chosen && moneyNumber(form.amount) > available && (
              <div className="ld-hint is-risk" role="status">
                ⚠ More than the {peso(available)} available on this line item{fundedByShown ? ` (${sourceName(source, labels)})` : ''}.
              </div>
            )}
            {err('lib_category_id')}
          </div>
          {fundedByShown && (
            <fieldset className="program-field full ld-funded-by">
              <legend className="program-label">Funded by</legend>
              {[FUNDER, COUNTERPART].map((value) => (
                <label key={value} className="ld-radio">
                  <input
                    type="radio"
                    name="ld-exp-source"
                    value={value}
                    checked={source === value}
                    onChange={() => setForm({ ...form, funding_source: value })}
                    disabled={locked || alreadyLinked || Boolean(linkedRequest)}
                  />
                  <span>{sourceName(value, labels)} — {peso(sourceAvailable(chosen, value) + (value === source ? heldForThis : 0))} available</span>
                </label>
              ))}
              {(alreadyLinked || linkedRequest) && <div className="ld-hint">A linked expense uses its purchase request&apos;s funding source.</div>}
              {err('funding_source')}
            </fieldset>
          )}
          <div className="program-field full">
            <label htmlFor="ld-exp-desc">Description</label>
            <input id="ld-exp-desc" className="program-input" autoComplete="off" maxLength={500} value={form.description} onChange={set('description')} disabled={locked} />
            {err('description')}
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-amount">Amount (₱)</label>
            <MoneyInput id="ld-exp-amount" value={form.amount} onChange={set('amount')} disabled={locked} />
            {itemsCap > 0 && <div className="ld-hint">At most {peso(itemsCap)}, the linked items&apos; total. A lower amount releases the difference.</div>}
            {err('amount')}
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-date">Expense date</label>
            <input id="ld-exp-date" className="program-input" type="date" {...DATE_BOUNDS} value={form.expense_date} onChange={set('expense_date')} disabled={locked} />
            {err('expense_date')}
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-payee">Payee <span className="ld-muted">optional</span></label>
            <input id="ld-exp-payee" className="program-input" autoComplete="off" maxLength={255} value={form.payee} onChange={set('payee')} disabled={locked} />
          </div>
          <div className="program-field">
            <label htmlFor="ld-exp-ref">Reference no. (OR / voucher) <span className="ld-muted">optional</span></label>
            <input id="ld-exp-ref" className="program-input" autoComplete="off" maxLength={100} value={form.reference_no} onChange={set('reference_no')} disabled={locked} />
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
            <FileField
              id="ld-exp-file"
              label={<>
                {editing ? 'Add or replace the receipt' : 'Receipt'}{' '}
                {editing
                  ? <span className="ld-muted">optional when a receipt is kept</span>
                  : <span className="ld-required">{assistant ? 'required to submit' : 'required'}</span>}
              </>}
              disabled={busy || saved?.uploaded}
              onPreparing={setPreparing}
              onChange={(prepared, problem) => {
                setReceipt(prepared);
                setErrors((current) => ({ ...current, file: problem ?? undefined }));
              }}
            />
            {saved?.uploaded && <div className="ld-hint">Receipt attached.</div>}
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
          {assistant && discardable && <button type="button" className="program-btn" onClick={close} disabled={busy}>Keep draft and close</button>}
          {assistant && (
            <button type="button" className="program-btn" onClick={() => run(false)} disabled={busy || preparing || (editing && documents === null)}>
              {editing ? 'Save changes' : (saved ? 'Save draft' : 'Save as draft')}
            </button>
          )}
          <button type="submit" className="program-btn primary" disabled={busy || preparing || (editing && documents === null)}>
            {busy ? 'Saving…'
              : retrying ? 'Try again'
              : editing ? (expense.workflow_status === 'draft' ? 'Save and submit' : 'Resubmit')
              : (assistant ? 'Save, attach and submit' : 'Record and submit')}
          </button>
        </div>
      </form>
    </div>
  );
}
