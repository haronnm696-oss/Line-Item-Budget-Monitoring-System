import { canOpen, homeFor } from '../routes/access.js';
import { BELL_TARGET } from '../layouts/roleNav.js';

function projectOf(link, projectId) {
  if (projectId) return String(projectId);
  if (typeof link !== 'string' || !link.includes('?')) return null;
  return new URLSearchParams(link.slice(link.indexOf('?') + 1)).get('project');
}

/**
 * The Project Leader's page and record for a notification: queue records
 * open in the Submissions side panel; budget request decisions highlight
 * the request on My requests. Null when the notification names no record.
 */
function leaderTarget(link, record, projectId) {
  const project = projectOf(link, projectId);
  if (!record || !project) return null;
  const p = `project=${encodeURIComponent(project)}`;
  if (['expense', 'payroll', 'purchase_request'].includes(record.type)) {
    return `/submissions?${p}&open=${record.type}-${record.id}`;
  }
  if (record.type === 'approval' && typeof link === 'string' && link.startsWith('/my-requests')) {
    return `/my-requests?${p}&request=${record.id}`;
  }
  return null;
}

/**
 * Where clicking a notification takes this user. For a Project Leader, the
 * record itself when the notification names one (see leaderTarget()).
 * Otherwise the notification's own link when their role may open that page,
 * or the role's review page (the bell target), keeping the ?project= so the
 * right project is picked.
 *
 * `record` ({ type, id }) and `projectId` come from the notification API.
 */
export function notificationTarget(role, link, record = null, projectId = null) {
  if (role === 'PL') {
    const target = leaderTarget(link, record, projectId);
    if (target) return target;
  }

  const fallback = BELL_TARGET[role] ?? homeFor(role);
  if (typeof link !== 'string' || !link.startsWith('/')) return fallback;
  if (canOpen(role, link)) return link;

  const project = projectOf(link, null);
  return project && canOpen(role, fallback) ? `${fallback}?project=${encodeURIComponent(project)}` : fallback;
}

/** Badge text: the count, capped so the badge stays small. */
export function unreadBadge(count) {
  const n = Number(count) || 0;
  if (n <= 0) return '';
  return n > 99 ? '99+' : String(n);
}
