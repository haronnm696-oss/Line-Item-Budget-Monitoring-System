import { useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fieldErrors } from '../../api/leader';
import { useApiData } from '../../lib/useApiData';
import { useAssignedProject } from '../../lib/useAssignedProject';
import { notAssignedText } from '../../lib/myProjects';
import { useUrlQuery } from '../../lib/useUrlQuery';
import { formatDate } from '../../lib/datetime';
import { DATE_BOUNDS, dateRangeError } from '../../lib/dateRange';
import LoadState from '../../components/LoadState';
import ProjectLine from '../../components/ProjectLine';
import StagePill from '../../components/assistant/StagePill';
import PurchaseRequestItemsTable from '../../components/PurchaseRequestItemsTable';
import { peso } from '../../components/money';

const STATUS = [
  ['endorsed', 'Approved, open'],
  ['fulfilled', 'Fulfilled'],
  ['', 'All stages'],
];
const NUMBERS = [
  ['pr', 'PR No.'],
  ['sai', 'SAI No.'],
  ['alobs', 'ALOBS No.'],
];
const EDITABLE = ['endorsed', 'fulfilled'];

function numberText(row, key) {
  const no = row[`${key}_no`];
  const date = row[`${key}_date`];
  if (!no && !date) return null;
  return `${no || '—'}${date ? ` · ${formatDate(date)}` : ''}`;
}

/**
 * Purchase requests (Finance/Admin): approved requests of the project with
 * their items and total. Finance/Admin fills in the PR No., SAI No. and
 * ALOBS No. with their dates (PATCH /purchase-requests/:id/numbers).
 */
