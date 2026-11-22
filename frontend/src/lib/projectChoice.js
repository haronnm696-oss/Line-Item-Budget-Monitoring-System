// The project a user is working on, shared by every page of their role.
// The top-bar picker (layouts/TopBarProjectPicker.jsx) is the one place to
// change it; the choice is kept in the ?project= link and remembered per
// user and role, so it carries across pages. Storage can be blocked
// (private mode, site settings): reads then return null and writes are
// ignored, and the ?project= link still works.

const PREFIX = 'lib.project';

/** Storage key for one user in one project role. */
export function choiceKey(userId, role) {
  return `${PREFIX}.${userId ?? 'anon'}.${role ?? 'all'}`;
}

function storage(store) {
  if (store) return store;
  try {
    return typeof window === 'undefined' ? null : window.localStorage;
  } catch {
    return null;
  }
}

/** The remembered project id (string) or null. */
export function readProjectChoice(userId, role, store) {
  if (userId === undefined || userId === null) return null;
  try {
    const value = storage(store)?.getItem(choiceKey(userId, role));
    return value ? String(value) : null;
  } catch {
    return null;
  }
}

/** Remembers the project id; failures are ignored (the link still carries it). */
export function writeProjectChoice(userId, role, projectId, store) {
  if (userId === undefined || userId === null || projectId === undefined || projectId === null || projectId === '') return;
  try {
    storage(store)?.setItem(choiceKey(userId, role), String(projectId));
  } catch {
    // Ignore: the ?project= link keeps the choice for this visit.
  }
}

/** The project to show: the ?project= one, else the remembered one, else null (the first). */
export function requestedProject(fromUrl, remembered) {
  return fromUrl || remembered || null;
}

/** Roles whose pages share the top-bar picker. The Project Leader leads one project and has none. */
export function hasTopBarPicker(code) {
  return code === 'RA' || code === 'FIN' || code === 'RES';
}

/** Show the picker only when the user has more than one project. */
export function showProjectPicker(code, projects) {
  return hasTopBarPicker(code) && Array.isArray(projects) && projects.length > 1;
}

/** A link that keeps the project: "/expenses" -> "/expenses?project=3". */
export function withProject(to, projectId) {
  if (!projectId) return to;
  const [path, query = ''] = String(to).split('?');
  const params = new URLSearchParams(query);
  params.set('project', String(projectId));
  return `${path}?${params.toString()}`;
}

/**
 * The search string after choosing a project: sets ?project= and drops
 * ?open= (an open record belongs to the old project); other filters stay.
 */
export function searchWithProject(search, projectId) {
  const params = new URLSearchParams(search);
  params.set('project', String(projectId));
  params.delete('open');
  return `?${params.toString()}`;
}
