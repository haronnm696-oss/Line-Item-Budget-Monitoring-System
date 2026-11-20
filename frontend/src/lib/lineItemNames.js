/**
 * Line-item dialog: "Item" (line_item_name) and "Who or what"
 * (unit_description) usually hold the same words on a computed row
 * ("Project Leader"). Typing in "Who or what" fills Item too while Item is
 * untouched: empty, or still the previous "Who or what" text. Once the user
 * edits Item to something else, it stops following. Both fields stay, and
 * the Form B wording does not change.
 */

/** The form after typing `value` in "Who or what". */
export function withUnitDescription(form, value) {
  const item = String(form.line_item_name ?? '');
  const previous = String(form.unit_description ?? '');
  const untouched = item.trim() === '' || item === previous;
  return { ...form, unit_description: value, ...(untouched ? { line_item_name: value } : {}) };
}

/** The item name to save: Item, or "Who or what" when Item is empty. */
export function itemName(form) {
  return String(form.line_item_name ?? '').trim() || String(form.unit_description ?? '').trim();
}
