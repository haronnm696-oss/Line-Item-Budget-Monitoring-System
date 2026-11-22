// Page titles shared by several roles. Kept free of JSX for node:test.

/** Every Researcher page (Overview, Budget, Transactions, Reports). */
export const RESEARCHER_EYEBROW = 'View · Researcher';

/** Researchers only read: their pages carry the "Read-only access" badge. */
export function isReadOnlyRole(roleCode) {
  return roleCode === 'RES';
}

/** The shared Transactions page: Finance/Admin's and the Researcher's eyebrows, otherwise "Project". */
export function transactionsEyebrow(roleCode) {
  if (roleCode === 'FIN') return 'Records · Finance/Admin';
  return roleCode === 'RES' ? RESEARCHER_EYEBROW : 'Project';
}

/** Finance/Admin calls the page "Transaction history" (its sidebar label); others keep "Transactions". */
export function transactionsTitle(roleCode) {
  return roleCode === 'FIN' ? 'Transaction history' : 'Transactions';
}
