import { Fragment, useEffect, useReducer, useRef, useState } from 'react';
import { funderDialogReducer, funderSummary, initialFunderDialog, saveFunder } from '../../lib/funderDialog';
import { ENDPOINTS } from '../../api/endpoints';
import { apiWrite } from '../../api/http';
import { errorText, fieldErrors } from '../../api/leader';
import { useApiData } from '../../lib/useApiData';
import { peso } from '../money';
import { RATE_PERIODS, computedAmount, hasComputation, periodsLabel, previewComputation } from '../../lib/lineItemComputation';
import { sourceLabels } from '../../lib/fundingSource';
import { durationInWords, periodErrors } from '../../lib/projectAgreement';
import { amountFromPercent, percentError, percentFromAmount } from '../../lib/releasePlan';
import { itemName, withUnitDescription } from '../../lib/lineItemNames';
import { moneyError, moneyNumber, moneyText } from '../../lib/moneyInput';
import { DATE_BOUNDS, dateRangeError } from '../../lib/dateRange';
import MoneyInput from '../MoneyInput';
import { endDateActive, leaderChoices } from '../../lib/leaderRule';
import { todayInManila } from '../../lib/datetime';

/*
 * Program Leader project set-up dialogs (New project, Edit details, line
 * items, releases). The server (ProjectSetupService) enforces every rule and
 * writes one audit entry per change; the checks here only give early,
 * clear messages.
 */


// Open dialogs, innermost last: Escape closes only the top one.
const openDialogs = [];

