import test from 'node:test';
import assert from 'node:assert/strict';
import { itemName, withUnitDescription } from './lineItemNames.js';

const blank = { line_item_name: '', unit_description: '' };

/** Types `text` into "Who or what" one key at a time, as a user would. */
function typeUnit(form, text) {
  let next = form;
  for (let i = 1; i <= text.length; i += 1) next = withUnitDescription(next, text.slice(0, i));
  return next;
}

test('typing in "Who or what" fills Item while Item is untouched', () => {
  const form = typeUnit(blank, 'Project Leader');
  assert.equal(form.unit_description, 'Project Leader');
  assert.equal(form.line_item_name, 'Project Leader');

  // Still following: Item equals the previous "Who or what" text.
  const corrected = withUnitDescription(withUnitDescription(form, 'Project Leade'), 'Project Leader I');
  assert.equal(corrected.line_item_name, 'Project Leader I');

  // Clearing "Who or what" clears the Item it filled.
  assert.equal(withUnitDescription(form, '').line_item_name, '');
});

test('an Item typed by the user is never overwritten', () => {
  const synced = typeUnit(blank, 'Laptop');
  const edited = { ...synced, line_item_name: 'IoT devices' };
  const after = typeUnit({ ...edited, unit_description: '' }, 'Laptops');
  assert.equal(after.unit_description, 'Laptops');
  assert.equal(after.line_item_name, 'IoT devices');

  // An Item entered first is kept too.
  assert.equal(typeUnit({ line_item_name: 'Project Staff', unit_description: '' }, 'Researcher').line_item_name, 'Project Staff');
  // Other fields are untouched.
  assert.equal(withUnitDescription({ ...blank, quantity: '2' }, 'Laptop').quantity, '2');
});

test('an existing line item with the same words keeps following', () => {
  const stored = { line_item_name: 'Project Leader', unit_description: 'Project Leader' };
  assert.equal(withUnitDescription(stored, 'Project Leader II').line_item_name, 'Project Leader II');
  const different = { line_item_name: 'Honoraria', unit_description: 'Project Leader' };
  assert.equal(withUnitDescription(different, 'Project Leader II').line_item_name, 'Honoraria');
});

test('on save an empty Item falls back to "Who or what"', () => {
  assert.equal(itemName({ line_item_name: '', unit_description: ' Project Leader ' }), 'Project Leader');
  assert.equal(itemName({ line_item_name: '   ', unit_description: 'Laptop' }), 'Laptop');
  assert.equal(itemName({ line_item_name: 'IoT devices', unit_description: 'Laptop' }), 'IoT devices');
  // Both empty: nothing to save, so the dialog still asks for the name.
  assert.equal(itemName({ line_item_name: '', unit_description: '' }), '');
  assert.equal(itemName({}), '');
});
