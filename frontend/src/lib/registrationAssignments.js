import { leaderTakenReason } from './assignForm.js';

// Project rows of the pending-registration approval drawer. A Project Leader
// leads one project and a project has one Project Leader; the server enforces
// both (ProjectLeaderRule, ProgramUserService::createAssignmentRow) and these
// helpers make the drawer follow the same rule.

/** A Project Leader account is approved with at most one project. */
export function singleProjectRole(role) {
  return role === 'project-leader';
}

/** "+ Add project" is offered for every role except Project Leader (one slot). */
export function canAddProjectRow(role) {
  return !singleProjectRole(role);
}

export const ONE_PROJECT_HELP = 'A Project Leader leads one project. Choose it now or later from Manage.';
export const ONE_PROJECT_TRIMMED = 'A Project Leader leads one project, so only the first project was kept.';

/**
 * The rows after the role changes to `role`. For a Project Leader: exactly
 * one slot (the first row, or a new empty one from `makeRow`); `trimmed` is
 * true when chosen projects were dropped. Leaving Project Leader drops the
 * empty slot. Other roles keep their rows unchanged.
 */
export function rowsForRole(rows, role, makeRow, previousRole = '') {
  const list = Array.isArray(rows) ? rows : [];
  if (singleProjectRole(role)) {
    const trimmed = list.slice(1).some((row) => row.project_id);
    return { rows: list.length ? [list[0]] : [makeRow()], trimmed };
  }
  if (singleProjectRole(previousRole)) {
    return { rows: list.filter((row) => row.project_id), trimmed: false };
  }
  return { rows: list, trimmed: false };
}

/**
 * The project options for `role`. For a Project Leader, a project that
 * already has a Project Leader stays listed but disabled, with `reason`.
 *
 * projects: [{ id, title, code, status, leader: { id, name } | null }]
 */
export function registrationProjectChoices(projects, role) {
  const leader = singleProjectRole(role);
  return (Array.isArray(projects) ? projects : []).map((project) => {
    const taken = leader && Boolean(project.leader);
    return { ...project, disabled: taken, reason: taken ? leaderTakenReason(project) : '' };
  });
}
