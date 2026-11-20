/*
 * State of the Program Leader "Funders" dialog (add / edit funders), kept as a
 * pure reducer so it can be unit tested without a DOM. Every save ends in
 * "saved" or "failed", and both clear `busy`, so Close, ×, Escape and
 * "Add funder" are always usable again after a save.
 */

const EMPTY_FORM = { name: '', acronym: '' };

export const initialFunderDialog = {
  editing: null, // funder id, 'new' or null
  form: EMPTY_FORM,
  local: {}, // client-side field errors
  serverErrors: {},
  error: '',
  notice: '',
  busy: false,
};

export function funderDialogReducer(state, action) {
  switch (action.type) {
    case 'start':
      return {
        ...state,
        editing: action.funder ? action.funder.id : 'new',
        form: {
          name: action.funder?.name ?? '',
          acronym: action.funder?.short_name ?? action.funder?.acronym ?? '',
        },
        local: {},
        serverErrors: {},
        error: '',
        notice: '',
      };
    case 'cancel':
      return { ...state, editing: null, form: EMPTY_FORM, local: {}, serverErrors: {}, error: '' };
    case 'field':
      return {
        ...state,
        form: { ...state.form, [action.key]: action.value },
        local: { ...state.local, [action.key]: undefined },
      };
    case 'invalid':
      return { ...state, local: action.errors };
    case 'submit':
      return { ...state, busy: true, error: '', serverErrors: {}, notice: '' };
    case 'saved':
      return { ...state, busy: false, editing: null, form: EMPTY_FORM, local: {}, serverErrors: {}, error: '', notice: action.message };
    case 'failed':
      return { ...state, busy: false, error: action.error, serverErrors: action.serverErrors ?? {} };
    default:
      return state;
  }
}

/** Client-side checks before saving; {} when the form is valid. */
export function validateFunder(form) {
  const errors = {};
  if (!form.name.trim()) errors.name = "Enter the funder's full name.";
  if (!form.acronym.trim()) errors.acronym = 'Enter the short name shown on screens.';
  return errors;
}

/**
 * Runs one save through the reducer: "submit", then always "saved" or
 * "failed". `write(method, which, body)` does the request (`which` is 'new'
 * or the funder id); `describe(failure)` gives { error, serverErrors }.
 */
export async function saveFunder({ state, dispatch, write, describe }) {
  const errors = validateFunder(state.form);
  dispatch({ type: 'invalid', errors });
  if (Object.keys(errors).length) return false;
  const body = { name: state.form.name.trim(), acronym: state.form.acronym.trim() };
  const adding = state.editing === 'new';
  dispatch({ type: 'submit' });
  try {
    const data = await write(adding ? 'post' : 'patch', state.editing, body);
    dispatch({ type: 'saved', message: data?.message ?? (adding ? 'Funder added.' : 'Funder saved.') });
    return true;
  } catch (failure) {
    const { error, serverErrors } = describe(failure);
    dispatch({ type: 'failed', error: error || 'Could not save. Please try again.', serverErrors });
    return false;
  }
}

/** Close, × and Escape work whenever no save is in flight. */
export const canClose = (state) => !state.busy;

/** "MOST · Ministry of Science and Technology - BARMM · 0 projects". */
export function funderSummary(funder) {
  const count = Number(funder.project_count ?? 0);
  const projects = `${count} ${count === 1 ? 'project' : 'projects'}`;
  const short = String(funder.short_name ?? funder.acronym ?? '').trim();
  const name = String(funder.name ?? '').trim();
  return { short: short || name, detail: short && short !== name ? `${name} · ${projects}` : projects };
}
