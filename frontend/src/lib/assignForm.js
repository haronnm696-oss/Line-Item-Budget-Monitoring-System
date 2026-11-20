/**
 * State rules of the "Assign to project" form: user and project only (the
 * role on a project is always the person's account role).
 *
 * The form must start clean every time it opens. It used to keep its state
 * while closed, so an earlier failed attempt's "required" message stayed on
 * screen next to a full selection; a change now also clears that message.
 */

export const ASSIGN_REQUIRED = 'Choose a user and a project.';

/** Only these projects take new assignments (the server checks the same). */
export const ASSIGNABLE_STATUSES = ['Ongoing', 'Planning'];

export function canAssignTo(project) {
  return ASSIGNABLE_STATUSES.includes(project?.status);
}

/**
 * A clean form. `defaultProjectId` preselects the project (the Team's "Add
 * member" on a project page); `defaultUserId` the person (Users and roles).
 */
export function initialAssignForm(defaultUserId = '', defaultProjectId = '') {
  return {
    userId: defaultUserId ? String(defaultUserId) : '',
    projectId: defaultProjectId ? String(defaultProjectId) : '',
    error: '',
  };
}

export function assignFormReducer(state, action) {
  switch (action.type) {
    case 'open':
      return initialAssignForm(action.userId, action.projectId);
    case 'change':
      return { ...state, [action.field]: action.value == null ? '' : String(action.value), error: '' };
    case 'invalid':
      return { ...state, error: action.error };
    default:
      return state;
  }
}

/**
 * What a submit sends: { payload } or { error }. `users` are the assignable
 * users ({ id, role_label }); the payload carries no role.
 */
export function assignSubmission(state, users = []) {
  if (!state.userId || !state.projectId) return { error: ASSIGN_REQUIRED };
  const user = users.find((row) => String(row.id) === String(state.userId));
  if (!user) return { error: 'This user cannot be assigned. Reload the page and try again.' };
  return { payload: { user_id: Number(state.userId), project_id: Number(state.projectId) } };
}

/** "as Research Assistant": the read-only role shown for the chosen user. */
export function assignRoleText(state, users = []) {
  const user = users.find((row) => String(row.id) === String(state.userId));
  return user?.role_label ? `as ${user.role_label}` : '';
}
