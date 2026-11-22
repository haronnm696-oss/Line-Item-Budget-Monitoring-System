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

/**
 * Approved requests as link choices for an expense: one per request and
 * line item that has no linked expense yet. Each choice looks like a
 * request (id, lib_category_id, estimated_amount, supplier, description,
 * funding_source) for that line item only, plus `key` ("id:lineId").
 */
export function linkOptions(requests) {
  const options = [];
  for (const request of requests ?? []) {
    const items = Array.isArray(request.items) && request.items.length > 0
      ? request.items
      : [{ lib_category_id: request.lib_category_id, line_item: request.line_item, estimated_cost: request.estimated_amount, funding_source: request.funding_source }];
    const lines = new Map();
    for (const item of items) {
      if (item.expense_linked) continue;
      const key = String(item.lib_category_id);
      const line = lines.get(key) ?? { cents: 0, item, count: 0 };
      line.cents += cents(item.estimated_cost);
      line.count += 1;
      lines.set(key, line);
    }
    const lineCount = new Set(items.map((item) => String(item.lib_category_id))).size;
    for (const [lineId, line] of lines) {
      options.push({
        key: `${request.id}:${lineId}`,
        id: request.id,
        lib_category_id: Number(lineId),
        line_item: line.item.line_item ?? request.line_item,
        estimated_amount: line.cents / 100,
        funding_source: line.item.funding_source || request.funding_source || 'funder',
        supplier: request.supplier ?? null,
        description: request.purpose ?? request.description ?? '',
        items_on_line: line.count,
        several_lines: lineCount > 1,
      });
    }
  }
  return options;
}
