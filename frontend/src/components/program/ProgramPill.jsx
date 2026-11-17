const PILL = {
  Healthy: ['healthy', '✓'],
  Watch: ['watch', '⚠'],
  'At risk': ['risk', '✕'],
  Closed: ['neutral', '✓'],
  Completed: ['neutral', '✓'],
  Ongoing: ['healthy', '✓'],
  Active: ['healthy', '✓'],
  Inactive: ['neutral', '⊘'],
  Overdue: ['risk', '✕'],
  'On track': ['healthy', '✓'],
  'Final pending': ['watch', '⚠'],
  Approved: ['healthy', '✓'],
  Rejected: ['risk', '✕'],
  Pending: ['watch', '⚠'],
  'Fund release': ['amber', '₱'],
  Realignment: ['amber', '⇄'],
  Liquidation: ['neutral', '▤'],
  'Budget change': ['amber', '₱'],
  Planning: ['neutral', '•'],
  Terminated: ['risk', '✕'],
  Payroll: ['amber', '₱'],
  // Project Leader pages: record types and workflow stages.
  Expense: ['neutral', '▤'],
  'Purchase request': ['neutral', '◇'],
  Draft: ['neutral', '•'],
  Submitted: ['watch', '⚠'],
  Returned: ['risk', '↩'],
  Withdrawn: ['neutral', '⊘'],
  'Approved by Project Leader': ['healthy', '✓'],
  Verified: ['healthy', '✓'],
  Fulfilled: ['healthy', '✓'],
  Cancelled: ['neutral', '⊘'],
  'Not yet requested': ['neutral', '•'],
  'Over budget': ['risk', '!'],
};

export function pillTone(value) {
  return PILL[value]?.[0] ?? 'neutral';
}

export default function ProgramPill({ value, label, tone, icon, className = '' }) {
  const preset = PILL[value] ?? ['neutral', ''];
  const resolvedTone = tone ?? preset[0];
  const resolvedIcon = icon ?? preset[1];
  const text = label ?? value;

  return (
    <span className={`program-pill ${resolvedTone} ${className}`.trim()}>
      {text}
      {resolvedIcon && <span aria-hidden="true">{resolvedIcon}</span>}
    </span>
  );
}
