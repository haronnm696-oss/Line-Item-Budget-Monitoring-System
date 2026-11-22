// The project a user is working on, shared by every page of their role.
// The "Switch project" button at the end of each page's project line
// (components/ProjectLine.jsx) is the one place to change it; the choice is
// kept in the ?project= link and remembered per user and role, so it
// carries across pages. Storage can be blocked
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

/** Roles that can switch projects. The Project Leader leads one project and cannot. */
export function canSwitchProject(code) {
  return code === 'RA' || code === 'FIN' || code === 'RES';
}

/** Show "Switch project" only when the user has more than one project. */
export function showProjectSwitch(code, projects) {
  return canSwitchProject(code) && Array.isArray(projects) && projects.length > 1;
}

/**
 * Choosing a project: remembers it for this user and role, and returns the
 * new search string (?project= set, ?open= dropped) for the current page.
 */
export function chooseProject({ userId, role, projectId, search, store }) {
  writeProjectChoice(userId, role, projectId, store);
  return searchWithProject(search, projectId);
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
 * The search string after choosing a project: sets ?project= and drops what
 * belongs to the old project (?open= record and the older ?expense= /
 * ?request= links, ?line_item=, ?page=); other filters (tab, type, status,
 * dates, month) stay.
 */
const OLD_PROJECT_PARAMS = ['open', 'expense', 'request', 'line_item', 'page'];

export function searchWithProject(search, projectId) {
  const params = new URLSearchParams(search);
  params.set('project', String(projectId));
  OLD_PROJECT_PARAMS.forEach((key) => params.delete(key));
  return `?${params.toString()}`;
}
