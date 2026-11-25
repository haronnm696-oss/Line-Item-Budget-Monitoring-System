// Every account role, in org-chart order (filters and labels).
export const ALL_ACCOUNT_ROLE_OPTIONS = [
  { slug: 'program-leader', label: 'Program Leader' },
  { slug: 'project-leader', label: 'Project Leader' },
  { slug: 'research-assistant', label: 'Research Assistant' },
  { slug: 'finance-admin', label: 'Finance/Admin Staff' },
  { slug: 'researcher', label: 'Researcher' },
];

// The system has one Program Leader: that role is never offered in
// registration approval, Add user or Change account role. It moves only by
// "Transfer Program Leader role".
export function assignableRoleOptions(options) {
  return (options ?? []).filter((option) => option?.slug !== 'program-leader');
}

// Account roles a Program Leader can grant, in org-chart order.
export const ACCOUNT_ROLE_OPTIONS = assignableRoleOptions(ALL_ACCOUNT_ROLE_OPTIONS);

// Roles a person can hold on a project (one per person per project).
export const PROJECT_ROLE_OPTIONS = [
  { slug: 'project-leader', label: 'Project Leader' },
  { slug: 'research-assistant', label: 'Research Assistant' },
  { slug: 'finance-admin', label: 'Finance/Admin Staff' },
  { slug: 'researcher', label: 'Researcher' },
];

// Frontend role codes (labels and dashboards), mirrors AuthController::SLUG_TO_CODE.
export const ROLE_MAP = {
  PRG: { dash: 'program', slug: 'program-leader', label: 'Program Leader' },
  PL: { dash: 'project-leader', slug: 'project-leader', label: 'Project Leader' },
  RES: { dash: 'researcher', slug: 'researcher', label: 'Researcher' },
  RA: { dash: 'research-assistant', slug: 'research-assistant', label: 'Research Assistant' },
  FIN: { dash: 'finance', slug: 'finance-admin', label: 'Finance/Admin Staff' },
};

// Backend slug -> frontend code.
export const SLUG_TO_CODE = {
  'program-leader': 'PRG',
  'project-leader': 'PL',
  researcher: 'RES',
  'research-assistant': 'RA',
  'finance-admin': 'FIN',
};

export function normalizeRole(user) {
  if (!user) return null;
  if (user.role && ROLE_MAP[user.role]) return user.role;
  if (user.role_slug && SLUG_TO_CODE[user.role_slug]) return SLUG_TO_CODE[user.role_slug];
  return null;
}