export function Dialog({ title, labelId, busy, onClose, children }) {
  const panelRef = useRef(null);
  const latest = useRef({ busy, onClose });
  useEffect(() => { latest.current = { busy, onClose }; });

  useEffect(() => {
    panelRef.current?.querySelector('input, select, textarea, button:not(.program-icon-btn)')?.focus();
  }, []);

  // Escape is handled on the document, not the overlay: after a save the
  // focused form can unmount, leaving focus on <body> outside the dialog.
  useEffect(() => {
    const token = {};
    openDialogs.push(token);
    function onKeyDown(event) {
      if (event.key !== 'Escape' || openDialogs[openDialogs.length - 1] !== token) return;
      if (latest.current.busy) return;
      event.preventDefault();
      latest.current.onClose();
    }
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      openDialogs.splice(openDialogs.indexOf(token), 1);
    };
  }, []);

  return (
    <div
      className="program-modal-overlay"
      onClick={busy ? undefined : onClose}
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
export function Field({ id, label, error, hint, full = false, children }) {
  return (
    <div className={`program-field${full ? ' full' : ''}`}>
      <label htmlFor={id}>{label}</label>
      {children({ id, 'aria-invalid': Boolean(error) || undefined, 'aria-describedby': error ? `${id}-error` : (hint ? `${id}-hint` : undefined) })}
      {hint && !error && <p className="pu-hint" id={`${id}-hint`}>{hint}</p>}
      {error && <p className="program-error" id={`${id}-error`}>{error}</p>}
    </div>
  );
}

/**
 * Runs a write: busy state, server field errors, or a general message
 * (`failText`; a server error never shows the server's own text). On a
 * failure the dialog stays open, so what was typed is kept.
 */
export function useSave(onSaved, { failText = 'Could not save. Please try again.' } = {}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [serverErrors, setServerErrors] = useState({});

  async function save(method, url, body, okText) {
    setBusy(true);
    setError('');
    setServerErrors({});
    let data;
    try {
      data = await apiWrite(method, url, body);
    } catch (failure) {
      setServerErrors(fieldErrors(failure));
      setError(errorText(failure, failText));
      setBusy(false);
      return;
    }
    // Clear busy on success too: a dialog that stays open after saving must
    // not be left with its buttons disabled.
    setBusy(false);
    onSaved(data?.message ?? okText, data);
  }

  return { busy, error, serverErrors, save };
}

// ------------------------------------------------------------------ project

/**
 * New project (mode "create") or Edit details (mode "edit": funder, funder
 * reference, period, total budget, Project Leader). Both carry the optional
 * fields from the Memorandum of Agreement (MOA_FIELDS).
 */
/**
 * Printed on R&D Form B, editable per project (all optional): the Monitoring
 * Agency (blank = the funder's name) and the four signatories, whose name and
 * position print under blank signature lines. Never hard-coded.
 */
const FORM_B_SIGNATORIES = [
  ['reviewed_by', 'Reviewed by'],
  ['funds_certified_by', 'Certified Funds Available'],
  ['recommending_approval', 'Recommending Approval'],
  ['approved_by', 'Approved'],
];
const MOA_FIELDS = ['moa_number', 'moa_date', 'rd_committee_approved_date', 'fund_source'];
const MOA_LIMITS = { moa_number: 100, fund_source: 255 };
const FORM_B_FIELDS = ['implementing_agency_name', 'monitoring_agency', ...FORM_B_SIGNATORIES.flatMap(([prefix]) => [`${prefix}_name`, `${prefix}_position`])];

const PROJECT_SAVE_ERROR = 'The project could not be saved. Please try again; if it keeps failing, tell the system administrator.';

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
    total_approved_budget: setup?.total_budget ? moneyText(setup.total_budget) : '',
    project_leader_id: setup?.project_leader_id ? String(setup.project_leader_id) : '',
    ...Object.fromEntries(MOA_FIELDS.map((key) => [key, setup?.agreement?.[key] ?? ''])),
    // The funder's grant per the MOA (projects.funding_agency_amount); blank = not entered.
    funding_agency_amount: setup?.funder_grant ? moneyText(setup.funder_grant) : '',
    ...Object.fromEntries(FORM_B_FIELDS.map((key) => [key, setup?.form_b?.[key] ?? ''])),
  });
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved, { failText: PROJECT_SAVE_ERROR });
  const errors = { ...serverErrors, ...local };
  const duration = durationInWords(form.start_date, form.end_date);
  const set = (key) => (event) => { setForm({ ...form, [key]: event.target.value }); setLocal({ ...local, [key]: undefined }); };

  function submit(event) {
    event.preventDefault();
    const next = {};
    if (creating && !form.title.trim()) next.title = 'Enter the project title.';
    if (creating && !form.code.trim()) next.code = 'Enter the project code.';
    if (!form.funding_agency_id) next.funding_agency_id = 'Choose the funder.';
    Object.assign(next, periodErrors(form.start_date, form.end_date));
    for (const key of ['moa_date', 'rd_committee_approved_date']) {
      const rangeError = dateRangeError(form[key]);
      if (rangeError) next[key] = rangeError;
    }
    for (const [key, limit] of Object.entries(MOA_LIMITS)) {
      if (String(form[key] ?? '').trim().length > limit) next[key] = `Use ${limit} characters or fewer.`;
    }
    const budgetError = moneyError(form.total_approved_budget, { positive: true });
    if (budgetError) next.total_approved_budget = budgetError;
    else if (setup && moneyNumber(form.total_approved_budget) < Number(setup.allocated)) {
      next.total_approved_budget = `The total budget cannot be less than the line items already allocated (${peso(setup.allocated)}).`;
    }
    if (String(form.funding_agency_amount).trim() !== '') {
      const grantError = moneyError(form.funding_agency_amount);
      if (grantError) next.funding_agency_amount = grantError;
      else if (!budgetError && moneyNumber(form.funding_agency_amount) > moneyNumber(form.total_approved_budget) + 0.001) {
        next.funding_agency_amount = `The grant amount cannot be more than the total budget (${peso(moneyNumber(form.total_approved_budget))}).`;
      }
    }
    if (!form.project_leader_id) next.project_leader_id = 'Choose the Project Leader.';
    for (const key of FORM_B_FIELDS) {
      if (String(form[key] ?? '').trim().length > 255) next[key] = 'Use 255 characters or fewer.';
    }
    setLocal(next);
    if (Object.keys(next).length) return;

    const body = {
      funding_agency_id: Number(form.funding_agency_id),
      funder_reference: form.funder_reference.trim() || null,
      start_date: form.start_date,
      end_date: form.end_date,
      total_approved_budget: moneyNumber(form.total_approved_budget),
      project_leader_id: Number(form.project_leader_id),
      ...Object.fromEntries(MOA_FIELDS.map((key) => [key, String(form[key] ?? '').trim() || null])),
      funding_agency_amount: String(form.funding_agency_amount).trim() === '' ? null : moneyNumber(form.funding_agency_amount),
    };
    if (creating) {
      save('post', ENDPOINTS.programProjects, { ...body, title: form.title.trim(), code: form.code.trim() }, 'Project created.');
    } else {
      const printed = Object.fromEntries(FORM_B_FIELDS.map((key) => [key, String(form[key] ?? '').trim() || null]));
      save('patch', ENDPOINTS.programProject(projectId), { ...body, ...printed }, 'Project details saved.');
    }
  }

  const text = (key, label, type = 'text', extra = {}) => (
    <Field id={`ps-${key}`} label={label} error={errors[key]} {...extra}>
      {(a11y) => (type === 'money'
        ? <MoneyInput {...a11y} value={form[key]} disabled={busy} onChange={set(key)} />
        : <input {...a11y} className="program-input" type={type} value={form[key]} disabled={busy} onChange={set(key)} {...(type === 'date' ? DATE_BOUNDS : {})} />)}
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
          {text('end_date', 'End date', 'date', { hint: duration ? `Duration: ${duration}` : 'After the start date' })}
          {text('total_approved_budget', 'Total budget (₱)', 'money', { hint: 'For example 3,178,376.00' })}
          <Field id="ps-project_leader_id" label="Project Leader" error={errors.project_leader_id} hint="A Project Leader leads one active project at a time; people who already lead one are greyed.">
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.project_leader_id} disabled={busy || options.status !== 'ready'} onChange={set('project_leader_id')}>
                <option value="">{options.status === 'loading' ? 'Loading…' : 'Choose a Project Leader'}</option>
                {/* One active project per Project Leader: people leading another active project are greyed, with it. */}
                {leaderChoices(leaders, {
                  projectId,
                  selectedId: form.project_leader_id,
                  projectActive: endDateActive(form.end_date, todayInManila()),
                }).map((leader) => (
                  <option key={leader.id} value={leader.id} disabled={leader.disabled}>{leader.label}</option>
                ))}
              </select>
            )}
          </Field>
        </div>
        <fieldset className="ps-formb">
          <legend>Memorandum of Agreement <span className="pu-hint">(optional)</span></legend>
          <div className="program-form-grid">
            {text('moa_number', 'MOA number')}
            {text('moa_date', 'MOA date', 'date')}
            {text('rd_committee_approved_date', "Approved by the funder's R&D committee", 'date')}
            {text('funding_agency_amount', 'Grant amount from the funder (per MOA) (₱)', 'money', {
              hint: 'For example 2,000,000.00. Releases are planned as parts of this amount.',
            })}
            {text('fund_source', 'Fund source', 'text', { full: true, hint: 'For example MOST-BARMM GAAB 2024 Program Funds. Printed on Form B as "Chargeable against …".' })}
          </div>
        </fieldset>
        {!creating && (
          <fieldset className="ps-formb">
            <legend>Printed on Form B <span className="pu-hint">(optional)</span></legend>
            <div className="program-form-grid">
              {text('implementing_agency_name', 'Implementing Agency', 'text', {
                full: true,
                hint: `For example MSU-Marawi. Leave blank to print ${setup?.labels?.implementing_agency || 'the default implementing agency'}.`,
              })}
              {text('monitoring_agency', 'Monitoring agency', 'text', { full: true, hint: "Leave blank to print the funder's name." })}
              {FORM_B_SIGNATORIES.map(([prefix, label]) => (
                <Fragment key={prefix}>
                  {text(`${prefix}_name`, `${label}: name`)}
                  {text(`${prefix}_position`, `${label}: position`)}
                </Fragment>
              ))}
            </div>
          </fieldset>
        )}
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : creating ? 'Create project' : 'Save details'}</button>
        </div>
      </form>
    </Dialog>
  );
}

