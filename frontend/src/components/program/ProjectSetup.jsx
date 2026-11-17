import { useEffect, useRef, useState } from 'react';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fieldErrors } from '../../api/leader';
import { useApiData } from '../../lib/useApiData';
import { peso } from '../money';

/*
 * Program Leader project set-up dialogs (New project, Edit details, line
 * items, tranches). The server (ProjectSetupService) enforces every rule and
 * writes one audit entry per change; the checks here only give early,
 * clear messages.
 */

const MONEY = /^\d+(\.\d{1,2})?$/;

/** '' -> error text; else null. */
function moneyError(text, { positive = false } = {}) {
  const value = String(text ?? '').trim();
  if (value === '') return 'Enter an amount.';
  if (value.startsWith('-')) return 'The amount cannot be negative.';
  if (!MONEY.test(value)) return 'Enter a number with at most two decimals.';
  if (positive && !(Number(value) > 0)) return 'The amount must be more than zero.';
  return null;
}

function Dialog({ title, labelId, busy, onClose, children }) {
  const panelRef = useRef(null);
  useEffect(() => {
    panelRef.current?.querySelector('input, select, textarea, button:not(.program-icon-btn)')?.focus();
  }, []);

  return (
    <div
      className="program-modal-overlay"
      onClick={busy ? undefined : onClose}
      onKeyDown={(event) => { if (event.key === 'Escape' && !busy) onClose(); }}
      role="dialog"
      aria-modal="true"
      aria-labelledby={labelId}
    >
      <div ref={panelRef} className="program-modal" onClick={(event) => event.stopPropagation()}>
        <div className="program-modal-head">
          <div className="program-modal-title" id={labelId}>{title}</div>
          <button type="button" className="program-icon-btn" onClick={onClose} aria-label="Close" disabled={busy}>×</button>
        </div>
        {children}
      </div>
    </div>
  );
}

/** Label + control + error, wired for screen readers. */
function Field({ id, label, error, hint, full = false, children }) {
  return (
    <div className={`program-field${full ? ' full' : ''}`}>
      <label htmlFor={id}>{label}</label>
      {children({ id, 'aria-invalid': Boolean(error) || undefined, 'aria-describedby': error ? `${id}-error` : (hint ? `${id}-hint` : undefined) })}
      {hint && !error && <p className="pu-hint" id={`${id}-hint`}>{hint}</p>}
      {error && <p className="program-error" id={`${id}-error`}>{error}</p>}
    </div>
  );
}

/** Runs a write: busy state, server field errors, or a general message. */
function useSave(onSaved) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [serverErrors, setServerErrors] = useState({});

  async function save(method, url, body, okText) {
    setBusy(true);
    setError('');
    setServerErrors({});
    try {
      const data = await apiWrite(method, url, body);
      onSaved(data?.message ?? okText, data);
    } catch (failure) {
      const fields = fieldErrors(failure);
      setServerErrors(fields);
      setError(errorText(failure, 'Could not save. Please try again.'));
      setBusy(false);
    }
  }

  return { busy, error, serverErrors, save };
}

// ------------------------------------------------------------------ project

/**
 * New project (mode "create") or Edit details (mode "edit": funder, funder
 * reference, period, total budget, Project Leader).
 */
