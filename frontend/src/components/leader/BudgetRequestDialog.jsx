import { useEffect, useState } from 'react';
import FileField from '../FileField';
import { ENDPOINTS } from '../../api/endpoints';
import { errorText, fetchDocuments, fieldErrors, resubmitBudgetRequest, submitBudgetRequest } from '../../api/leader';
import { useAuth } from '../../context/AuthContext';
import { useApiData } from '../../lib/useApiData';
import { documentsAfter, initialForm, pendingTrancheIds, requestFields } from '../../lib/budgetRequestForm';
import { peso } from '../money';
import { formatDate } from '../../lib/datetime';
import { requestFormWarning } from '../../lib/releasePlan';
import { COUNTERPART, FUNDER, effectiveSource, hasCounterpart, sourceAvailable, sourceName } from '../../lib/fundingSource';

const TYPES = [
  ['fund_release', 'Fund release'],
  ['realignment', 'Realignment'],
  ['liquidation', 'Liquidation'],
];

/**
 * Submit a budget request to the Program Leader
 * (POST /api/projects/:id/budget-requests), or "Edit and resubmit" a
 * returned one (`request`, a row with `edit` values;
 * POST /api/budget-requests/:id/resubmit). Fund release picks an unreleased
 * release ("Release 2"; API fields say tranche); realignment moves money between line items and may not exceed
 * the source's available amount (balancing query; the server checks too);
 * liquidation needs at least one document. Any type may carry supporting
 * documents (e.g. a work plan). On a resubmit the type stays, and the
 * maker's existing documents can be kept or removed.
 */
