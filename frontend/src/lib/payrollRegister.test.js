import assert from 'node:assert/strict';
import test from 'node:test';

import { REGISTER_PERIOD, registerCell, registerColumns, registerDocumentHtml, registerRows, registerTableHtml } from './payrollRegister.js';

const escape = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const money = (value) => `P${Number(value).toFixed(2)}`;
const person = (name, honorarium, months) => ({
  name, position: 'Researcher', monthly_rate: honorarium, months,
  computation: `(${honorarium.toLocaleString('en-US')} x ${months} ${months === 1 ? 'month' : 'months'}) = ${(honorarium * months).toLocaleString('en-US', { minimumFractionDigits: 2 })}`,
  gross: honorarium * months, recorded_deductions: 0, net: honorarium * months,
});
const register = {
  project: { code: 'P-1', title: 'Project <one>' },
  period_label: 'All dates',
  has_recorded_deductions: false,
  months: [
    { month: '2025-10', month_label: 'October to December 2025', months: 3, rows: [person('Ana', 8800, 3), person('Ben', 8800, 2)],
      totals: { people: 2, gross: 44000, recorded_deductions: 0, net: 44000 } },
  ],
  totals: { people: 2, gross: 44000, recorded_deductions: 0, net: 44000 },
};

test('Reports periods map to the API period', () => {
  assert.deepEqual(REGISTER_PERIOD, { 'All dates': 'all', 'Current year': 'year', 'Last 90 days': '90d' });
});

test('no deduction or allowance columns: computation, gross and net only', () => {
  const labels = registerColumns(register).map((c) => c.label);
  assert.deepEqual(labels, ['Pay period', 'Name', 'Position', 'Computation', 'Gross pay', 'Net pay']);
  assert.ok(!labels.some((label) => /allowance|gsis|philhealth|pag-ibig|tax/i.test(label)));
});

test('older verified batches with recorded deductions get one "Recorded deductions" column', () => {
  const labels = registerColumns({ ...register, has_recorded_deductions: true }).map((c) => c.label);
  assert.deepEqual(labels, ['Pay period', 'Name', 'Position', 'Computation', 'Gross pay', 'Recorded deductions', 'Net pay']);
});

test('rows: each person, a period total, then the grand total', () => {
  const rows = registerRows(register);
  assert.deepEqual(rows.map((r) => r.kind), ['person', 'person', 'month', 'grand']);
  const computation = registerColumns(register).find((c) => c.key === 'computation');
  assert.equal(registerCell(rows[0], computation), '(8,800 x 3 months) = 26,400.00');
  const net = registerColumns(register).find((c) => c.key === 'net');
  assert.equal(registerCell(rows[2], net), 44000);
  assert.equal(rows[2].name, 'Period total');
  assert.equal(registerRows({ months: [] }).length, 0);
});

test('the printed register lists people, computations and totals', () => {
  const html = registerTableHtml(register, escape, money);
  assert.match(html, /Ana/);
  assert.match(html, /\(8,800 x 2 months\) = 17,600\.00/);
  assert.match(html, /P44000\.00/);
  assert.match(registerTableHtml({ ...register, months: [] }, escape, money), /No verified payroll/);

  // Report template table: money headers carry "(₱)", period totals bold, grand total is TOTAL.
  assert.match(html, /<th class="r">Gross pay \(₱\)<\/th>/);
  assert.match(html, /<tr class="rt-subtotal">/);
  assert.match(html, /<tr class="rt-total"><td>TOTAL<\/td>/);
  assert.doesNotMatch(html, /style=/);
});

test('the printed register uses the official report layout (landscape)', () => {
  const doc = registerDocumentHtml(register, {
    escape,
    project: { title: 'Project <one>', code: 'P-1', project_leader: 'Dr. Lead', funding_agency: 'DOST', implementing_agency: 'MSU' },
    signatories: { project_leader: 'Dr. Lead', finance_admin: null },
    preparedBy: { name: 'Ria Assistant', role: 'Research Assistant' },
    generatedAt: new Date('2026-11-18T02:00:00Z'),
    assetBase: '',
  });
  assert.match(doc, /Project &lt;one&gt;/);
  assert.match(doc, /no deductions and no allowances/);
  assert.match(doc, /<h1 class="rt-title">Payroll Register<\/h1>/);
  assert.match(doc, /Period: All dates/);
  assert.match(doc, /size: A4 landscape/);
  assert.match(doc, /44,000\.00/);
  assert.doesNotMatch(doc, /₱44,000/);
  assert.match(doc, /Ria Assistant/);
  assert.match(doc, /Certified correct:/);
  assert.doesNotMatch(doc, /Mindanao State University–Main Campus · LIB Monitoring System/);

  // Without the project detail it still names the register's project.
  assert.match(registerDocumentHtml(register, { escape, assetBase: '' }), /Project &lt;one&gt;/);
});