export function ProjectFormDialog({ mode, projectId = null, setup = null, onClose, onSaved }) {
  const creating = mode === 'create';
  const options = useApiData(ENDPOINTS.programProjectOptions);
  const funders = options.data?.funders ?? [];
  const leaders = options.data?.leaders ?? [];
  const [form, setForm] = useState({
    title: '',
    code: '',
    funding_agency_id: setup?.funding_agency_id ? String(setup.funding_agency_id) : '',
    funder_reference: setup?.funder_reference ?? '',
    start_date: setup?.start_date ?? '',
    end_date: setup?.end_date ?? '',
    total_approved_budget: setup?.total_budget ? String(setup.total_budget) : '',
    project_leader_id: setup?.project_leader_id ? String(setup.project_leader_id) : '',
  });
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved);
  const errors = { ...serverErrors, ...local };
  const set = (key) => (event) => { setForm({ ...form, [key]: event.target.value }); setLocal({ ...local, [key]: undefined }); };

  function submit(event) {
    event.preventDefault();
    const next = {};
    if (creating && !form.title.trim()) next.title = 'Enter the project title.';
    if (creating && !form.code.trim()) next.code = 'Enter the project code.';
    if (!form.funding_agency_id) next.funding_agency_id = 'Choose the funder.';
    if (!form.start_date) next.start_date = 'Enter the start date.';
    if (!form.end_date) next.end_date = 'Enter the end date.';
    else if (form.start_date && form.end_date < form.start_date) next.end_date = 'The end date must be on or after the start date.';
    const budgetError = moneyError(form.total_approved_budget, { positive: true });
    if (budgetError) next.total_approved_budget = budgetError;
    else if (setup && Number(form.total_approved_budget) < Number(setup.allocated)) {
      next.total_approved_budget = `The total budget cannot be less than the line items already allocated (${peso(setup.allocated)}).`;
    }
    if (!form.project_leader_id) next.project_leader_id = 'Choose the Project Leader.';
    setLocal(next);
    if (Object.keys(next).length) return;

    const body = {
      funding_agency_id: Number(form.funding_agency_id),
      funder_reference: form.funder_reference.trim() || null,
      start_date: form.start_date,
      end_date: form.end_date,
      total_approved_budget: Number(form.total_approved_budget),
      project_leader_id: Number(form.project_leader_id),
    };
    if (creating) {
      save('post', ENDPOINTS.programProjects, { ...body, title: form.title.trim(), code: form.code.trim() }, 'Project created.');
    } else {
      save('patch', ENDPOINTS.programProject(projectId), body, 'Project details saved.');
    }
  }

  const text = (key, label, type = 'text', extra = {}) => (
    <Field id={`ps-${key}`} label={label} error={errors[key]} {...extra}>
      {(a11y) => <input {...a11y} className="program-input" type={type} value={form[key]} disabled={busy} onChange={set(key)} inputMode={key === 'total_approved_budget' ? 'decimal' : undefined} />}
    </Field>
  );

  return (
    <Dialog title={creating ? 'New project' : 'Edit project details'} labelId="ps-project-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        {options.status === 'error' && <div className="program-banner error" role="alert">Could not load funders and Project Leaders. Close and try again.</div>}
        <div className="program-form-grid">
          {creating && text('title', 'Project title', 'text', { full: true })}
          {creating && text('code', 'Project code', 'text', { hint: 'For example RP-2026-010' })}
          <Field id="ps-funding_agency_id" label="Funder" error={errors.funding_agency_id}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.funding_agency_id} disabled={busy || options.status !== 'ready'} onChange={set('funding_agency_id')}>
                <option value="">{options.status === 'loading' ? 'Loading…' : 'Choose a funder'}</option>
                {funders.map((funder) => <option key={funder.id} value={funder.id}>{funder.acronym ? `${funder.acronym} · ${funder.name}` : funder.name}</option>)}
              </select>
            )}
          </Field>
          {text('funder_reference', 'Funder reference', 'text', { hint: 'Optional' })}
          {text('start_date', 'Start date', 'date')}
          {text('end_date', 'End date', 'date')}
          {text('total_approved_budget', 'Total budget (₱)')}
          <Field id="ps-project_leader_id" label="Project Leader" error={errors.project_leader_id}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.project_leader_id} disabled={busy || options.status !== 'ready'} onChange={set('project_leader_id')}>
                <option value="">{options.status === 'loading' ? 'Loading…' : 'Choose a Project Leader'}</option>
                {leaders.map((leader) => <option key={leader.id} value={leader.id}>{leader.name}</option>)}
              </select>
            )}
          </Field>
        </div>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : creating ? 'Create project' : 'Save details'}</button>
        </div>
      </form>
    </Dialog>
  );
}

// ------------------------------------------------------------------ line items

/**
 * Add (item null) or edit a line item. `setup` comes from the project page
 * (total budget, allocated, locks).
 */
export function LineItemDialog({ projectId, item = null, setup, onClose, onSaved }) {
  const editing = Boolean(item);
  const used = editing && setup.used_line_items.includes(item.id);
  const amountLocked = editing && setup.allocations_locked;
  const [form, setForm] = useState({
    category_type: item?.type ?? 'MOOE',
    line_item_name: item?.name ?? '',
    allocated_amount: item ? String(item.allocated) : '',
  });
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved);
  const errors = { ...serverErrors, ...local };
  const others = Number(setup.allocated) - (editing ? Number(item.allocated) : 0);
  const left = Math.max(0, Number(setup.total_budget) - others);
  const floor = editing ? Number(item.spent ?? 0) + Number(item.pending ?? 0) : 0;
  const set = (key) => (event) => { setForm({ ...form, [key]: event.target.value }); setLocal({ ...local, [key]: undefined }); };

  function submit(event) {
    event.preventDefault();
    const next = {};
    if (!form.line_item_name.trim()) next.line_item_name = 'Enter the line item name.';
    const amountError = moneyError(form.allocated_amount);
    const amount = Number(form.allocated_amount);
    if (amountError) next.allocated_amount = amountError;
    else if (!(Number(setup.total_budget) > 0)) next.allocated_amount = "Set the project's total budget first (Edit details).";
    else if (others + amount > Number(setup.total_budget) + 0.001) next.allocated_amount = `Line items would total ${peso(others + amount)}, more than the total budget of ${peso(setup.total_budget)}. ${peso(left)} is left to allocate.`;
    else if (editing && amount + 0.001 < floor) next.allocated_amount = `The allocated amount cannot be less than what is already spent and pending (${peso(floor)}).`;
    setLocal(next);
    if (Object.keys(next).length) return;

    if (!editing) {
      save('post', ENDPOINTS.programLineItems(projectId), { ...form, line_item_name: form.line_item_name.trim(), allocated_amount: amount }, 'Line item added.');
      return;
    }
    const body = {};
    if (form.category_type !== item.type) body.category_type = form.category_type;
    if (form.line_item_name.trim() !== item.name) body.line_item_name = form.line_item_name.trim();
    if (amount !== Number(item.allocated)) body.allocated_amount = amount;
    if (Object.keys(body).length === 0) { onClose(); return; }
    save('patch', ENDPOINTS.programLineItem(item.id), body, 'Line item saved.');
  }

  return (
    <Dialog title={editing ? 'Edit line item' : 'Add line item'} labelId="ps-line-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <div className="program-form-grid">
          <Field id="ps-category" label="Category" error={errors.category_type} hint={used ? 'In use: the category cannot change.' : undefined}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.category_type} disabled={busy || used} onChange={set('category_type')}>
                <option value="PS">PS · Personal Services</option>
                <option value="MOOE">MOOE · Maintenance and Other Operating Expenses</option>
              </select>
            )}
          </Field>
          <Field id="ps-line-name" label="Line item name" error={errors.line_item_name}>
            {(a11y) => <input {...a11y} className="program-input" value={form.line_item_name} disabled={busy} onChange={set('line_item_name')} />}
          </Field>
          <Field
            id="ps-allocated"
            label="Allocated amount (₱)"
            full
            error={errors.allocated_amount}
            hint={amountLocked
              ? 'This project has verified expenses: change amounts through a realignment request.'
              : `${peso(left)} of the ${peso(setup.total_budget)} total budget is left to allocate${floor > 0 ? `; at least ${peso(floor)} (spent and pending)` : ''}.`}
          >
            {(a11y) => <input {...a11y} className="program-input" inputMode="decimal" value={form.allocated_amount} disabled={busy || amountLocked} onChange={set('allocated_amount')} />}
          </Field>
        </div>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save line item' : 'Add line item'}</button>
        </div>
      </form>
    </Dialog>
  );
}

