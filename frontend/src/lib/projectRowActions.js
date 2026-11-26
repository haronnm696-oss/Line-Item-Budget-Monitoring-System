import { ENDPOINTS } from '../api/endpoints.js';

/**
 * The "⋯" menu of each row on the Program Leader project page
 * (/program-projects/:id). Each option opens the dialog the row used to open
 * from its own Edit / Remove button, with the same endpoint: the menu only
 * changes how the action is reached, never what it does.
 *
 * Option: { key, label, danger?, disabled?, note?, dialog? }. `dialog` is
 * what the page puts in its dialog state; a remove dialog carries the `url`
 * it deletes.
 */

const IN_USE = 'In use: it has expenses, payroll or purchase requests.';

/** Line item (R&D Form B row). `used`: it has expenses, payroll or purchase requests. */
export function lineItemActions(item, { used = false } = {}) {
  return [
    { key: 'edit', label: 'Edit', dialog: { kind: 'line', item } },
    used
      ? { key: 'remove', label: 'Remove', danger: true, disabled: true, note: IN_USE }
      : { key: 'remove', label: 'Remove', danger: true, dialog: { kind: 'removeLine', item, url: ENDPOINTS.programLineItem(item.id) } },
  ];
}

/** Release. Only still-scheduled releases can change (setup.editable_tranches). */
export function trancheActions(tranche, { editable = false } = {}) {
  if (!editable) return [];
  return [
    { key: 'edit', label: 'Edit', dialog: { kind: 'tranche', item: tranche } },
    { key: 'remove', label: 'Remove', danger: true, dialog: { kind: 'removeTranche', item: tranche, url: ENDPOINTS.programTranche(tranche.id) } },
  ];
}

/** Personnel record: Edit, Special Order file, Remove. */
export function personnelActions(record) {
  return [
    { key: 'edit', label: 'Edit', dialog: { kind: 'form', record } },
    { key: 'order', label: 'Special Order file', dialog: { kind: 'order', record, url: ENDPOINTS.personnelSpecialOrder(record.id) } },
    { key: 'remove', label: 'Remove', danger: true, dialog: { kind: 'remove', record, url: ENDPOINTS.personnelRecord(record.id) } },
  ];
}

/** Project document (MOA, Forms A–C…). Documents are replaced, not edited. */
export function documentActions(doc) {
  return [
    { key: 'remove', label: 'Remove', danger: true, dialog: { kind: 'remove', doc, url: ENDPOINTS.programProjectDocument(doc.id) } },
  ];
}

/** Menu options for <RowMenu>: each opens its dialog through `open(dialog)`. */
export function menuOptions(actions, open) {
  return actions.map(({ dialog, ...option }) => ({ ...option, onSelect: () => open(dialog) }));
}

/**
 * One "Recent activity" line, worded like the Audit log page
 * (AuditEntryPresenter): "Release added — Release 1".
 */
export function activityTitle(entry) {
  const action = entry?.action_label || 'Activity recorded';
  const record = entry?.record_label;
  return record ? `${action} — ${record}` : action;
}
