// Page titles shared by several roles. Kept free of JSX for node:test.

/** Researchers only read: their pages carry the "Read-only access" badge. */
export function isReadOnlyRole(roleCode) {
  return roleCode === 'RES';
}

/** Finance/Admin calls the page "Transaction history" (its sidebar label); others keep "Transactions". */
export function transactionsTitle(roleCode) {
  return roleCode === 'FIN' ? 'Transaction history' : 'Transactions';
}