// ------------------------------------------------------------------ line items

/** Expense groups of R&D Form B, offered as suggestions (any name is allowed). */
const EXPENSE_GROUPS = [
  'Honoraria', 'Salaries and Wages', 'Traveling Expenses', 'Training Expenses', 'Supplies and Materials Expenses',
  'Communication Expenses', 'Professional Services', 'Utilities', 'Printing and Binding Expenses',
  'Other Maintenance and Operating Expenses',
];

const WHOLE = /^\d+$/;

/** The line item's values as form strings. */
function lineItemForm(item) {
  const text = (value) => (value == null ? '' : String(value));
  return {
    category_type: item?.type ?? 'MOOE',
    cost_type: item?.cost_type ?? 'Direct',
    expense_group: text(item?.group),
    expense_subgroup: text(item?.subgroup),
    line_item_name: text(item?.name),
    quantity: text(item?.quantity),
    unit_description: text(item?.unit_description),
    rate: moneyText(item?.rate),
    rate_period: text(item?.rate_period),
    periods: text(item?.periods),
    implementing_agency_amount: item ? moneyText(item.implementing_agency_amount ?? 0) : '',
    funder_amount: item ? moneyText(item.funder_amount ?? item.allocated ?? 0) : '',
  };
}

/** Request body from the form (computation fields as numbers or null). */
function lineItemBody(form, computed) {
  const number = (value) => (String(value).trim() === '' ? null : Number(value));
  // Peso fields accept "8,800.00" (lib/moneyInput).
  const money = (value) => (String(value).trim() === '' ? null : moneyNumber(value));
  const counterpart = money(form.implementing_agency_amount) ?? 0;
  return {
    category_type: form.category_type,
    cost_type: form.cost_type,
    expense_group: form.expense_group.trim() || null,
    expense_subgroup: form.expense_subgroup.trim() || null,
    // Item, or "Who or what" when Item was left empty (lib/lineItemNames).
    line_item_name: itemName(form),
    quantity: number(form.quantity),
    unit_description: form.unit_description.trim() || null,
    rate: money(form.rate),
    rate_period: form.rate_period || null,
    periods: number(form.periods),
    implementing_agency_amount: counterpart,
    // With a computation the funder amount is the computed amount (the server sets it too).
    funder_amount: computed != null ? computed : (money(form.funder_amount) ?? 0),
  };
}

