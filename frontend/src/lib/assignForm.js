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

export const NO_PROJECTS_LEFT = 'No other projects to add.';

/** Why a Project Leader cannot be added to a project that already has one (the server refuses the same). */
export function leaderTakenReason(project) {
  return `Already led by ${project.leader.name}. A project has one Project Leader.`;
}

/**
 * The project options for one person: active projects they are not already
 * on (`user.project_ids`). For a Project Leader, a project that already has
 * another leader stays listed but disabled, with `reason`. With no person
 * chosen yet, every active project is listed.
 *
 * projects: [{ id, title, code, status, leader: { id, name } | null }]
 */
export function projectChoices(projects = [], user = null) {
  const assigned = new Set((user?.project_ids ?? []).map(String));
  const isLeader = user?.role_slug === 'project-leader';
  return projects
    .filter((project) => canAssignTo(project) && !assigned.has(String(project.id)))
    .map((project) => {
      const taken = isLeader && project.leader && String(project.leader.id) !== String(user.id);
      return { ...project, disabled: Boolean(taken), reason: taken ? leaderTakenReason(project) : '' };
    });
}

/**
 * What a submit sends: { payload } or { error }. `users` are the assignable
 * users ({ id, role_label }); the payload carries no role. With `projects`,
 * the chosen project must be one of that person's enabled choices.
 */
export function assignSubmission(state, users = [], projects = null) {
  if (!state.userId || !state.projectId) return { error: ASSIGN_REQUIRED };
  const user = users.find((row) => String(row.id) === String(state.userId));
  if (!user) return { error: 'This user cannot be assigned. Reload the page and try again.' };
  if (projects) {
    const choices = projectChoices(projects, user);
    if (choices.length === 0) return { error: NO_PROJECTS_LEFT };
    const choice = choices.find((project) => String(project.id) === String(state.projectId));
    if (!choice) return { error: ASSIGN_REQUIRED };
    if (choice.disabled) return { error: choice.reason };
  }
  return { payload: { user_id: Number(state.userId), project_id: Number(state.projectId) } };
}

/** "as Research Assistant": the read-only role shown for the chosen user. */
export function assignRoleText(state, users = []) {
  const user = users.find((row) => String(row.id) === String(state.userId));
  return user?.role_label ? `as ${user.role_label}` : '';
}
