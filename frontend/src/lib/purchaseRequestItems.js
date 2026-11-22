// Items of a purchase request (MSU Purchase Request form): quantity, unit,
// description, estimated unit cost and estimated cost (quantity x unit cost,
// computed, read-only), each charged to its own line item. The server
// computes the same numbers (PurchaseRequestService::cost); amounts are
// worked in centavos so 3 x 33.33 is 99.99, never 99.99000000000001.
import { moneyError, moneyNumber, moneyText } from './moneyInput.js';

export const UNITS = ['pc', 'set', 'box', 'lot', 'ream', 'unit'];

export function emptyItem() {
  return { lib_category_id: '', quantity: '1', unit: 'pc', description: '', unit_cost: '' };
}

function cents(value) {
  return Math.round((Number(value) || 0) * 100);
}

function wholeQuantity(text) {
  const value = Number(String(text ?? '').trim());
  return Number.isInteger(value) && value >= 1 ? value : null;
}

/** Quantity x unit cost in pesos, or 0 while either is not a valid number. */
export function itemCost(item) {
  const quantity = wholeQuantity(item?.quantity);
  const unit = moneyNumber(item?.unit_cost);
  if (quantity === null || !Number.isFinite(unit)) return 0;
  return (cents(unit) * quantity) / 100;
}

/** Sum of the items' estimated costs. */
export function itemsTotal(items) {
  return (items ?? []).reduce((sum, item) => sum + cents(itemCost(item)), 0) / 100;
}

/** Estimated costs summed per line item id: { [id]: pesos }. */
export function totalsByLine(items) {
  const out = {};
  for (const item of items ?? []) {
    if (!item.lib_category_id) continue;
    const key = String(item.lib_category_id);
    out[key] = ((out[key] ?? 0) * 100 + cents(itemCost(item))) / 100;
  }
  return out;
}

/** Field errors per row: [{ lib_category_id?, quantity?, unit?, description?, unit_cost? }]. */
export function itemErrors(items) {
  return (items ?? []).map((item) => {
    const errors = {};
    if (!item.lib_category_id) errors.lib_category_id = 'Choose a line item.';
    if (wholeQuantity(item.quantity) === null) errors.quantity = 'Enter a whole number of at least 1.';
    if (!UNITS.includes(item.unit)) errors.unit = 'Choose a unit.';
    if (!String(item.description ?? '').trim()) errors.description = 'Describe the item.';
    const money = moneyError(item.unit_cost, { positive: true });
    if (money) errors.unit_cost = ['Enter an amount.', 'The amount must be more than zero.'].includes(money) ? 'Enter a unit cost greater than zero.' : money;
    return errors;
  });
}

/** The rows as the API takes them. */
export function itemsPayload(items) {
  return (items ?? []).map((item) => ({
    lib_category_id: Number(item.lib_category_id),
    quantity: Number(item.quantity),
    unit: item.unit,
    description: String(item.description ?? '').trim(),
    unit_cost: moneyNumber(item.unit_cost),
  }));
}

/** API items -> form rows. */
export function itemsToForm(items) {
  const rows = (items ?? []).map((item) => ({
    lib_category_id: item.lib_category_id == null ? '' : String(item.lib_category_id),
    quantity: item.quantity == null ? '1' : String(item.quantity),
    unit: UNITS.includes(item.unit) ? item.unit : 'lot',
    description: item.description == null ? '' : String(item.description),
    unit_cost: moneyText(item.unit_cost),
  }));
  return rows.length > 0 ? rows : [emptyItem()];
}

// ------------------------------------------------------------------ linking an expense
// An expense pays ticked items of one approved request, all on one line
// item. Items are open (no expense), linked (expense not verified yet) or
// paid (expense verified); only open items can be ticked.

/** The request's items; an older request without items reads as one item. */
export function requestItems(request) {
  if (Array.isArray(request?.items) && request.items.length > 0) return request.items;
  if (!request) return [];
  return [{
    id: null, lib_category_id: request.lib_category_id, line_item: request.line_item, quantity: request.quantity, unit: 'lot',
    description: request.description, estimated_cost: request.estimated_amount, funding_source: request.funding_source, status: 'open',
  }];
}

export function isOpenItem(item) {
  return (item.status ?? (item.expense_linked ? 'linked' : 'open')) === 'open';
}

/** Approved requests that still have an open item. */
export function linkableRequests(requests) {
  return (requests ?? []).filter((request) => requestItems(request).some(isOpenItem));
}

/** Items to tick first: every open item when they share one line item, else none. */
export function defaultTicks(request) {
  const open = requestItems(request).filter(isOpenItem);
  const lines = new Set(open.map((item) => String(item.lib_category_id)));
  return lines.size === 1 ? open.map((item) => item.id).filter((id) => id != null) : [];
}

/**
 * Each item with whether it can be ticked: open items on another line item
 * than the ticked ones are disabled with "Record a separate expense for
 * <line item>"; linked and paid items are disabled with their status.
 */
export function tickRows(request, ticked) {
  const items = requestItems(request);
  const chosen = items.filter((item) => item.id != null && ticked.includes(item.id));
  const line = chosen.length > 0 ? String(chosen[0].lib_category_id) : null;
  return items.map((item) => {
    const checked = item.id != null && ticked.includes(item.id);
    if (!isOpenItem(item)) {
      const label = item.status_label ?? (item.status === 'paid' ? 'Paid' : 'Linked (pending)');
      return { item, checked: false, disabled: true, note: item.expense_reference ? `${label} · ${item.expense_reference}` : label };
    }
    if (line !== null && String(item.lib_category_id) !== line) {
      return { item, checked: false, disabled: true, note: `Record a separate expense for ${item.line_item ?? 'this line item'}` };
    }
    // An older request without stored items links as a whole (always ticked).
    if (item.id == null) return { item, checked: true, disabled: true, note: null };
    return { item, checked, disabled: false, note: null };
  });
}

/**
 * The ticked items as a link: { id, lib_category_id, line_item,
 * estimated_amount (sum, the most the expense may be), funding_source,
 * supplier, description } — the shape prefillFromRequest takes. Null when
 * nothing is ticked. item_ids is null for an older request without stored
 * items (the server then takes its open items on that line item).
 */
export function tickedLink(request, ticked) {
  const items = requestItems(request);
  const whole = items.length > 0 && items.every((item) => item.id == null);
  const chosen = whole
    ? items.filter(isOpenItem)
    : items.filter((item) => item.id != null && ticked.includes(item.id) && isOpenItem(item));
  if (!request || chosen.length === 0) return null;
  return {
    id: request.id,
    item_ids: whole ? null : chosen.map((item) => item.id),
    lib_category_id: Number(chosen[0].lib_category_id),
    line_item: chosen[0].line_item ?? request.line_item,
    estimated_amount: chosen.reduce((sum, item) => sum + cents(item.estimated_cost), 0) / 100,
    funding_source: chosen[0].funding_source || request.funding_source || 'funder',
    supplier: request.supplier ?? null,
    description: request.purpose ?? request.description ?? '',
  };
}
