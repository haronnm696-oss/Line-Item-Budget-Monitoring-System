// Sidebar navigation per role for the shared role shell. Labels are exact.
// `badge` names the count a later change will fill in (empty for now).
// Items whose page does not exist yet route to the "Coming soon" page.

export const PROGRAM_NAV = [
  {
    items: [
      { to: '/program-dashboard', label: 'Overview', icon: 'dashboard' },
      { to: '/program-projects', label: 'Projects', icon: 'projects' },
    ],
  },
  {
    label: 'Review',
    items: [
      { to: '/program-approvals', label: 'Approvals', icon: 'approvals', badge: 'approvals' },
      { to: '/program-reports', label: 'Reports', icon: 'reports' },
      { to: '/program-audit', label: 'Audit trail', icon: 'audit' },
    ],
  },
  {
    label: 'Administration',
    items: [{ to: '/program-users', label: 'Users and roles', icon: 'users' }],
  },
];

export const ROLE_NAV = {
  PRG: PROGRAM_NAV,
  PL: [
    { items: [{ to: '/dashboard/project-leader', label: 'Overview', icon: 'dashboard' }] },
    {
      label: 'Project',
      items: [
        { to: '/budget', label: 'Line-item budget', icon: 'budget' },
        { to: '/transactions', label: 'Transactions', icon: 'transactions' },
      ],
    },
    {
      label: 'Review',
      items: [
        { to: '/submissions', label: 'Submissions', icon: 'approvals', badge: 'submissions' },
        { to: '/my-requests', label: 'My requests', icon: 'requests', badge: 'requests' },
      ],
    },
    { label: 'Output', items: [{ to: '/reports', label: 'Reports', icon: 'reports' }] },
  ],
  RA: [
    { items: [{ to: '/dashboard/research-assistant', label: 'Overview', icon: 'dashboard' }] },
    {
      label: 'Encode',
      items: [
        { to: '/expenses', label: 'Expenses and receipts', icon: 'receipt' },
        { to: '/purchase-requests', label: 'Purchase requests', icon: 'cart' },
        { to: '/payroll', label: 'Payroll', icon: 'payroll' },
        { to: '/documents', label: 'Documents', icon: 'documents' },
      ],
    },
    {
      label: 'Track',
      items: [
        { to: '/my-submissions', label: 'My submissions', icon: 'submissions', badge: 'my-submissions' },
        { to: '/balancing-query', label: 'Balancing query', icon: 'balance' },
      ],
    },
  ],
  FIN: [
    { items: [{ to: '/dashboard/finance', label: 'Overview', icon: 'dashboard' }] },
    {
      label: 'Verify',
      items: [
        { to: '/verification-queue', label: 'Verification queue', icon: 'approvals', badge: 'verification' },
        { to: '/expenses', label: 'Expenses and receipts', icon: 'receipt' },
        { to: '/payroll', label: 'Payroll', icon: 'payroll' },
      ],
    },
    {
      label: 'Records',
      items: [
        { to: '/transaction-history', label: 'Transaction history', icon: 'audit' },
        { to: '/reports', label: 'Reports', icon: 'reports' },
      ],
    },
  ],
  RES: [
    { items: [{ to: '/dashboard/researcher', label: 'Overview', icon: 'dashboard' }] },
    {
      label: 'View',
      items: [
        { to: '/budget', label: 'Budget', icon: 'budget' },
        { to: '/transactions', label: 'Transactions', icon: 'transactions' },
        { to: '/reports', label: 'Reports', icon: 'reports' },
      ],
    },
  ],
};

/** Paths that have no page yet; they open the "Coming soon" page. */
export const COMING_SOON_PATHS = [];

/** Where the bell leads for each role (its review/inbox page). */
export const BELL_TARGET = {
  PRG: '/program-approvals',
  PL: '/submissions',
  RA: '/my-submissions',
  FIN: '/verification-queue',
  RES: '/dashboard/researcher',
};

/** The nav item for a path in a role's menu (longest matching prefix). */
export function navItemFor(roleCode, pathname) {
  const items = (ROLE_NAV[roleCode] ?? []).flatMap((group) => group.items);
  return items
    .filter((item) => pathname === item.to || pathname.startsWith(`${item.to}/`))
    .sort((a, b) => b.to.length - a.to.length)[0] ?? null;
}
