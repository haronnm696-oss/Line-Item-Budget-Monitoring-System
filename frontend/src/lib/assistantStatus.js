// Status wording for the Research Assistant pages. Status is always text
// plus an icon (never colour alone). Kept free of JSX so it can be tested.

/** workflow_status -> [label, tone, icon] for lists (Overview, My submissions). */
const STAGE = {
  draft: ['Draft', 'neutral', '•'],
  submitted: ['Submitted', 'watch', '◷'],
  endorsed: ['Approved', 'healthy', '✓'],
  returned: ['Returned', 'risk', '✕'],
  verified: ['Verified', 'healthy', '✓'],
  fulfilled: ['Fulfilled', 'healthy', '✓'],
  cancelled: ['Cancelled', 'neutral', '⊘'],
};

/** Purchase request page: the request's own stage names. */
const PURCHASE_REQUEST_STAGE = {
  ...STAGE,
  submitted: ['Waiting for Project Leader', 'watch', '◷'],
};

export function stagePill(status, type = null) {
  const table = type === 'purchase_request' ? PURCHASE_REQUEST_STAGE : STAGE;
  const [label, tone, icon] = table[status] ?? [String(status ?? '—'), 'neutral', '•'];
  return { label, tone, icon };
}

/** Tabs of My submissions, in order. */
export const SUBMISSION_TABS = [
  ['drafts', 'Drafts'],
  ['waiting', 'Waiting'],
  ['returned', 'Returned'],
  ['approved', 'Approved'],
  // Finished records: verified expenses and payroll, fulfilled or cancelled
  // purchase requests. Each row keeps its own status pill.
  ['completed', 'Completed'],
];

export const SUBMISSION_TYPES = [
  ['', 'All types'],
  ['expense', 'Expense'],
  ['purchase_request', 'Purchase request'],
  ['payroll', 'Payroll'],
];

/** "Dr. Aminah Macarambon: “reason”" for a returned record, else null. */
export function returnedLine(item) {
  if (!item || item.workflow_status !== 'returned') return null;
  const who = item.returned_by?.name ?? 'Reviewer';
  return item.return_reason ? `${who}: “${item.return_reason}”` : `Returned by ${who}`;
}

/**
 * "Edit and resubmit" on a list row: only a returned record the server says
 * this user may edit (can_edit / can_resubmit are true for the maker only).
 */
export function canResubmit(item) {
  return Boolean(item && item.workflow_status === 'returned' && (item.can_edit === true || item.can_resubmit === true));
}

/** ?open=expense-12 -> { type: 'expense', id: 12 } (null when not a record key). */
export function parseOpen(value) {
  const match = /^(expense|payroll|purchase_request)-(\d+)$/.exec(value ?? '');
  return match ? { type: match[1], id: Number(match[2]) } : null;
}

/** The page that owns a record type (its panel opens with ?open=). */
export const RECORD_PAGE = {
  expense: '/expenses',
  purchase_request: '/purchase-requests',
  payroll: '/payroll',
};
