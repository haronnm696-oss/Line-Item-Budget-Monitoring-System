// "My projects" resolver for the role pages. The ONE rule (same as the
// backend's User::rolesOn / hasRoleOn): a person works on a project as role R
// when the Program Leader assigned them there with role R (a project_user
// row). Every page, picker and badge of a role uses myProjects(), so they can
// never disagree about which projects are "mine".

import { ROLE_MAP } from './roles.js';

/** Project role slug for a frontend role code; null for the Program Leader (all projects). */
export function projectRoleFor(code) {
  if (!code || code === 'PRG') return null;
  return ROLE_MAP[code]?.slug ?? null;
}

/** True when the API row says the user holds `role` on that project. */
export function holdsRole(project, role) {
  if (!role) return true;
  if (Array.isArray(project?.my_roles)) return project.my_roles.includes(role);
  return project?.my_role === role;
}

/** The projects of the list where the user holds `role` (all of them when role is null). */
export function myProjects(list, role) {
  const all = Array.isArray(list) ? list : [];
  return role ? all.filter((project) => holdsRole(project, role)) : all;
}

const ROLE_WORDS = {
  'project-leader': 'leader',
  'research-assistant': 'Research Assistant',
  'finance-admin': 'Finance/Admin staff',
  researcher: 'Researcher',
};

/** Shown instead of the create actions when the user has no project in that role. */
export function notAssignedText(role) {
  const word = ROLE_WORDS[role] ?? 'a member';
  return `You are not assigned as ${word} of a project yet. Ask the Program Leader to assign you.`;
}
