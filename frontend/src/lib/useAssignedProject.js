import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../api/endpoints';
import { useAuth } from '../context/AuthContext';
import { normalizeRole } from './roles';
import { myProjects, projectRoleFor } from './myProjects';
import { useApiData } from './useApiData';

/**
 * The project a page works on: one of the user's projects for their role
 * (see lib/myProjects.js, the one "my projects" rule), chosen with
 * ?project= or the first one by default.
 *
 * `role` (a project role slug) defaults to the signed-in user's role, so
 * every page of a role (Overview, Line-item budget, Transactions, Reports…)
 * lists the same projects. The Program Leader gets every project.
 */
export function useAssignedProject({ role } = {}) {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const state = useApiData(ENDPOINTS.projects);
  const effectiveRole = role === undefined ? projectRoleFor(normalizeRole(user)) : role;
  const { projects, project } = pickProject(state.data, searchParams.get('project'), effectiveRole);

  function choose(id) {
    const next = new URLSearchParams(window.location.search);
    next.set('project', String(id));
    setSearchParams(next, { replace: true });
  }

  return { state, projects, project, projectId: project?.id ?? null, role: effectiveRole, choose };
}

/**
 * The same choice without the hook (used by the sidebar badges so they
 * follow the page's project): the ?project= one, else the first.
 */
export function pickProject(data, requested, role) {
  const projects = myProjects(data, role);
  const project = projects.find((item) => String(item.id) === String(requested)) ?? projects[0] ?? null;
  return { projects, project };
}
