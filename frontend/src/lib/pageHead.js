// Page titles shared by several roles. Kept free of JSX for node:test.

/** Every Researcher page (Overview, Budget, Transactions, Reports). */
export const RESEARCHER_EYEBROW = 'View · Researcher';

/** Researchers only read: their pages carry the "Read-only access" badge. */
export function isReadOnlyRole(roleCode) {
  return roleCode === 'RES';
}

/** The shared Transactions page: the Researcher's eyebrow, otherwise "Project". */
export function transactionsEyebrow(roleCode) {
  return roleCode === 'RES' ? RESEARCHER_EYEBROW : 'Project';
}