export default function BudgetRequestDialog({ projectId, initial = {}, request = null, requests = [], onClose, onSaved }) {
  const editing = Boolean(request?.edit);
  const { user } = useAuth();
  const tranches = useApiData(ENDPOINTS.projectTranches(projectId));
  const balancing = useApiData(ENDPOINTS.balancing(projectId));
  const [form, setForm] = useState(() => initialForm(editing ? request.edit : null, initial));
  const [files, setFiles] = useState([]);
  const [documents, setDocuments] = useState(editing ? null : []); // edit: the request's current documents
  const [toRemove, setToRemove] = useState([]);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const { type, trancheId, fromId, toId, amount, title, justification } = form;
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

  useEffect(() => {
    if (!editing) return undefined;
    let alive = true;
    fetchDocuments('approval', request.id)
      .then((data) => { if (alive) setDocuments(Array.isArray(data?.documents) ? data.documents : []); })
      .catch(() => { if (alive) setDocuments([]); });
    return () => { alive = false; };
  }, [editing, request?.id]);

  const pendingTranches = pendingTrancheIds(requests, editing ? request.id : null);
  const unreleased = (tranches.data?.items ?? []).filter((t) => !t.is_released);
  // A reminder, not a block: an earlier released release has no approved liquidation.
  const liquidationWarning = requestFormWarning(type, tranches.data?.summary);
  const lineItems = balancing.data?.line_items ?? [];
  const source = lineItems.find((item) => String(item.id) === fromId);
  const labels = balancing.data?.labels;
  const moneySource = effectiveSource(source, form.fundingSource);
  // Money moves within one source: its available amount is the limit.
  const available = source ? sourceAvailable(source, moneySource) : null;
  const overAvailable = type === 'realignment' && available != null && Number(amount) > available;
  const documentCount = documentsAfter(documents, toRemove, files);

  function validate() {
    const next = {};
    if (!justification.trim()) next.justification = 'A justification is required.';
    if (type === 'fund_release' && !trancheId) next.project_tranche_id = 'Choose the release to request.';
    if (type === 'realignment') {
      if (!fromId || !toId) next.from_lib_category_id = 'Choose both line items.';
      else if (fromId === toId) next.to_lib_category_id = 'The source and destination line items must be different.';
      if (!(Number(amount) > 0)) next.amount = 'Enter an amount greater than zero.';
      else if (overAvailable) next.amount = `The source line item has only ${peso(available)} available.`;
    }
    if (type === 'liquidation') {
      if (!(Number(amount) > 0)) next.amount = 'Enter the amount liquidated.';
      if (documentCount === 0) next.files = 'Attach at least one supporting document.';
    }
    return next;
  }

  async function submit(event) {
    event.preventDefault();
    const next = validate();
    setErrors(next);
    setFormError('');
    if (Object.keys(next).length > 0 || busy) return;

    const fields = requestFields(form);
    setBusy(true);
    try {
      const data = editing
        ? await resubmitBudgetRequest(request.id, fields, files, toRemove)
        : await submitBudgetRequest(projectId, { type, ...fields }, files);
      onSaved?.(data?.budget_request);
    } catch (error) {
      const server = fieldErrors(error);
      const mapped = Object.fromEntries(Object.entries(server).map(([key, message]) => [
        key.startsWith('files') ? 'files' : key.startsWith('remove_document_ids') ? 'documents' : key,
        message,
      ]));
      setErrors(mapped);
      const shown = ['justification', 'project_tranche_id', 'from_lib_category_id', 'to_lib_category_id', 'amount', 'files', 'documents'];
      if (!Object.keys(mapped).some((key) => shown.includes(key))) {
        setFormError(mapped.status ?? errorText(error, editing ? 'Could not resubmit the request. Please try again.' : 'Could not send the request. Please try again.'));
      }
    } finally {
      setBusy(false);
    }
  }

  const err = (name) => errors[name] && <div className="field-err" role="alert">{errors[name]}</div>;
  const loadingOptions = tranches.status === 'loading' || balancing.status === 'loading';
  const optionsFailed = tranches.status === 'error' || balancing.status === 'error';
  const typeLabel = TYPES.find(([value]) => value === type)?.[1] ?? type;

  return (
    <div className="program-modal-overlay" role="presentation" onClick={() => { if (!busy) onClose(); }}>
      <form
        className="program-modal wide"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ld-request-title"
        onClick={(event) => event.stopPropagation()}
        onSubmit={submit}
        noValidate
      >
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="ld-request-title">{editing ? 'Edit and resubmit' : 'Submit budget request'}</div>
            <div className="program-card-sub">
              {editing ? 'Goes back to the Program Leader for a decision' : 'Sent to the Program Leader for a decision'}
            </div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>

        {editing && request.review_notes && (
          <div className="program-banner error ld-return-banner" role="note">
            <b>Returned{request.reviewed_by ? ` by ${request.reviewed_by}` : ''}:</b> “{request.review_notes}”
          </div>
        )}
        {optionsFailed && (
          <div className="program-banner error" role="alert">
            Could not load the releases or line items.{' '}
            <button type="button" className="program-btn sm" onClick={() => { tranches.retry(); balancing.retry(); }}>Try again</button>
          </div>
        )}
        {formError && <div className="program-banner error" role="alert">{formError}</div>}

        {editing ? (
          <div className="program-field">
            <span className="program-label">Type</span>
            <p className="ld-muted">{typeLabel} (the type stays the same; send a new request for another type)</p>
          </div>
        ) : (
          <fieldset className="ld-type-choice" disabled={busy}>
            <legend>Type</legend>
            {TYPES.map(([value, label]) => (
              <label key={value} className={type === value ? 'is-on' : ''}>
                <input type="radio" name="ld-request-type" value={value} checked={type === value} onChange={() => { setForm((current) => ({ ...current, type: value })); setErrors({}); }} />
                {label}
              </label>
            ))}
          </fieldset>
        )}

        {type === 'fund_release' && (
          <div className="program-field">
            <label htmlFor="ld-tranche">Release</label>
            <select id="ld-tranche" className="program-select" value={trancheId} onChange={set('trancheId')} disabled={busy || loadingOptions}>
              <option value="">{loadingOptions ? 'Loading…' : unreleased.length ? 'Choose a release' : 'All released'}</option>
              {unreleased.map((t) => (
                <option key={t.id} value={t.id} disabled={pendingTranches.has(String(t.id))}>
                  {t.name} · {peso(t.amount)} · planned {formatDate(t.planned_release_date)}
                  {pendingTranches.has(String(t.id)) ? ' (request pending)' : ''}
                </option>
              ))}
            </select>
            {err('project_tranche_id')}
            {liquidationWarning && (
              <p className="ld-waiting" role="status"><span aria-hidden="true">⚠</span> {liquidationWarning}</p>
            )}
          </div>
        )}

        {type === 'realignment' && (
          <div className="program-form-grid ld-grid-3">
            <div className="program-field">
              <label htmlFor="ld-from">From line item</label>
              <select
                id="ld-from"
                className="program-select"
                value={fromId}
                onChange={(event) => {
                  const value = event.target.value;
                  const item = lineItems.find((row) => String(row.id) === value);
                  setForm((current) => ({ ...current, fromId: value, fundingSource: effectiveSource(item, current.fundingSource) }));
                }}
                disabled={busy || loadingOptions}
              >
                <option value="">Choose</option>
                {lineItems.map((item) => <option key={item.id} value={item.id}>{item.type} · {item.name}</option>)}
              </select>
              {source && (
                <div className={`ld-hint${available <= 0 ? ' is-risk' : ''}`}>
                  Available{hasCounterpart(source) ? ` (${sourceName(moneySource, labels)})` : ''}: <b>{peso(available)}</b>
                </div>
              )}
              {err('from_lib_category_id')}
            </div>
            {hasCounterpart(source) && (
              <fieldset className="program-field full ld-funded-by">
                <legend className="program-label">Move money of</legend>
                {[FUNDER, COUNTERPART].map((value) => (
                  <label key={value} className="ld-radio">
                    <input
                      type="radio"
                      name="ld-realign-source"
                      value={value}
                      checked={moneySource === value}
                      onChange={() => setForm((current) => ({ ...current, fundingSource: value }))}
                      disabled={busy}
                    />
                    <span>{sourceName(value, labels)} — {peso(sourceAvailable(source, value))} available</span>
                  </label>
                ))}
                <div className="ld-hint">The amount moves to the same source of the destination line item.</div>
                {err('funding_source')}
              </fieldset>
            )}
            <div className="program-field">
              <label htmlFor="ld-to">To line item</label>
              <select id="ld-to" className="program-select" value={toId} onChange={set('toId')} disabled={busy || loadingOptions}>
                <option value="">Choose</option>
                {lineItems.filter((item) => String(item.id) !== fromId).map((item) => (
                  <option key={item.id} value={item.id}>{item.type} · {item.name}</option>
                ))}
              </select>
              {err('to_lib_category_id')}
            </div>
            <div className="program-field">
              <label htmlFor="ld-amount">Amount (₱)</label>
              <input
                id="ld-amount"
                className="program-input"
                type="number"
                min="0.01"
                step="0.01"
                max={available != null ? Math.max(available, 0) : undefined}
                value={amount}
                onChange={set('amount')}
                aria-invalid={overAvailable || Boolean(errors.amount)}
                disabled={busy}
              />
              {overAvailable && !errors.amount && <div className="field-err" role="alert">More than the {peso(available)} available.</div>}
              {err('amount')}
            </div>
          </div>
        )}

        {type === 'liquidation' && (
          <div className="program-form-grid">
            <div className="program-field">
              <label htmlFor="ld-liq-title">Title <span className="ld-muted">optional</span></label>
              <input id="ld-liq-title" className="program-input" value={title} maxLength={255} onChange={set('title')} disabled={busy} />
            </div>
            <div className="program-field">
              <label htmlFor="ld-liq-amount">Amount liquidated (₱)</label>
              <input id="ld-liq-amount" className="program-input" type="number" min="0.01" step="0.01" value={amount} onChange={set('amount')} disabled={busy} />
              {err('amount')}
            </div>
            <div className="program-field">
              <label htmlFor="ld-liq-tranche">Release <span className="ld-muted">optional</span></label>
              <select id="ld-liq-tranche" className="program-select" value={trancheId} onChange={set('trancheId')} disabled={busy || loadingOptions}>
                <option value="">None</option>
                {(tranches.data?.items ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </div>
          </div>
        )}

        {editing && (
          <div className="program-field">
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
                        <span>{removing ? 'will be removed' : 'kept'}</span>
                      </div>
                      {mine && (
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
            {err('documents')}
          </div>
        )}

        <div className="program-field">
          <FileField
            id="ld-request-files"
            label={<>
              {editing ? 'Add supporting documents' : 'Supporting documents'}{' '}
              {type === 'liquidation' && !editing
                ? <span className="ld-required">required</span>
                : <span className="ld-muted">{type === 'liquidation' ? 'at least one in total' : 'optional, e.g. a work plan'}</span>}
            </>}
            multiple
            disabled={busy}
            onPreparing={setPreparing}
            onChange={(prepared, problem) => {
              setFiles(prepared);
              setErrors((current) => ({ ...current, files: problem ?? undefined }));
            }}
          />
          {files.length > 0 && <div className="ld-hint">{files.length} file{files.length === 1 ? '' : 's'} chosen</div>}
          {err('files')}
        </div>

        <div className="program-field">
          <label htmlFor="ld-justification">Justification <span className="ld-required">required</span></label>
          <textarea
            id="ld-justification"
            className="program-textarea"
            rows={4}
            maxLength={2000}
            value={justification}
            onChange={set('justification')}
            disabled={busy}
            placeholder={editing ? 'What you changed, and why this request is needed' : 'Why this request is needed'}
          />
          {err('justification')}
        </div>

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy || preparing || overAvailable || (editing && documents === null)}>
            {busy ? 'Sending…' : editing ? 'Resubmit to Program Leader' : 'Send to Program Leader'}
          </button>
        </div>
      </form>
    </div>
  );
}
