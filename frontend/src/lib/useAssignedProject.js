import { useSearchParams } from 'react-router-dom';
import { ENDPOINTS } from '../api/endpoints';
import { useApiData } from './useApiData';

/**
 * The project a page works on: one of the user's assigned projects (the API
 * returns only those), chosen with ?project= or the first one by default.
 * Replaces the old hard-coded sample project id.
 */
export function useAssignedProject() {
  const [searchParams, setSearchParams] = useSearchParams();
  const state = useApiData(ENDPOINTS.projects);
  const projects = Array.isArray(state.data) ? state.data : [];
  const requested = searchParams.get('project');
  const project = projects.find((item) => String(item.id) === requested) ?? projects[0] ?? null;

  function choose(id) {
    const next = new URLSearchParams(window.location.search);
    next.set('project', String(id));
    setSearchParams(next, { replace: true });
  }

  return { state, projects, project, projectId: project?.id ?? null, choose };
}
