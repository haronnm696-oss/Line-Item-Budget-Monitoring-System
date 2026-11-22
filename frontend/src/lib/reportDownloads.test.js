import test from 'node:test';
import assert from 'node:assert/strict';
import { canSeePayrollRegister, reportDownloadOptions, reportsEyebrow, reportTypesFor, showReportProjectField } from './reportDownloads.js';
import { RESEARCHER_EYEBROW, transactionsEyebrow } from './pageHead.js';

test('every report downloads as PDF or CSV; Form B names its layout', () => {
  assert.deepEqual(reportDownloadOptions('ledger').map((o) => o.label), ['PDF', 'CSV']);
  assert.deepEqual(reportDownloadOptions('form-b').map((o) => o.label), ['Form B (PDF)', 'CSV']);
  assert.deepEqual(reportDownloadOptions('form-b').map((o) => o.key), ['pdf', 'csv']);
});

test('the Reports eyebrow follows the sidebar group of each role', () => {
  assert.equal(reportsEyebrow('PL'), 'Output · Project Leader');
  assert.equal(reportsEyebrow('FIN'), 'Records · Finance/Admin');
  assert.equal(reportsEyebrow('RES'), 'View · Researcher');
});

test('the Reports page hides the Project field with one project (Researcher, Finance/Admin)', () => {
  for (const role of ['RES', 'FIN']) {
    assert.equal(showReportProjectField(role, 'ready', 1), false, role);
    assert.equal(showReportProjectField(role, 'ready', 2), true, role);
    assert.equal(showReportProjectField(role, 'loading', 0), false, role);
    assert.equal(showReportProjectField(role, 'empty', 0), false, role);
  }
  // The Project Leader never has it; the Program Leader always does.
  assert.equal(showReportProjectField('PL', 'ready', 3), false);
  assert.equal(showReportProjectField('PRG', 'ready', 1), true);
});

test('Researchers get no Payroll register card', () => {
  const types = [{ key: 'form-b' }, { key: 'payroll-register' }, { key: 'ledger' }];
  assert.deepEqual(reportTypesFor('RES', types).map((t) => t.key), ['form-b', 'ledger']);
  for (const role of ['PRG', 'PL', 'FIN', 'RA']) {
    assert.equal(reportTypesFor(role, types).length, 3, role);
    assert.equal(canSeePayrollRegister(role), true, role);
  }
  assert.equal(canSeePayrollRegister('RES'), false);
});

test('every Researcher page uses one eyebrow; Transactions follows the role', () => {
  assert.equal(reportsEyebrow('RES'), RESEARCHER_EYEBROW);
  assert.equal(transactionsEyebrow('RES'), RESEARCHER_EYEBROW);
  assert.equal(transactionsEyebrow('PL'), 'Project');
  assert.equal(transactionsEyebrow('FIN'), 'Records · Finance/Admin');
});
