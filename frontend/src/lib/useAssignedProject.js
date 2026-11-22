import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../api/endpoints';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from './roles';
import { pickProject, projectRoleFor } from './myProjects';
import { readProjectChoice, requestedProject } from './projectChoice';
import { useApiData } from './useApiData';

/**
 * The project a page works on: one of the user's projects for their role
 * (see lib/myProjects.js, the one "my projects" rule), chosen with
 * ?project=, else the one remembered from the top-bar picker
 * (lib/projectChoice.js), else the first one.
 *
 * `role` (a project role slug) defaults to the signed-in user's role, so
 * every page of a role (Overview, Line-item budget, Transactions, Reports…)
 * lists the same projects. The Program Leader gets every project. Pages
 * have no picker of their own: the top bar has the one picker.
 */
export function useAssignedProject({ role } = {}) {
  const { user } = useAuth();
  const [searchParams] = useSearchParams();
  const state = useApiData(ENDPOINTS.projects);
  const effectiveRole = role === undefined ? projectRoleFor(normalizeRole(user)) : role;
  // The Project Leader leads one project (lib/leaderRule.js): no remembered choice.
  const remembered = effectiveRole === 'project-leader' ? null : readProjectChoice(user?.id, effectiveRole);
  const requested = requestedProject(searchParams.get('project'), remembered);
  const { projects, project, others, past } = pickProject(state.data, requested, effectiveRole);

  // others / past: the Project Leader's other active projects (older data) and completed ones.
  return { state, projects, project, projectId: project?.id ?? null, role: effectiveRole, others, past };
}
