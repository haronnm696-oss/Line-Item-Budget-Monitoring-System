import { test } from 'node:test';
import assert from 'node:assert/strict';
import { periodText, personnelForm, validatePersonnel } from './personnel.js';

const PERIOD = { start: '2026-01-01', end: '2026-12-31' };
const valid = {
  ...personnelForm(null),
  user_id: '7', position: 'Project Staff Level 3', lib_category_id: '3', special_order_no: 'SO No. 1111-OP s. 2025',
  effective_from: '2026-01-01', effective_to: '2026-12-31', monthly_honorarium: '7500.00',
};

test('a valid new record; the bank account is sent only when typed', () => {
  const { errors, body } = validatePersonnel(valid, PERIOD, { creating: true });
  assert.deepEqual(errors, {});
  assert.deepEqual(body, {
    user_id: 7, position: 'Project Staff Level 3', lib_category_id: 3, special_order_no: 'SO No. 1111-OP s. 2025',
    effective_from: '2026-01-01', effective_to: '2026-12-31', monthly_honorarium: 7500,
  });
  assert.equal(validatePersonnel({ ...valid, bank_account: '0000-1234-9170' }, PERIOD, { creating: true }).body.bank_account, '0000-1234-9170');
});

test('editing: blank keeps the stored account, the checkbox removes it', () => {
  assert.equal('bank_account' in validatePersonnel(valid, PERIOD, { creating: false }).body, false);
  assert.equal(validatePersonnel({ ...valid, remove_bank_account: true }, PERIOD, { creating: false }).body.bank_account, null);
  assert.equal('user_id' in validatePersonnel(valid, PERIOD, { creating: false }).body, false);
});

test('required fields, money and account format', () => {
  const { errors } = validatePersonnel(personnelForm(null), PERIOD, { creating: true });
  assert.deepEqual(Object.keys(errors).sort(), ['effective_from', 'effective_to', 'lib_category_id', 'monthly_honorarium', 'position', 'special_order_no', 'user_id']);
  assert.equal(validatePersonnel({ ...valid, monthly_honorarium: '-1' }, PERIOD, { creating: true }).errors.monthly_honorarium, 'The monthly honorarium cannot be negative.');
  assert.ok(validatePersonnel({ ...valid, monthly_honorarium: '1.555' }, PERIOD, { creating: true }).errors.monthly_honorarium);
  assert.deepEqual(validatePersonnel({ ...valid, monthly_honorarium: '0' }, PERIOD, { creating: true }).errors, {});
  assert.ok(validatePersonnel({ ...valid, bank_account: 'ABC-123' }, PERIOD, { creating: true }).errors.bank_account);
});

test('effective dates stay inside the project period', () => {
  const check = (from, to) => validatePersonnel({ ...valid, effective_from: from, effective_to: to }, PERIOD, { creating: true }).errors;
  assert.equal(check('2025-12-31', '2026-06-30').effective_from, 'The start date must be within the project period.');
  assert.equal(check('2026-01-01', '2027-01-01').effective_to, 'The end date must be within the project period.');
  assert.equal(check('2026-06-01', '2026-05-31').effective_to, 'The end date must be on or after the start date.');
  assert.deepEqual(check('2026-03-01', '2026-08-31'), {});
});

test('the form never prefills a bank account; period text', () => {
  assert.equal(personnelForm({ bank_account_masked: '****9170' }).bank_account, '');
  assert.equal(periodText('2026-01-01', '2026-12-31', (d) => d), '2026-01-01 – 2026-12-31');
  assert.equal(periodText(null, null, (d) => d), '—');
});

test('the Special Order shows its type, not the raw file name', async () => {
  const { specialOrderLabel, specialOrderPreview } = await import('./personnel.js');
  assert.equal(specialOrderLabel({ file_name: 'SO 1111-OP s.2025 scan final.pdf', mime: 'application/pdf' }), 'Special Order (PDF)');
  assert.equal(specialOrderLabel({ file_name: 'IMG_2041.JPG' }), 'Special Order (JPG)');
  assert.equal(specialOrderLabel({ file_name: 'order', mime: 'image/png' }), 'Special Order (PNG)');
  assert.equal(specialOrderLabel({ file_name: 'order.docx' }), 'Special Order');
  assert.equal(specialOrderLabel(null), '');
  assert.equal(specialOrderPreview({ file_name: 'a.pdf' }), 'pdf');
  assert.equal(specialOrderPreview({ file_name: 'a.jpeg' }), 'image');
  assert.equal(specialOrderPreview({ file_name: 'a.docx' }), null);
});
