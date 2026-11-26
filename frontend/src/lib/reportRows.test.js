import assert from 'node:assert/strict';
import test from 'node:test';

import { filterReportRows, ledgerPendingNote, verifiedRows } from './reportRows.js';

const rows = [
  { kind: 'expense', date: '2026-10-02', desc: 'Paper', type: 'MOOE', amount: 1000, status: 'Verified' },
  { kind: 'expense', date: '2026-10-05', desc: 'Ink', type: 'MOOE', amount: 500, status: 'Pending' },
  { kind: 'expense', date: '2026-10-06', desc: 'Returned', type: 'MOOE', amount: 300, status: 'Rejected' },
  { kind: 'payroll', date: '2026-10-31', desc: 'Payroll October 2026', type: 'PS', amount: 8800, status: 'Verified' },
  { kind: 'expense', date: '2025-03-01', desc: 'Old', type: 'MOOE', amount: 50, status: 'Verified' },
];

test('the Expense Ledger lists verified expenses and verified payroll only', () => {
  const listed = verifiedRows(rows).map((r) => r.desc);
  assert.deepEqual(listed, ['Paper', 'Payroll October 2026', 'Old']);
  assert.ok(!listed.includes('Ink') && !listed.includes('Returned'));
  assert.deepEqual(verifiedRows(undefined), []);
});

test('the pending note is shown only when records are waiting for verification', () => {
  assert.equal(ledgerPendingNote([]), '');
  assert.equal(ledgerPendingNote(undefined), '');
  assert.equal(
    ledgerPendingNote([{ amount: 500 }, { amount: 30000 }]),
    'Only verified expenses and verified payroll are included. 2 records still pending verification (₱30,500.00) are not counted.',
  );
  assert.equal(
    ledgerPendingNote([{ amount: 1234.5 }]),
    'Only verified expenses and verified payroll are included. 1 record still pending verification (₱1,234.50) is not counted.',
  );
});

test('period and category filters (pending entries are filtered the same way)', () => {
  const today = new Date('2026-11-26T00:00:00Z');
  assert.deepEqual(filterReportRows(rows, { period: 'Current year', category: 'PS and MOOE' }, today).map((r) => r.desc),
    ['Payroll October 2026', 'Returned', 'Ink', 'Paper']);
  assert.deepEqual(filterReportRows(rows, { period: 'All dates', category: 'PS only' }, today).map((r) => r.desc), ['Payroll October 2026']);
  assert.equal(filterReportRows(rows, { period: 'All dates', category: 'MOOE only' }, today).length, 4);
  assert.deepEqual(filterReportRows(null, { period: 'All dates', category: 'PS and MOOE' }), []);
});
