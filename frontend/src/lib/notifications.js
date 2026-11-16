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
 * The Research Assistant's page and panel for a notification: each record
 * type opens on its own page with its side panel (?open=type-id); payroll
 * also keeps the month from the link so the right batch shows.
 */
function assistantTarget(link, record, projectId) {
  const project = projectOf(link, projectId);
  if (!record || !project) return null;
  const params = new URLSearchParams({ project: String(project) });
  if (record.type === 'expense') {
    params.set('open', `expense-${record.id}`);
    return `/expenses?${params}`;
  }
  if (record.type === 'purchase_request') {
    params.set('open', `purchase_request-${record.id}`);
    return `/purchase-requests?${params}`;
  }
  if (record.type === 'payroll') {
    const month = typeof link === 'string' && link.includes('?')
      ? new URLSearchParams(link.slice(link.indexOf('?') + 1)).get('month')
      : null;
    if (month && /^\d{4}-\d{2}$/.test(month)) params.set('month', month);
    params.set('open', `payroll-${record.id}`);
    return `/payroll?${params}`;
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
  if (role === 'RA') {
    const target = assistantTarget(link, record, projectId);
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
