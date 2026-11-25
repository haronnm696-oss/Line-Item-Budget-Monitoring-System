import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { ROLE_NAV } from '../layouts/roleNav.js';
import { ROUTE_ROLES } from '../routes/access.js';
import { transactionsTitle } from '../lib/pageHead.js';

// Finance/Admin follows MSU's process: it verifies expenses and payroll
// against their documents and records PR/SAI/ALOBS numbers; it does not
// encode expenses (the Research Assistant and the Project Leader do).
// No JSX renderer is set up for node:test, so pages are checked as source.
const SRC = fileURLToPath(new URL('..', import.meta.url));
const source = (path) => readFileSync(join(SRC, path), 'utf8');

function sourceFiles(dir = SRC) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.(jsx?|tsx?)$/.test(name) && !/\.test\.js$/.test(name) ? [path] : [];
  });
}

test('the Finance/Admin sidebar: Overview; Verify; Records', () => {
  const groups = ROLE_NAV.FIN.map((group) => [group.label ?? null, group.items.map((item) => item.label)]);
  assert.deepEqual(groups, [
    [null, ['Overview']],
    ['Verify', ['Verification queue', 'Purchase requests', 'Payroll']],
    ['Records', ['Transaction history', 'Reports']],
  ]);
  assert.deepEqual(ROLE_NAV.FIN[1].items.map((item) => item.to), ['/verification-queue', '/purchase-requests', '/payroll']);
  assert.deepEqual(ROLE_NAV.FIN[2].items.map((item) => item.to), ['/transaction-history', '/reports']);
});

test('the Finance "Expenses and receipts" page is gone; the Research Assistant keeps its page', () => {
  assert.equal(existsSync(join(SRC, 'pages/finance/FinanceExpenses.jsx')), false);
  assert.deepEqual(ROUTE_ROLES['/expenses'], ['RA']);
  assert.doesNotMatch(source('App.jsx'), /FinanceExpenses/);
  assert.match(source('App.jsx'), /\['\/expenses', <AssistantExpenses \/>\]/);
  assert.equal(ROLE_NAV.RA[1].items[0].label, 'Expenses and receipts');
});

test('the Finance Overview has no Record expense and no Missing receipts card; its KPI opens Transaction history', () => {
  const page = source('pages/dashboards/FinanceDashboard.jsx');
  assert.doesNotMatch(page, /Record expense|RecordExpenseDialog|setRecording/);
  assert.doesNotMatch(page, /program-card-title">Missing receipts</);
  assert.match(page, /<Kpi alert=\{kpis\.missing_receipts > 0\} to=\{`\/transaction-history\$\{q\}\$\{q \? '&' : '\?'\}receipt=missing`\} label="Missing receipts"/);
  // An older expense this Finance/Admin recorded and got back stays editable.
  assert.match(page, /data\.returned_to_you\?\.length > 0/);
  assert.match(page, /actions\.resubmit\(item\)/);
});

test('the Finance Overview shows exact KPI values, never a paused counting animation (₱365 vs ₱500)', () => {
  const page = source('pages/dashboards/FinanceDashboard.jsx');
  assert.doesNotMatch(page, /AnimatedNumber/);
  assert.match(page, /<div className="pl-kpi-value">\{format \? format\(value\)/);
});

test('the Finance Payroll page has no "Payroll to verify" button', () => {
  assert.doesNotMatch(source('pages/finance/FinancePayroll.jsx'), /Payroll to verify/);
});

test('Transaction history: Finance/Admin title; other roles keep theirs', () => {
  assert.equal(transactionsTitle('FIN'), 'Transaction history');
  assert.equal(transactionsTitle('PL'), 'Transactions');
  assert.equal(transactionsTitle('RES'), 'Transactions');
  assert.match(source('pages/leader/Transactions.jsx'), /title=\{transactionsTitle\(roleCode\)\}/);
});

test('Transaction history has a "Missing receipt" filter in the URL', () => {
  const page = source('pages/leader/Transactions.jsx');
  assert.match(page, /const FILTERS = \['from', 'to', 'line_item', 'category', 'receipt'\];/);
  assert.match(page, /<option value="missing">Missing receipt<\/option>/);
  assert.match(page, /receipt: '' \}\)\}>Clear filters/);
});

test('no page says Finance/Admin records expenses', () => {
  for (const file of sourceFiles()) {
    const text = readFileSync(file, 'utf8');
    assert.doesNotMatch(text, /then another Finance\/Admin verifies them/i, file);
    assert.doesNotMatch(text, /Expenses you record go to the Project Leader/i, file);
  }
});
