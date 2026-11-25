/** "waiting 3 days" since an ISO timestamp (whole days, never negative). */
export function waitedText(since, now = Date.now()) {
  if (!since) return '';
  const start = new Date(since).getTime();
  if (Number.isNaN(start)) return '';
  const days = Math.max(0, Math.floor((now - start) / 86400000));
  if (days === 0) return 'waiting since today';
  return `waiting ${days} day${days === 1 ? '' : 's'}`;
}