// ------------------------------------------------------------------ tranches

/** Add (tranche null) or edit a planned tranche (not released). */
export function TrancheDialog({ projectId, tranche = null, setup, onClose, onSaved }) {
  const editing = Boolean(tranche);
  const [form, setForm] = useState({
    name: tranche?.name ?? '',
    amount: tranche ? String(tranche.amount) : '',
    planned_release_date: tranche?.planned_release_date ?? '',
  });
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved);
  const errors = { ...serverErrors, ...local };
  const others = Number(setup.tranches_total) - (editing ? Number(tranche.amount) : 0);
  const set = (key) => (event) => { setForm({ ...form, [key]: event.target.value }); setLocal({ ...local, [key]: undefined }); };

  function submit(event) {
    event.preventDefault();
    const next = {};
    const amountError = moneyError(form.amount, { positive: true });
    if (amountError) next.amount = amountError;
    else if (Number(setup.total_budget) > 0 && others + Number(form.amount) > Number(setup.total_budget) + 0.001) {
      next.amount = `Tranches would total ${peso(others + Number(form.amount))}, more than the total budget of ${peso(setup.total_budget)}.`;
    }
    if (!form.planned_release_date) next.planned_release_date = 'Enter the planned release date.';
    setLocal(next);
    if (Object.keys(next).length) return;

    const body = { name: form.name.trim() || null, amount: Number(form.amount), planned_release_date: form.planned_release_date };
    if (editing) save('patch', ENDPOINTS.programTranche(tranche.id), body, 'Tranche saved.');
    else save('post', ENDPOINTS.programTranches(projectId), body, 'Tranche added.');
  }

  return (
    <Dialog title={editing ? 'Edit tranche' : 'Add tranche'} labelId="ps-tranche-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <div className="program-form-grid">
          <Field id="ps-tranche-amount" label="Amount (₱)" error={errors.amount}>
            {(a11y) => <input {...a11y} className="program-input" inputMode="decimal" value={form.amount} disabled={busy} onChange={set('amount')} />}
          </Field>
          <Field id="ps-tranche-date" label="Planned release date" error={errors.planned_release_date}>
            {(a11y) => <input {...a11y} className="program-input" type="date" value={form.planned_release_date} disabled={busy} onChange={set('planned_release_date')} />}
          </Field>
          <Field id="ps-tranche-name" label="Name" full error={errors.name} hint="Optional; for example Second tranche">
            {(a11y) => <input {...a11y} className="program-input" value={form.name} disabled={busy} onChange={set('name')} />}
          </Field>
        </div>
        <p className="pu-hint">Releasing a tranche stays in the fund release approval.</p>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save tranche' : 'Add tranche'}</button>
        </div>
      </form>
    </Dialog>
  );
}

// ------------------------------------------------------------------ remove

/** Confirm removing a line item or tranche. */
export function RemoveDialog({ title, text, url, onClose, onSaved }) {
  const { busy, error, save } = useSave(onSaved);

  return (
    <Dialog title={title} labelId="ps-remove-title" busy={busy} onClose={onClose}>
      <div className="pu-form">
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <p className="pu-confirm">{text}</p>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="button" className="program-btn danger" disabled={busy} onClick={() => save('delete', url, undefined, 'Removed.')}>
            {busy ? 'Removing…' : 'Remove'}
          </button>
        </div>
      </div>
    </Dialog>
  );
}
