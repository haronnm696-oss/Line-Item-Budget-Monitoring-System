import assert from 'node:assert/strict';
import test from 'node:test';

import { ENDPOINTS } from '../api/endpoints.js';
import {
  activityTitle,
  documentActions,
  lineItemActions,
  menuOptions,
  personnelActions,
  trancheActions,
} from './projectRowActions.js';

const byKey = (actions) => Object.fromEntries(actions.map((action) => [action.key, action]));

test('line item menu: Edit opens the line dialog, Remove deletes through the same endpoint', () => {
  const item = { id: 14, name: 'Travelling expenses' };
  const { edit, remove } = byKey(lineItemActions(item));
  assert.deepEqual(edit.dialog, { kind: 'line', item });
  assert.deepEqual(remove.dialog, { kind: 'removeLine', item, url: ENDPOINTS.programLineItem(14) });
  assert.equal(remove.url ?? remove.dialog.url, '/api/program/line-items/14');
  assert.equal(remove.danger, true);
});

test('a line item in use keeps Remove in the menu, disabled with the reason', () => {
  const { remove } = byKey(lineItemActions({ id: 3, name: 'Honoraria' }, { used: true }));
  assert.equal(remove.disabled, true);
  assert.equal(remove.dialog, undefined);
  assert.match(remove.note, /expenses, payroll or purchase requests/);
});

test('release menu: same tranche endpoints; a requested or released one has no menu', () => {
  const tranche = { id: 9, name: 'Release 2' };
  const { edit, remove } = byKey(trancheActions(tranche, { editable: true }));
  assert.deepEqual(edit.dialog, { kind: 'tranche', item: tranche });
  assert.deepEqual(remove.dialog, { kind: 'removeTranche', item: tranche, url: ENDPOINTS.programTranche(9) });
  assert.deepEqual(trancheActions(tranche, { editable: false }), []);
});

test('personnel menu: Edit, Special Order file and Remove with the personnel endpoints', () => {
  const record = { id: 5, name: 'Papaw Dianalan' };
  const actions = personnelActions(record);
  assert.deepEqual(actions.map((action) => action.label), ['Edit', 'Special Order file', 'Remove']);
  const { edit, order, remove } = byKey(actions);
  assert.deepEqual(edit.dialog, { kind: 'form', record });
  assert.deepEqual(order.dialog, { kind: 'order', record, url: ENDPOINTS.personnelSpecialOrder(5) });
  assert.deepEqual(remove.dialog, { kind: 'remove', record, url: ENDPOINTS.personnelRecord(5) });
});

test('document menu: Remove deletes through the project document endpoint', () => {
  const doc = { id: 21, file_name: 'MOA.pdf' };
  const [remove] = documentActions(doc);
  assert.deepEqual(remove.dialog, { kind: 'remove', doc, url: ENDPOINTS.programProjectDocument(21) });
});

test('menuOptions: choosing an option opens exactly its dialog', () => {
  const opened = [];
  const options = menuOptions(personnelActions({ id: 5, name: 'A' }), (dialog) => opened.push(dialog));
  options.forEach((option) => option.onSelect());
  assert.deepEqual(opened.map((dialog) => [dialog.kind, dialog.url]), [
    ['form', undefined],
    ['order', '/api/program/personnel/5/special-order'],
    ['remove', '/api/program/personnel/5'],
  ]);
  assert.ok(options.every((option) => !('dialog' in option)));
});

test('recent activity reads like the Audit trail, never class names', () => {
  assert.equal(activityTitle({ action_label: 'Release added', record_label: 'Release 1' }), 'Release added — Release 1');
  assert.equal(activityTitle({ action_label: 'Line item added', record_label: null }), 'Line item added');
  const legacy = activityTitle({ action: 'created', record_type: 'ProjectTranche' });
  assert.doesNotMatch(legacy, /ProjectTranche|Created ·/);
});
