import assert from 'node:assert/strict';
import test from 'node:test';

import { REGISTER_PERIOD, registerCell, registerColumns, registerDocumentHtml, registerRows, registerTableHtml } from './payrollRegister.js';

const escape = (value) => String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const money = (value) => `P${Number(value).toFixed(2)}`;
const types = ['GSIS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax'];
const person = (name, gross, deductions) => {
  const total = Object.values(deductions).reduce((a, b) => a + b, 0);
  return { name, position: 'Researcher', monthly_rate: gross, allowance: 0, gross, deductions: { GSIS: 0, PhilHealth: 0, 'Pag-IBIG': 0, 'Withholding Tax': 0, ...deductions }, total_deductions: total, net: gross - total };
};
const register = {
  project: { code: 'P-1', title: 'Project <one>' },
  period_label: 'All dates',
  rates_label: null,
  deduction_types: types,
  months: [
    { month: '2026-10', month_label: 'October 2026', rows: [person('Ana', 30000, { GSIS: 2700 }), person('Ben', 20000, { PhilHealth: 500 })],
      totals: { monthly_rate: 50000, allowance: 0, gross: 50000, deductions: { GSIS: 2700, PhilHealth: 500, 'Pag-IBIG': 0, 'Withholding Tax': 0 }, total_deductions: 3200, net: 46800 } },
  ],
  totals: { monthly_rate: 50000, allowance: 0, gross: 50000, deductions: { GSIS: 2700, PhilHealth: 500, 'Pag-IBIG': 0, 'Withholding Tax': 0 }, total_deductions: 3200, net: 46800 },
};

test('Reports periods map to the API period', () => {
  assert.deepEqual(REGISTER_PERIOD, { 'All dates': 'all', 'Current year': 'year', 'Last 90 days': '90d' });
});

test('one column per deduction between gross and total deductions', () => {
  const labels = registerColumns(register).map((c) => c.label);
  assert.deepEqual(labels, ['Month', 'Name', 'Position', 'Monthly rate', 'Allowance', 'Gross pay', ...types, 'Total deductions', 'Net pay']);
});

test('rows: each person, a month total, then the grand total', () => {
  const rows = registerRows(register);
  assert.deepEqual(rows.map((r) => r.kind), ['person', 'person', 'month', 'grand']);
  const gsis = registerColumns(register).find((c) => c.label === 'GSIS');
  assert.equal(registerCell(rows[0], gsis), 2700);
  assert.equal(registerCell(rows[2], gsis), 2700);
  assert.equal(rows[2].name, 'Month total');
  assert.equal(registerRows({ months: [] }).length, 0);
});

test('the printed register lists people and totals, and prints the label only when set', () => {
  const html = registerTableHtml(register, escape, money);
  assert.match(html, /Ana/);
  assert.match(html, /P46800\.00/);
  assert.match(registerTableHtml({ ...register, months: [] }, escape, money), /No verified payroll/);

  const plain = registerDocumentHtml(register, { escape, money, generated: 'Nov 18, 2026' });
  assert.doesNotMatch(plain, /rates-label" role/);
  assert.match(plain, /Project &lt;one&gt;/);
  const labelled = registerDocumentHtml({ ...register, rates_label: 'Demo deduction rates, not official' }, { escape, money, generated: '' });
  assert.match(labelled, /⚠ Demo deduction rates, not official/);
  assert.ok(labelled.indexOf('Demo deduction rates') < labelled.indexOf('<table>'));
});