/**
 * Add (item null) or edit a line item, R&D Form B style: category, cost
 * type, expense group (and optional sub-group), name, an optional
 * computation, and the two amounts (implementing agency counterpart and the
 * project's funder). `setup` comes from the project page (total budget,
 * allocated, locks, labels).
 */
export function LineItemDialog({ projectId, item = null, setup, onClose, onSaved }) {
  const editing = Boolean(item);
  const used = editing && setup.used_line_items.includes(item.id);
  const amountLocked = editing && setup.allocations_locked;
  const labels = sourceLabels(setup.labels);
  const [form, setForm] = useState(() => lineItemForm(item));
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved);
  const errors = { ...serverErrors, ...local };
  const computing = hasComputation(form);
  const computed = computing ? computedAmount(form) : null;
  const counterpart = moneyNumber(form.implementing_agency_amount) || 0;
  // As on Form B: the computation is the funder amount; the counterpart is added.
  const funder = computed != null ? computed : moneyNumber(form.funder_amount) || 0;
  const total = Math.round((counterpart + funder) * 100) / 100;
  const others = Number(setup.allocated) - (editing ? Number(item.allocated) : 0);
  const left = Math.max(0, Number(setup.total_budget) - others);
  const set = (key) => (event) => { setForm({ ...form, [key]: event.target.value }); setLocal({ ...local, [key]: undefined }); };
  // One time has no periods field: switching to it clears the periods (blank counts as 1).
  const setRatePeriod = (event) => {
    const value = event.target.value;
    setForm({ ...form, rate_period: value, ...(value === '' ? { periods: '' } : {}) });
    setLocal({ ...local, rate_period: undefined, periods: undefined });
  };
  // "Who or what" fills Item too while Item is untouched (lib/lineItemNames).
  const setUnitDescription = (event) => {
    setForm(withUnitDescription(form, event.target.value));
    setLocal({ ...local, unit_description: undefined, line_item_name: undefined });
  };
  // Older one-time rows may still carry a periods count; keep it visible so it can be edited.
  const showPeriods = form.rate_period !== '' || String(form.periods).trim() !== '';
  const preview = previewComputation(form);

  function submit(event) {
    event.preventDefault();
    const next = {};
    if (!itemName(form)) next.line_item_name = 'Enter the line item name.';
    const anyComputation = ['quantity', 'rate', 'periods', 'unit_description', 'rate_period'].some((key) => String(form[key]).trim() !== '');
    if (anyComputation) {
      if (!WHOLE.test(String(form.quantity).trim()) || Number(form.quantity) < 1) next.quantity = 'Enter a whole number of at least 1.';
      const rateError = moneyError(form.rate);
      if (rateError) next.rate = rateError;
      if (String(form.periods).trim() !== '' && (!WHOLE.test(String(form.periods).trim()) || Number(form.periods) < 1)) next.periods = 'Enter a whole number of at least 1.';
    }
    const counterpartError = String(form.implementing_agency_amount).trim() === '' ? null : moneyError(form.implementing_agency_amount);
    if (counterpartError) next.implementing_agency_amount = counterpartError;
    if (!computing) {
      const funderError = String(form.funder_amount).trim() === '' ? null : moneyError(form.funder_amount);
      if (funderError) next.funder_amount = funderError;
    }
    if (!next.funder_amount && !next.implementing_agency_amount && !(total > 0) && !anyComputation) {
      next.funder_amount = 'Enter the amounts or the computation.';
    } else if (!(Number(setup.total_budget) > 0)) {
      next.funder_amount = "Set the project's total budget first (Edit details).";
    } else if (others + total > Number(setup.total_budget) + 0.001) {
      next.funder_amount = `Line items would total ${peso(others + total)}, more than the total budget of ${peso(setup.total_budget)}. ${peso(left)} is left to allocate.`;
    }
    setLocal(next);
    if (Object.keys(next).length) return;

    const body = lineItemBody(form, computed);
    if (!editing) {
      save('post', ENDPOINTS.programLineItems(projectId), body, 'Line item added.');
      return;
    }
    const before = lineItemBody(lineItemForm(item), computedAmount(lineItemForm(item)));
    const changes = Object.fromEntries(Object.entries(body).filter(([key, value]) => value !== before[key]));
    if (amountLocked) {
      for (const key of ['quantity', 'rate', 'rate_period', 'periods', 'implementing_agency_amount', 'funder_amount']) delete changes[key];
    }
    if (Object.keys(changes).length === 0) { onClose(); return; }
    save('patch', ENDPOINTS.programLineItem(item.id), changes, 'Line item saved.');
  }

  const input = (key, label, extra = {}) => (
    <Field id={`ps-li-${key}`} label={label} error={errors[key]} hint={extra.hint} full={extra.full}>
      {(a11y) => (extra.money ? (
        <MoneyInput {...a11y} value={form[key]} disabled={busy || extra.disabled} onChange={set(key)} />
      ) : (
        <input
          {...a11y}
          className="program-input"
          value={form[key]}
          disabled={busy || extra.disabled}
          onChange={extra.onChange ?? set(key)}
          inputMode={extra.inputMode}
          list={extra.list}
          readOnly={extra.readOnly}
        />
      ))}
    </Field>
  );

  return (
    <Dialog title={editing ? 'Edit line item' : 'Add line item'} labelId="ps-line-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <div className="program-form-grid fb-form-grid">
          <Field id="ps-category" label="Category" error={errors.category_type} hint={used ? 'In use: the category cannot change.' : undefined}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.category_type} disabled={busy || used} onChange={set('category_type')}>
                <option value="PS">PS · Personal Services</option>
                <option value="MOOE">MOOE · Maintenance and Other Operating Expenses</option>
              </select>
            )}
          </Field>
          <Field id="ps-cost-type" label="Cost type" error={errors.cost_type}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.cost_type} disabled={busy} onChange={set('cost_type')}>
                <option value="Direct">Direct cost</option>
                <option value="Indirect">Indirect cost</option>
              </select>
            )}
          </Field>
          {input('expense_group', 'Expense group', { list: 'ps-li-groups', hint: 'For example Honoraria or Supplies and Materials Expenses' })}
          {input('expense_subgroup', 'Sub-group', { hint: 'Optional; for example Semi-Expendable Machinery and Equipment' })}
          <datalist id="ps-li-groups">
            {EXPENSE_GROUPS.map((group) => <option key={group} value={group} />)}
          </datalist>
          {input('line_item_name', 'Item', { full: true, hint: 'For example Project Leader or IoT devices. Filled from "Who or what" until you change it.' })}

          <div className="program-field full">
            <span className="program-label">Compute the {labels.funder} amount <span className="ld-muted">optional</span></span>
            <p className="pu-hint">For example 1 Project Leader × ₱8,800.00 per month × 12 months. The counterpart is entered on its own and added.</p>
          </div>
          {input('quantity', 'How many', { inputMode: 'numeric', disabled: amountLocked })}
          {input('unit_description', 'Who or what', { hint: 'e.g. Project Leader, Laptop', onChange: setUnitDescription })}
          {input('rate', 'Amount each (₱)', { money: true, disabled: amountLocked })}
          <Field id="ps-li-rate_period" label="Paid every" error={errors.rate_period}>
            {(a11y) => (
              <select {...a11y} className="program-select" value={form.rate_period} disabled={busy || amountLocked} onChange={setRatePeriod}>
                {RATE_PERIODS.map((period) => <option key={period.value} value={period.value}>{period.label}</option>)}
              </select>
            )}
          </Field>
          {showPeriods && input('periods', periodsLabel(form.rate_period), { inputMode: 'numeric', disabled: amountLocked, hint: 'Optional; 1 when left blank' })}
          <div className="program-field" aria-live="polite">
            <span className="program-label">{labels.funder} total</span>
            <p className="fb-computed">
              {computed != null ? <b>{peso(computed)}</b> : 'Not computed: fill in How many and Amount each, or enter both amounts below.'}
            </p>
          </div>
          {preview && <p className="pu-hint program-field full" aria-live="polite">{preview}</p>}

          {input('implementing_agency_amount', `${labels.implementing_agency} counterpart (₱)`, {
            money: true, disabled: amountLocked, hint: 'Leave blank or 0 when there is none',
          })}
          <Field
            id="ps-li-funder_amount"
            label={`${labels.funder} (₱)`}
            error={errors.funder_amount}
            hint={computing ? 'Set by the computation above.' : undefined}
          >
            {(a11y) => (computing ? (
              <input {...a11y} className="program-input" value={computed != null ? moneyText(computed) : ''} readOnly disabled={busy || amountLocked} />
            ) : (
              <MoneyInput {...a11y} value={form.funder_amount} disabled={busy || amountLocked} onChange={set('funder_amount')} />
            ))}
          </Field>
        </div>
        <p className="ld-hint" role="status">
          Allocated: <b>{peso(total)}</b> ({labels.implementing_agency} {peso(counterpart)} + {labels.funder} {peso(funder)})
          {' · '}
          {amountLocked
            ? 'This project has verified expenses: change amounts through a realignment request.'
            : `${peso(left)} of the ${peso(setup.total_budget)} total budget is left to allocate.`}
        </p>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save line item' : 'Add line item'}</button>
        </div>
      </form>
    </Dialog>
  );
}

