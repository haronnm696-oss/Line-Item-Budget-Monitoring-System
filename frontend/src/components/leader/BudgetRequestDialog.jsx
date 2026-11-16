import { useState } from 'react';
import FileField from '../FileField';
import { ENDPOINTS } from '../../api/endpoints';
import { errorText, fieldErrors, submitBudgetRequest } from '../../api/leader';
import { useApiData } from '../../lib/useApiData';
import { peso } from '../money';
import { formatDate } from '../../lib/datetime';

const TYPES = [
  ['fund_release', 'Fund release'],
  ['realignment', 'Realignment'],
  ['liquidation', 'Liquidation'],
];

/**
 * Submit a budget request to the Program Leader (existing endpoint
 * POST /api/projects/:id/budget-requests). Fund release picks an unreleased
 * tranche; realignment moves money between line items and may not exceed
 * the source's available amount (balancing query; the server checks too);
 * liquidation needs at least one document.
 */
export default function BudgetRequestDialog({ projectId, initial = {}, requests = [], onClose, onSaved }) {
  const tranches = useApiData(ENDPOINTS.projectTranches(projectId));
  const balancing = useApiData(ENDPOINTS.balancing(projectId));
  const [type, setType] = useState(initial.type ?? 'fund_release');
  const [trancheId, setTrancheId] = useState(initial.trancheId ? String(initial.trancheId) : '');
  const [fromId, setFromId] = useState('');
  const [toId, setToId] = useState('');
  const [amount, setAmount] = useState('');
  const [title, setTitle] = useState('');
  const [justification, setJustification] = useState('');
  const [files, setFiles] = useState([]);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(false);

  const pendingTranches = new Set(
    requests.filter((r) => r.type === 'fund_release' && r.status === 'pending').map((r) => String(r.project_tranche_id))
  );
  const unreleased = (tranches.data?.items ?? []).filter((t) => !t.is_released);
  const lineItems = balancing.data?.line_items ?? [];
  const source = lineItems.find((item) => String(item.id) === fromId);
  const available = source ? Number(source.available) : null;
  const overAvailable = type === 'realignment' && available != null && Number(amount) > available;

  function validate() {
    const next = {};
    if (!justification.trim()) next.justification = 'A justification is required.';
    if (type === 'fund_release' && !trancheId) next.project_tranche_id = 'Choose the tranche to release.';
    if (type === 'realignment') {
      if (!fromId || !toId) next.from_lib_category_id = 'Choose both line items.';
      else if (fromId === toId) next.to_lib_category_id = 'The source and destination line items must be different.';
      if (!(Number(amount) > 0)) next.amount = 'Enter an amount greater than zero.';
      else if (overAvailable) next.amount = `The source line item has only ${peso(available)} available.`;
    }
    if (type === 'liquidation') {
      if (!(Number(amount) > 0)) next.amount = 'Enter the amount liquidated.';
      if (files.length === 0) next.files = 'Attach at least one supporting document.';
    }
    return next;
  }

  async function submit(event) {
    event.preventDefault();
    const next = validate();
    setErrors(next);
    setFormError('');
    if (Object.keys(next).length > 0 || busy) return;

    const fields = { type, justification: justification.trim() };
    if (type === 'fund_release') fields.project_tranche_id = Number(trancheId);
    if (type === 'realignment') Object.assign(fields, { from_lib_category_id: Number(fromId), to_lib_category_id: Number(toId), amount: Number(amount) });
    if (type === 'liquidation') Object.assign(fields, { amount: Number(amount), title: title.trim(), project_tranche_id: trancheId || '', document_type: 'receipt' });

    setBusy(true);
    try {
      const data = await submitBudgetRequest(projectId, fields, type === 'liquidation' ? files : []);
      onSaved?.(data?.budget_request);
    } catch (error) {
      const server = fieldErrors(error);
      const mapped = Object.fromEntries(Object.entries(server).map(([key, text]) => [key.startsWith('files') ? 'files' : key, text]));
      setErrors(mapped);
      if (Object.keys(mapped).length === 0) setFormError(errorText(error, 'Could not send the request. Please try again.'));
    } finally {
      setBusy(false);
    }
  }

  const err = (name) => errors[name] && <div className="field-err" role="alert">{errors[name]}</div>;
  const loadingOptions = tranches.status === 'loading' || balancing.status === 'loading';
  const optionsFailed = tranches.status === 'error' || balancing.status === 'error';

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
            <div className="program-modal-title" id="ld-request-title">Submit budget request</div>
            <div className="program-card-sub">Sent to the Program Leader for a decision</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>

        {optionsFailed && (
          <div className="program-banner error" role="alert">
            Could not load the tranches or line items.{' '}
            <button type="button" className="program-btn sm" onClick={() => { tranches.retry(); balancing.retry(); }}>Try again</button>
          </div>
        )}
        {formError && <div className="program-banner error" role="alert">{formError}</div>}

        <fieldset className="ld-type-choice" disabled={busy}>
          <legend>Type</legend>
          {TYPES.map(([value, label]) => (
            <label key={value} className={type === value ? 'is-on' : ''}>
              <input type="radio" name="ld-request-type" value={value} checked={type === value} onChange={() => { setType(value); setErrors({}); }} />
              {label}
            </label>
          ))}
        </fieldset>

        {type === 'fund_release' && (
          <div className="program-field">
            <label htmlFor="ld-tranche">Tranche</label>
            <select id="ld-tranche" className="program-select" value={trancheId} onChange={(e) => setTrancheId(e.target.value)} disabled={busy || loadingOptions}>
              <option value="">{loadingOptions ? 'Loading…' : unreleased.length ? 'Choose a tranche' : 'Every tranche is released'}</option>
              {unreleased.map((t) => (
                <option key={t.id} value={t.id} disabled={pendingTranches.has(String(t.id))}>
                  {t.name} · {peso(t.amount)} · planned {formatDate(t.planned_release_date)}
                  {pendingTranches.has(String(t.id)) ? ' (request pending)' : ''}
                </option>
              ))}
            </select>
            {err('project_tranche_id')}
          </div>
        )}

        {type === 'realignment' && (
          <div className="program-form-grid ld-grid-3">
            <div className="program-field">
              <label htmlFor="ld-from">From line item</label>
              <select id="ld-from" className="program-select" value={fromId} onChange={(e) => setFromId(e.target.value)} disabled={busy || loadingOptions}>
                <option value="">Choose</option>
                {lineItems.map((item) => <option key={item.id} value={item.id}>{item.type} · {item.name}</option>)}
              </select>
              {source && (
                <div className={`ld-hint${available <= 0 ? ' is-risk' : ''}`}>Available: <b>{peso(available)}</b></div>
              )}
              {err('from_lib_category_id')}
            </div>
            <div className="program-field">
              <label htmlFor="ld-to">To line item</label>
              <select id="ld-to" className="program-select" value={toId} onChange={(e) => setToId(e.target.value)} disabled={busy || loadingOptions}>
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
                onChange={(e) => setAmount(e.target.value)}
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
              <input id="ld-liq-title" className="program-input" value={title} maxLength={255} onChange={(e) => setTitle(e.target.value)} disabled={busy} />
            </div>
            <div className="program-field">
              <label htmlFor="ld-liq-amount">Amount liquidated (₱)</label>
              <input id="ld-liq-amount" className="program-input" type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} disabled={busy} />
              {err('amount')}
            </div>
            <div className="program-field">
              <label htmlFor="ld-liq-tranche">Tranche <span className="ld-muted">optional</span></label>
              <select id="ld-liq-tranche" className="program-select" value={trancheId} onChange={(e) => setTrancheId(e.target.value)} disabled={busy || loadingOptions}>
                <option value="">None</option>
                {(tranches.data?.items ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </div>
            <div className="program-field">
              <FileField
                id="ld-liq-files"
                label="Supporting documents"
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
          </div>
        )}

        <div className="program-field">
          <label htmlFor="ld-justification">Justification <span className="ld-required">required</span></label>
          <textarea
            id="ld-justification"
            className="program-textarea"
            rows={4}
            maxLength={2000}
            value={justification}
            onChange={(e) => setJustification(e.target.value)}
            disabled={busy}
            placeholder="Why this request is needed"
          />
          {err('justification')}
        </div>

        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy || preparing || overAvailable}>
            {busy ? 'Sending…' : 'Send to Program Leader'}
          </button>
        </div>
      </form>
    </div>
  );
}
