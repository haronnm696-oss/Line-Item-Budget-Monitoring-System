/**
 * Helpers for the "Record expense" form: line item grouping, the default
 * line item, and filling the form from a linked purchase request.
 */

export const LINE_ITEM_GROUPS = ['PS', 'MOOE'];

export const PS_NOTE = 'Salaries and honoraria are recorded through Payroll.';

/** Available balance of a line item: allocated - spent - pending. */
export function lineItemAvailable(item) {
  if (item?.available != null) return Number(item.available) || 0;
  const allocated = Number(item?.allocated) || 0;
  const spent = Number(item?.spent) || 0;
  const pending = Number(item?.pending) || 0;
  return Math.round((allocated - spent - pending) * 100) / 100;
}

/**
 * Line items grouped as [{ type: 'PS', items }, { type: 'MOOE', items }],
 * keeping their order and leaving out empty groups. Items of another type
 * (not expected) go in a trailing group of their own.
 */
export function groupLineItems(items) {
  const list = Array.isArray(items) ? items : [];
  const types = [...LINE_ITEM_GROUPS, ...new Set(list.map((item) => item.type).filter((type) => !LINE_ITEM_GROUPS.includes(type)))];
  return types
    .map((type) => ({ type, items: list.filter((item) => item.type === type) }))
    .filter((group) => group.items.length > 0);
}

/** The id (string) to select automatically when there is only one line item, else ''. */
export function singleLineItemId(items) {
  return Array.isArray(items) && items.length === 1 ? String(items[0].id) : '';
}

function storageKey(userId, projectId) {
  return `lib:last-line-item:${userId ?? 'anon'}:${projectId}`;
}

/** The last line item chosen for the project in this browser, if it still exists. Never throws. */
export function rememberedLineItemId(storage, userId, projectId, items) {
  try {
    const id = storage?.getItem(storageKey(userId, projectId));
    if (!id) return '';
    return (items ?? []).some((item) => String(item.id) === id) ? id : '';
  } catch {
    return '';
  }
}

/** Remember the chosen line item for the project. Never throws. */
export function rememberLineItemId(storage, userId, projectId, id) {
  try {
    if (id) storage?.setItem(storageKey(userId, projectId), String(id));
  } catch {
    // Storage blocked or full: remembering is only a convenience.
  }
}

/** The line item to start a new expense with: the only one, else the remembered one. */
export function defaultLineItemId(items, remembered = '') {
  return singleLineItemId(items) || remembered || '';
}

function requestValues(request) {
  if (!request) return { lib_category_id: '', amount: '', payee: '', description: '' };
  return {
    lib_category_id: request.lib_category_id == null ? '' : String(request.lib_category_id),
    amount: request.estimated_amount == null ? '' : String(request.estimated_amount),
    payee: request.supplier == null ? '' : String(request.supplier),
    description: request.description == null ? '' : String(request.description),
  };
}

/**
 * The form after linking `request` (or unlinking, when null). The line item
 * always follows the request. Amount, payee and description are filled when
 * blank or when they still hold the previously linked request's values, so
 * what the user typed is kept.
 */
export function prefillFromRequest(form, request, previous = null) {
  const from = requestValues(request);
  const old = requestValues(previous);
  const next = { ...form, purchase_request_id: request ? String(request.id) : '' };
  if (request) next.lib_category_id = from.lib_category_id;
  for (const key of ['amount', 'payee', 'description']) {
    const current = String(form[key] ?? '');
    const untouched = current.trim() === '' || (previous && current === old[key]);
    if (untouched) next[key] = request ? from[key] : '';
  }
  return next;
}
