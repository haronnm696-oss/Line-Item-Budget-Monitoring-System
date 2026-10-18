export default function Chip({ tone = 'neutral', children }) {
  return <span className={`chip ${tone}`}>{children}</span>;
}

// Maps a status string from the API to a chip tone so pages don't repeat
// this switch. Extend as new statuses are added on the backend.
export function statusTone(status) {
  const map = {
    Verified: 'ok', Approved: 'ok', Released: 'ok', Active: 'ok', Reviewed: 'ok',
    Pending: 'warn', Draft: 'warn', 'Pending review': 'warn',
    Rejected: 'over',
    Inactive: 'neutral', 'Not started': 'neutral',
    Ongoing: 'ok', Completed: 'neutral', Planning: 'brand',
  };
  return map[status] || 'neutral';
}