// ------------------------------------------------------------------ tranches

/**
 * Add (tranche null) or edit a planned release (not released). Releases are
 * numbered "Release 1", "Release 2"… in the order added and keep that
 * number (stored by the server), so the only name
 * field is an optional milestone. API fields keep the name "tranche".
 */
export function TrancheDialog({ projectId, tranche = null, setup, onClose, onSaved }) {
  const editing = Boolean(tranche);
  const funderTotal = Number(setup.funder_total ?? setup.total_budget) || 0;
  const [form, setForm] = useState(() => {
    const amount = tranche ? moneyText(tranche.amount) : '';
    return {
      milestone: tranche?.milestone ?? '',
      amount,
      percent: percentFromAmount(amount, funderTotal),
      planned_release_date: tranche?.planned_release_date ?? '',
    };
  });
  const [local, setLocal] = useState({});
  const { busy, error, serverErrors, save } = useSave(onSaved);
  const errors = { ...serverErrors, ...local };
  const others = Number(setup.tranches_total) - (editing ? Number(tranche.amount) : 0);
  const unscheduled = Math.round((funderTotal - others) * 100) / 100;
  const set = (key) => (event) => { setForm({ ...form, [key]: event.target.value }); setLocal({ ...local, [key]: undefined }); };
  // Amount or percent: typing one computes the other.
  const setAmount = (event) => {
    const amount = event.target.value;
    setForm({ ...form, amount, percent: percentFromAmount(amount, funderTotal) });
    setLocal({ ...local, amount: undefined, percent: undefined });
  };
  const setPercent = (event) => {
    const percent = event.target.value;
    setForm({ ...form, percent, amount: amountFromPercent(percent, funderTotal) || (percent.trim() === '' ? '' : form.amount) });
    setLocal({ ...local, amount: undefined, percent: undefined });
  };

  function submit(event) {
    event.preventDefault();
    const next = {};
    const shareError = percentError(form.percent);
    if (shareError) next.percent = shareError;
    const amountError = moneyError(form.amount, { positive: true });
    if (amountError) next.amount = amountError;
    else if (funderTotal > 0 && others + moneyNumber(form.amount) > funderTotal + 0.001) {
      next.amount = `Releases would total ${peso(others + moneyNumber(form.amount))}, more than the funder total of ${peso(funderTotal)}.`;
    }
    if (!form.planned_release_date) next.planned_release_date = 'Enter the planned release date.';
    else if (dateRangeError(form.planned_release_date)) next.planned_release_date = dateRangeError(form.planned_release_date);
    setLocal(next);
    if (Object.keys(next).length) return;

    const body = { name: form.milestone.trim() || null, amount: moneyNumber(form.amount), planned_release_date: form.planned_release_date };
    if (editing) save('patch', ENDPOINTS.programTranche(tranche.id), body, 'Release saved.');
    else save('post', ENDPOINTS.programTranches(projectId), body, 'Release added.');
  }

  return (
    <Dialog title={editing ? `Edit ${tranche.name}` : 'Add release'} labelId="ps-tranche-title" busy={busy} onClose={onClose}>
      <form className="pu-form" onSubmit={submit} noValidate>
        {error && <div className="program-banner error" role="alert">{error}</div>}
        <div className="program-form-grid">
          <Field id="ps-tranche-amount" label="Amount (₱)" error={errors.amount}>
            {(a11y) => <MoneyInput {...a11y} value={form.amount} disabled={busy} onChange={setAmount} />}
          </Field>
          <Field
            id="ps-tranche-percent"
            label="Percent of the funder total (%)"
            error={errors.percent}
            hint={funderTotal > 0 ? `Of ${peso(funderTotal)}; type either one` : "Set the project's total budget first (Edit details)."}
          >
            {(a11y) => <input {...a11y} className="program-input" inputMode="decimal" value={form.percent} disabled={busy || !(funderTotal > 0)} onChange={setPercent} />}
          </Field>
          <Field id="ps-tranche-date" label="Planned release date" error={errors.planned_release_date}>
            {(a11y) => <input {...a11y} className="program-input" type="date" {...DATE_BOUNDS} value={form.planned_release_date} disabled={busy} onChange={set('planned_release_date')} />}
          </Field>
          <Field id="ps-tranche-name" label="Milestone (optional)" full error={errors.name} hint="e.g. Upon signing of the MOA, Upon liquidation of Release 1">
            {(a11y) => <input {...a11y} className="program-input" value={form.milestone} disabled={busy} onChange={set('milestone')} />}
          </Field>
        </div>
        {funderTotal > 0 && (
          <p className="ld-hint" role="status">
            {unscheduled >= 0
              ? `${peso(unscheduled)} of the ${peso(funderTotal)} funder total is not yet scheduled${editing ? ' (besides this release)' : ''}.`
              : `⚠ The other releases are ${peso(-unscheduled)} over the ${peso(funderTotal)} funder total.`}
          </p>
        )}
        <p className="pu-hint">A new release gets the next number. Changing a date never renumbers; removing a scheduled release that was never requested frees its number. Releasing the funds stays in the fund release approval.</p>
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Cancel</button>
          <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save release' : 'Add release'}</button>
        </div>
      </form>
    </Dialog>
  );
}

