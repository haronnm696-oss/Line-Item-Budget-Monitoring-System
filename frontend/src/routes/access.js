// Which role may open which route. App.jsx wraps every signed-in route with
// RoleRoute using this table; a user who opens another role's route is sent
// to their own dashboard. Kept free of JSX so it can be unit-tested.
//
// The backend still enforces its own rules (role middleware and project
// scoping); this table only decides which pages a role can open.

/** Where each role lands after sign-in. */
export const ROUTE_BY_ROLE = {
  PRG: '/program-dashboard',
  PL: '/dashboard/project-leader',
  RA: '/dashboard/research-assistant',
  RES: '/dashboard/researcher',
  FIN: '/dashboard/finance',
};

const ALL = ['PRG', 'PL', 'RA', 'FIN', 'RES'];

/** Route pattern -> roles that may open it. */
export const ROUTE_ROLES = {
  // Redirects to the user's own dashboard.
  '/dashboard': ALL,

  // Program Leader shell.
  '/program-dashboard': ['PRG'],
  '/program-projects': ['PRG'],
  '/program-projects/:id': ['PRG'],
  '/program-approvals': ['PRG'],
  '/program-reports': ['PRG'],
  '/program-reports/executive': ['PRG'],
  '/program-audit': ['PRG'],
  '/program-users': ['PRG'],
  '/program-leader-dashboard': ['PRG'],
  '/dashboard/program-leader': ['PRG'],
  '/users': ['PRG'],
  '/approvals': ['PRG'],

  // Role dashboards (and their older URLs).
  '/dashboard/project-leader': ['PL'],
  '/project-leader-dashboard': ['PL'],
  '/dashboard/research-assistant': ['RA'],
  '/research-assistant-dashboard': ['RA'],
  '/dashboard/researcher': ['RES'],
  '/researcher-dashboard': ['RES'],
  '/dashboard/finance': ['FIN'],

  // Shared pages, by sidebar menu.
  '/budget': ['PL', 'RES'],
  '/transactions': ['PL', 'FIN', 'RES'],
  '/submissions': ['PL'],
  '/my-requests': ['PL'],
  '/reports': ['PL', 'FIN', 'RES'],
  '/expenses': ['RA', 'FIN'],
  '/payroll': ['RA', 'FIN'],
  '/purchase-requests': ['RA'],
  '/documents': ['RA'],
  '/my-submissions': ['RA'],
  '/balancing-query': ['RA'],
  '/balancing': ['RA'],
  '/verification-queue': ['FIN'],
  '/verification': ['FIN'],
  '/transaction-history': ['FIN'],

  // Earlier pages kept on their routes (not in a menu).
  '/projects': ['PL', 'FIN', 'RES'],
  '/projects/:id': ['PL', 'FIN', 'RES'],
  '/audit': ['PL', 'FIN'],
};

function matches(pattern, path) {
  const a = pattern.split('/').filter(Boolean);
  const b = path.split('?')[0].split('/').filter(Boolean);
  return a.length === b.length && a.every((part, index) => part.startsWith(':') || part === b[index]);
}

/** Roles allowed on a concrete path, or [] when the path is unknown. */
export function rolesFor(path) {
  const pattern = Object.keys(ROUTE_ROLES).find((key) => matches(key, path));
  return pattern ? ROUTE_ROLES[pattern] : [];
}

export function canOpen(role, path) {
  return rolesFor(path).includes(role);
}

/** Where a user who may not open a route is sent instead. */
export function homeFor(role) {
  return ROUTE_BY_ROLE[role] ?? '/dashboard';
}