export default function FinancePurchaseRequests() {
  const picker = useAssignedProject({ role: 'finance-admin' });
  const { get, update } = useUrlQuery({ status: 'endorsed' });
  const status = STATUS.some(([value]) => value === get('status')) ? get('status') : 'endorsed';
  const [editing, setEditing] = useState(null);
  const [feedback, setFeedback] = useState(null);
  const [expanded, setExpanded] = useState(() => new Set());

  const projectId = picker.projectId;
  const list = useApiData(projectId ? `${ENDPOINTS.purchaseRequests(projectId)}${status ? `?workflow_status=${status}` : ''}` : null);
  const rows = Array.isArray(list.data?.purchase_requests) ? list.data.purchase_requests : [];
  const toggle = (id) => setExpanded((current) => {
    const next = new Set(current);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  return (
    <div className="ld-page">
      <div className="pl-head">
        <div>
          <div className="program-eyebrow">Records · Finance/Admin</div>
          <h1 className="program-h1">Purchase requests</h1>
          <ProjectLine picker={picker} detail="Fill in the PR, SAI and ALOBS numbers of approved requests" />
        </div>
        {projectId && (
          <div className="pl-head-actions">
            <div className="program-field">
              <label htmlFor="fin-pr-status">Stage</label>
              <select id="fin-pr-status" className="program-select" value={status} onChange={(event) => update({ status: event.target.value })}>
                {STATUS.map(([value, label]) => <option key={value || 'all'} value={value}>{label}</option>)}
              </select>
            </div>
          </div>
        )}
      </div>

      {feedback && <div className={`program-banner ${feedback.tone}`} role="status" aria-live="polite">{feedback.text}</div>}

      <LoadState state={picker.state} emptyText={notAssignedText(picker.role)} isEmpty={picker.state.status === 'ready' && !picker.project}>
        <LoadState state={list} loadingText="Loading purchase requests…" emptyText="No purchase request in this stage." isEmpty={rows.length === 0}>
          <div className="pr-item-list">
            {rows.map((row) => (
              <section key={row.id} className="program-card">
                <div className="pl-head">
                  <div>
                    <div className="program-eyebrow">PR-{row.id} · {row.requested_by?.name ?? '—'}</div>
                    <h2 className="program-card-title">{row.purpose ?? row.description}</h2>
                    <div className="program-card-sub">
                      {row.items?.length ?? 1} {(row.items?.length ?? 1) === 1 ? 'item' : 'items'} · {row.line_item}{row.supplier ? ` · ${row.supplier}` : ''}
                    </div>
                  </div>
                  <div className="pl-head-actions">
                    <StagePill status={row.workflow_status} type="purchase_request" fulfilment={row.fulfilment} />
                    <b className="ld-amount">{peso(row.total ?? row.estimated_amount)}</b>
                  </div>
                </div>
                <dl className="ld-fields">
                  {NUMBERS.map(([key, label]) => (
                    <div key={key}><dt>{label}</dt><dd>{numberText(row, key) ?? 'Not set'}</dd></div>
                  ))}
                </dl>
                <div className="ld-panel-actions">
                  <button type="button" className="program-btn" aria-expanded={expanded.has(row.id)} onClick={() => toggle(row.id)}>
                    {expanded.has(row.id) ? 'Hide items' : 'Show items'}
                  </button>
                  {EDITABLE.includes(row.workflow_status) && (
                    <button type="button" className="program-btn primary" onClick={() => { setFeedback(null); setEditing(row); }}>
                      {NUMBERS.some(([key]) => numberText(row, key)) ? 'Edit PR / SAI / ALOBS' : 'Set PR / SAI / ALOBS'}
                    </button>
                  )}
                </div>
                {expanded.has(row.id) && <PurchaseRequestItemsTable items={row.items} total={row.total} />}
              </section>
            ))}
          </div>
        </LoadState>
      </LoadState>

      {editing && (
        <NumbersDialog
          request={editing}
          onClose={() => setEditing(null)}
          onDone={(text) => { setEditing(null); setFeedback({ tone: 'ok', text }); list.retry(); }}
        />
      )}
    </div>
  );
}

function NumbersDialog({ request, onClose, onDone }) {
  const [form, setForm] = useState(() => Object.fromEntries(NUMBERS.flatMap(([key]) => [
    [`${key}_no`, request[`${key}_no`] ?? ''],
    [`${key}_date`, request[`${key}_date`] ?? ''],
  ])));
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (key) => (event) => setForm({ ...form, [key]: event.target.value });

  async function save(event) {
    event.preventDefault();
    const next = {};
    for (const [key] of NUMBERS) {
      if (form[`${key}_no`].trim().length > 100) next[`${key}_no`] = 'Use at most 100 characters.';
      const dateError = form[`${key}_date`] ? dateRangeError(form[`${key}_date`]) : null;
      if (dateError) next[`${key}_date`] = dateError;
    }
    setErrors(next);
    setFormError('');
    if (Object.keys(next).length > 0 || busy) return;
    setBusy(true);
    try {
      const body = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, String(value).trim() || null]));
      await apiWrite('patch', ENDPOINTS.purchaseRequestStep(request.id, 'numbers'), body);
      onDone(`Saved the PR, SAI and ALOBS details of PR-${request.id}.`);
    } catch (error) {
      setErrors(fieldErrors(error));
      setFormError(errorText(error, 'Could not save. Please try again.'));
      setBusy(false);
    }
  }

  const close = () => { if (!busy) onClose(); };
  const err = (name) => errors[name] && <div className="field-err" role="alert">{errors[name]}</div>;

  return (
    <div className="program-modal-overlay" role="presentation" onClick={close}>
      <form className="program-modal" role="dialog" aria-modal="true" aria-labelledby="fin-pr-numbers-title" onClick={(event) => event.stopPropagation()} onSubmit={save} noValidate>
        <div className="program-modal-head">
          <div>
            <div className="program-modal-title" id="fin-pr-numbers-title">PR, SAI and ALOBS — PR-{request.id}</div>
            <div className="program-card-sub">{request.purpose ?? request.description} · {peso(request.total ?? request.estimated_amount)}</div>
          </div>
          <button type="button" className="program-icon-btn" onClick={close} aria-label="Close" disabled={busy}>×</button>
        </div>
        {formError && <div className="program-banner error" role="alert">{formError}</div>}
        <div className="program-form-grid">
          {NUMBERS.map(([key, label]) => (
            <div key={key} className="program-field full">
              <div className="program-form-grid">
                <div className="program-field">
                  <label htmlFor={`fin-pr-${key}-no`}>{label} <span className="ld-muted">optional</span></label>
                  <input id={`fin-pr-${key}-no`} className="program-input" autoComplete="off" maxLength={100} value={form[`${key}_no`]} onChange={set(`${key}_no`)} disabled={busy} />
                  {err(`${key}_no`)}
                </div>
                <div className="program-field">
                  <label htmlFor={`fin-pr-${key}-date`}>{label.replace(' No.', '')} date <span className="ld-muted">optional</span></label>
                  <input id={`fin-pr-${key}-date`} className="program-input" type="date" {...DATE_BOUNDS} value={form[`${key}_date`]} onChange={set(`${key}_date`)} disabled={busy} />
                  {err(`${key}_date`)}
                </div>
              </div>
            </div>
          ))}
        </div>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={close} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </div>
  );
}