// ------------------------------------------------------------------ remove

/** Confirm removing a line item or release. */
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

// ------------------------------------------------------------------ funders

/**
 * Funders: each has a full name ("Ministry of Science and Technology -
 * BARMM") and a short name ("MOST") that labels the funder column of every
 * Line-Item Budget. Program Leader only; no funder is built in.
 */
export function FundersDialog({ onClose }) {
  const list = useApiData(ENDPOINTS.programFunders);
  const funders = list.data?.funders ?? [];
  const [state, dispatch] = useReducer(funderDialogReducer, initialFunderDialog);
  const { editing, form, local, notice, busy, error, serverErrors } = state;
  const errors = { ...serverErrors, ...local };

  const start = (funder) => dispatch({ type: 'start', funder });

  async function submit(event) {
    event.preventDefault();
    if (busy) return;
    const saved = await saveFunder({
      state,
      dispatch,
      write: (method, which, body) => apiWrite(method, which === 'new' ? ENDPOINTS.programFunders : ENDPOINTS.programFunder(which), body),
      describe: (failure) => ({ error: errorText(failure, 'Could not save. Please try again.'), serverErrors: fieldErrors(failure) }),
    });
    if (saved) list.retry();
  }

  const set = (key) => (event) => dispatch({ type: 'field', key, value: event.target.value });
  const fields = (
    <form className="pu-form" onSubmit={submit} noValidate>
      {error && <div className="program-banner error" role="alert">{error}</div>}
      <div className="program-form-grid">
        <Field id="ps-funder-name" label="Full name" error={errors.name} full hint="The funder's official name">
          {(a11y) => <input {...a11y} className="program-input" value={form.name} disabled={busy} onChange={set('name')} />}
        </Field>
        <Field id="ps-funder-acronym" label="Short name" error={errors.acronym} hint="The acronym shown as the funder column">
          {(a11y) => <input {...a11y} className="program-input" value={form.acronym} disabled={busy} onChange={set('acronym')} />}
        </Field>
      </div>
      <div className="program-modal-actions">
        <button type="button" className="program-btn" onClick={() => dispatch({ type: 'cancel' })} disabled={busy}>Cancel</button>
        <button type="submit" className="program-btn primary" disabled={busy}>{busy ? 'Saving…' : editing === 'new' ? 'Add funder' : 'Save funder'}</button>
      </div>
    </form>
  );

  return (
    <Dialog title="Funders" labelId="ps-funders-title" busy={busy} onClose={onClose}>
      <div aria-live="polite">{notice && <div className="program-banner ok">{notice}</div>}</div>
      {list.status === 'error' && (
        <div className="program-banner error" role="alert">
          Could not load the funders. <button type="button" className="program-btn sm" onClick={list.retry}>Try again</button>
        </div>
      )}
      {list.status === 'loading' && <p className="pu-hint">Loading funders…</p>}
      {list.status === 'ready' && funders.length === 0 && <p className="pu-hint">No funders yet. Add the first one.</p>}
      {funders.length > 0 && (
        <ul className="pd-list">
          {funders.map((funder) => {
            const summary = funderSummary(funder);
            return (
            <li key={funder.id}>
              {editing === funder.id ? fields : (
                <div className="pd-actions" style={{ display: 'flex', alignItems: 'center', gap: 12, justifyContent: 'space-between', textAlign: 'left' }}>
                  <span>
                    <b>{summary.short}</b>
                    <span> · {summary.detail}</span>
                  </span>
                  <button type="button" className="program-btn sm" onClick={() => start(funder)} disabled={busy}>
                    Edit<span className="pa-sr-only"> {funder.name}</span>
                  </button>
                </div>
              )}
            </li>
            );
          })}
        </ul>
      )}
      {editing === 'new' ? fields : (
        <div className="program-modal-actions">
          <button type="button" className="program-btn" onClick={onClose} disabled={busy}>Close</button>
          <button type="button" className="program-btn primary" onClick={() => start(null)} disabled={busy}>Add funder</button>
        </div>
      )}
    </Dialog>
  );
}
