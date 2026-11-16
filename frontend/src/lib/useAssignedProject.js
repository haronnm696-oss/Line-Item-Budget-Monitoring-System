import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../api/endpoints';
import { useApiData } from './useApiData';

/**
 * The project a page works on: one of the user's assigned projects (the API
 * returns only those), chosen with ?project= or the first one by default.
 * Replaces the old hard-coded sample project id.
 *
 * `role` (a project role slug, e.g. 'project-leader') keeps only the
 * projects where the user holds that role (the API's my_role).
 */
export function useAssignedProject({ role } = {}) {
  const [searchParams, setSearchParams] = useSearchParams();
  const state = useApiData(ENDPOINTS.projects);
  const { projects, project } = pickProject(state.data, searchParams.get('project'), role);

  function choose(id) {
    const next = new URLSearchParams(window.location.search);
    next.set('project', String(id));
    setSearchParams(next, { replace: true });
  }

  return { state, projects, project, projectId: project?.id ?? null, choose };
}

/**
 * The same choice without the hook (used by the sidebar badges so they
 * follow the page's project): the ?project= one, else the first.
 */
export function pickProject(data, requested, role) {
  const all = Array.isArray(data) ? data : [];
  const projects = role ? all.filter((item) => item.my_role === role) : all;
  const project = projects.find((item) => String(item.id) === String(requested)) ?? projects[0] ?? null;
  return { projects, project };
}
