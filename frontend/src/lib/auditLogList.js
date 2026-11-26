// Rules for the compact Audit log list (components/AuditLogList.jsx), kept
// free of JSX so node:test can check them.

/** Debounce for the search box before it asks the server (ms). */
export const SEARCH_DEBOUNCE_MS = 300;

/** Only one entry is open at a time: opening another closes the first; the open one closes. */
export function nextExpanded(current, id) {
  return current === id ? null : id;
}

/**
 * A click on the row toggles it, except a click on a link or button inside
 * it (the record link, the project code), which does its own thing.
 */
export function rowClickToggles(target) {
  return !target?.closest?.('a, button, input, select, textarea');
}

/** "Name · short type" on one line, skipping empty parts. */
export function recordLine(label, type) {
  return [label, type].map((part) => String(part ?? '').trim()).filter(Boolean).join(' · ');
}

/** The search text sent as `q`: trimmed, or nothing. */
export function searchParam(text) {
  const value = String(text ?? '').trim();
  return value === '' ? undefined : value;
}
