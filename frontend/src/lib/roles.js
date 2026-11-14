// Account roles a Program Leader can grant, in org-chart order.
export const ACCOUNT_ROLE_OPTIONS = [
  { slug: 'program-leader', label: 'Program Leader' },
  { slug: 'project-leader', label: 'Project Leader' },
  { slug: 'research-assistant', label: 'Research Assistant' },
  { slug: 'finance-admin', label: 'Finance/Admin Staff' },
  { slug: 'researcher', label: 'Researcher' },
];

// Roles a person can hold on a project (one per person per project).
export const PROJECT_ROLE_OPTIONS = [
  { slug: 'project-leader', label: 'Project Leader' },
  { slug: 'research-assistant', label: 'Research Assistant' },
  { slug: 'finance-admin', label: 'Finance/Admin Staff' },
  { slug: 'researcher', label: 'Researcher' },
];
