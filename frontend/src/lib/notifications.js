import { canOpen, homeFor } from '../routes/access.js';
import { BELL_TARGET } from '../layouts/roleNav.js';

/**
 * Where clicking a notification takes this user. The notification's own link
 * when their role may open that page; otherwise the role's review page (the
 * bell target), keeping the ?project= so the right project is picked.
 */
export function notificationTarget(role, link) {
  const fallback = BELL_TARGET[role] ?? homeFor(role);
  if (typeof link !== 'string' || !link.startsWith('/')) return fallback;
  if (canOpen(role, link)) return link;

  const query = link.includes('?') ? link.slice(link.indexOf('?') + 1) : '';
  const project = new URLSearchParams(query).get('project');
  return project && canOpen(role, fallback) ? `${fallback}?project=${encodeURIComponent(project)}` : fallback;
}

/** Badge text: the count, capped so the badge stays small. */
export function unreadBadge(count) {
  const n = Number(count) || 0;
  if (n <= 0) return '';
  return n > 99 ? '99+' : String(n);
}
