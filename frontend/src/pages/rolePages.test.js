import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

// No JSX renderer is set up for node:test, so these check the component sources.
const SRC = fileURLToPath(new URL('..', import.meta.url));
const source = (path) => readFileSync(join(SRC, path), 'utf8');

function sourceFiles(dir = SRC) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return sourceFiles(path);
    return /\.(jsx?|tsx?)$/.test(name) && !/\.test\.js$/.test(name) ? [path] : [];
  });
}

const rel = (path) => path.slice(SRC.length).replace(/\\/g, '/');

test('no page has its own Project picker; the top bar has the one picker', () => {
  for (const file of sourceFiles(join(SRC, 'pages'))) {
    const text = readFileSync(file, 'utf8');
    assert.doesNotMatch(text, /ProjectSelect/, `${rel(file)} still has a per-page Project picker`);
  }
  const layout = source('layouts/RoleLayout.jsx');
  assert.match(layout, /<TopBarProjectPicker/);
  assert.match(layout, /showProjectPicker\(code, picked\.projects\)/);
  assert.match(layout, /topBarExtra=\{current\.showPicker/);
  // Sidebar links carry ?project= so the choice follows the user.
  assert.match(layout, /navTo=\{current\.withPicker \? \(to\) => withProject\(to, current\.projectId\)/);
  assert.match(source('layouts/RoleShell.jsx'), /\{topBarExtra\}/);
});

test('the Research Assistant Overview has no header action buttons', () => {
  const page = source('pages/dashboards/ResearchAssistantDashboard.jsx');
  const head = page.slice(page.indexOf('<div className="pl-head">'), page.indexOf('{actions.feedback'));
  assert.ok(head.length > 0);
  assert.doesNotMatch(head, /pl-head-actions|<button|program-btn/);
  for (const label of ['Upload document', 'Purchase request', 'Upload payroll', 'Record expense']) {
    assert.doesNotMatch(head, new RegExp(`>\\s*${label}\\s*<`));
  }
  assert.doesNotMatch(page, /UploadDocumentDialog|RecordExpenseDialog|PurchaseRequestDialog/);
});

test('the Overview has no bottom "Missing receipts" card; its KPI opens Expenses and receipts', () => {
  const page = source('pages/dashboards/ResearchAssistantDashboard.jsx');
  assert.doesNotMatch(page, /function MissingReceipts|<MissingReceipts/);
  assert.match(page, /<Kpi to=\{`\/expenses\$\{q\}`\} label="Missing receipts"/);
});

test('the standalone "Upload document" dialog is gone', () => {
  for (const file of sourceFiles()) {
    assert.doesNotMatch(readFileSync(file, 'utf8'), /UploadDocumentDialog/, rel(file));
  }
});

test('no raw browser file input: only FileField renders one, visually hidden', () => {
  const offenders = sourceFiles()
    .filter((file) => !rel(file).endsWith('components/FileField.jsx'))
    .filter((file) => /type=["'{]+file\b|type:\s*['"]file['"]|createElement\(\s*['"]input['"]/.test(readFileSync(file, 'utf8')))
    .map(rel);
  assert.deepEqual(offenders, []);
  assert.match(source('components/FileField.jsx'), /className="file-field-input" type="file"/);
  assert.match(source('styles/global.css'), /\.file-field-input\{position:absolute;width:1px;height:1px/);
});

test('Record expense and New purchase request use the shared FileField', () => {
  assert.match(source('components/leader/RecordExpenseDialog.jsx'), /<FileField\s+id="ld-exp-file"/);
  assert.match(source('components/assistant/PurchaseRequestDialog.jsx'), /<FileField\s+id="ra-pr-file"/);
});

test('free-text record fields turn off browser suggestions', () => {
  const expense = source('components/leader/RecordExpenseDialog.jsx');
  for (const id of ['ld-exp-ref', 'ld-exp-payee', 'ld-exp-desc']) {
    assert.match(expense, new RegExp(`id="${id}"[^>]*autoComplete="off"`), id);
  }
  const purchase = source('components/assistant/PurchaseRequestDialog.jsx');
  for (const id of ['ra-pr-supplier', 'ra-pr-desc']) {
    assert.match(purchase, new RegExp(`id="${id}"[^>]*autoComplete="off"`), id);
  }
});
