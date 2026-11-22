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

test('the top bar has no project picker', () => {
  const shell = source('layouts/RoleShell.jsx');
  const header = shell.slice(shell.indexOf('<header className="program-topbar">'), shell.indexOf('</header>'));
  assert.ok(header.length > 0);
  assert.doesNotMatch(header, /<select|Project|topBarExtra/);
  const layout = source('layouts/RoleLayout.jsx');
  assert.doesNotMatch(layout, /TopBarProjectPicker|topBarExtra/);
  assert.doesNotMatch(source('styles/global.css'), /topbar-project/);
  for (const file of sourceFiles()) {
    assert.doesNotMatch(readFileSync(file, 'utf8'), /TopBarProjectPicker|ProjectSelect/, rel(file));
  }
  // Sidebar links still carry ?project= so the choice follows the user.
  assert.match(layout, /navTo=\{current\.withPicker \? \(to\) => withProject\(to, current\.projectId\)/);
});

// Every page of the Research Assistant, Finance/Admin and Researcher menus
// whose title has a project line (Reports has its own Project field for the report).
const ROLE_PAGES = [
  'pages/dashboards/ResearchAssistantDashboard.jsx',
  'pages/assistant/AssistantExpenses.jsx',
  'pages/assistant/PurchaseRequests.jsx',
  'pages/assistant/AssistantPayroll.jsx',
  'pages/assistant/Documents.jsx',
  'pages/assistant/MySubmissions.jsx',
  'pages/assistant/BalancingQuery.jsx',
  'pages/dashboards/FinanceDashboard.jsx',
  'pages/finance/VerificationQueue.jsx',
  'pages/finance/FinanceExpenses.jsx',
  'pages/finance/FinancePayroll.jsx',
  'pages/leader/Transactions.jsx',
  'pages/dashboards/ResearcherDashboard.jsx',
  'pages/researcher/ResearcherBudget.jsx',
];

test('every role page shows the shared project line, not its own', () => {
  for (const path of ROLE_PAGES) {
    const page = source(path);
    assert.match(page, /import ProjectLine from '\.\.\/\.\.\/components\/ProjectLine';/, path);
    const head = page.slice(page.indexOf('<div className="pl-head">'), page.indexOf('<LoadState'));
    assert.match(head, /<ProjectLine\s+picker=\{picker\}/, path);
    assert.doesNotMatch(head, /className="program-sub"/, `${path} writes its own project line`);
  }
});

test('"Switch project" is one ActionMenu on the project line, shown only with more than one project', () => {
  const line = source('components/ProjectLine.jsx');
  assert.match(line, /const canSwitch = showProjectSwitch\(normalizeRole\(user\), picker\.projects\);/);
  assert.match(line, /\{canSwitch && \(\s*<ActionMenu/);
  assert.match(line, /Switch project <span aria-hidden="true">▾<\/span>/);
  // "<code> · <title>", current one checked; the title is truncated in CSS.
  assert.match(line, /<b>\{item\.code\}<\/b> · <span className="project-switch-title">\{item\.title\}<\/span>/);
  assert.match(line, /checked: String\(item\.id\) === String\(picker\.projectId\)/);
  assert.match(line, /chooseProject\(\{ userId: user\?\.id, role: picker\.role, projectId, search: location\.search \}\)/);
  assert.match(source('styles/global.css'), /\.project-switch-option\{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap\}/);
  const menu = source('components/ActionMenu.jsx');
  assert.match(menu, /role=\{radio \? 'menuitemradio' : 'menuitem'\}/);
  assert.match(menu, /aria-checked=\{radio \? Boolean\(option\.checked\) : undefined\}/);
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
  for (const id of ['ra-pr-supplier', 'ra-pr-purpose']) {
    assert.match(purchase, new RegExp(`id="${id}"[^>]*autoComplete="off"`), id);
  }
  // Each item row's description.
  assert.match(purchase, /id=\{id\('desc'\)\}[^>]*autoComplete="off"/);
});

test('the Researcher Overview has no Reports card; Transaction summary is full width', () => {
  const page = source('pages/dashboards/ResearcherDashboard.jsx');
  assert.doesNotMatch(page, /program-card-title">Reports</);
  assert.doesNotMatch(page, /format=pdf|format=csv|res-export/);
  assert.match(page, /program-card-title">Transaction summary</);
  // Not in a two-column row any more.
  assert.doesNotMatch(page, /pl-row-charts/);
});

// The Researcher's menu: eyebrow and "Read-only access" badge from PageTitle.
const RESEARCHER_PAGES = [
  ['pages/dashboards/ResearcherDashboard.jsx', /<PageTitle eyebrow=\{RESEARCHER_EYEBROW\} title="[^"]+" readOnly \/>/],
  ['pages/researcher/ResearcherBudget.jsx', /<PageTitle eyebrow=\{RESEARCHER_EYEBROW\} title="Budget" readOnly \/>/],
  ['pages/leader/Transactions.jsx', /<PageTitle eyebrow=\{transactionsEyebrow\(roleCode\)\} title="Transactions" readOnly=\{readOnly\} \/>/],
  ['pages/Reports.jsx', /<PageTitle eyebrow=\{reportsEyebrow\(roleCode\)\} title="Reports" readOnly=\{isReadOnlyRole\(roleCode\)\} \/>/],
];

test('every Researcher page shows the eyebrow and the read-only badge in the same place', () => {
  for (const [path, title] of RESEARCHER_PAGES) {
    const page = source(path);
    assert.match(page, title, path);
    assert.doesNotMatch(page, /Read-only access/, `${path}: the badge comes from PageTitle`);
  }
  assert.match(source('components/PageTitle.jsx'), /res-readonly"><span aria-hidden="true">👁<\/span> Read-only access/);
});

test('the Reports page hides the Project field with one project and shows no payroll register to Researchers', () => {
  const page = source('pages/Reports.jsx');
  assert.match(page, /const showProjectField = showReportProjectField\(roleCode, projectsStatus, projectList\.length\);/);
  assert.match(page, /\{showProjectField && <div \{\.\.\.fieldProps\}>\s*<label htmlFor="report-param-project">/);
  assert.match(page, /Generated from verified expenses and verified payroll · R&D Form B/);
  assert.doesNotMatch(page, /REPORT_TYPES\.(map|find)/);
  assert.match(page, /const registerKey = listRow && seesRegister \?/);
});

test('the Researcher Budget reuses the Form B table read-only, with Remaining', () => {
  const page = source('pages/researcher/ResearcherBudget.jsx');
  assert.match(page, /<FormBTable formB=\{formB\} remaining \/>/);
  assert.doesNotMatch(page, /setup=|RowMenu/);
  assert.match(source('pages/program/ProgramProjectDetail.jsx'), /<FormBTable formB=\{formB\} setup=\{setup\} onOpen=\{openDialog\} \/>/);
});

test('the Project Leader cancels a purchase request from the review panel only, with a reason', () => {
  const panel = source('components/leader/RecordPanel.jsx');
  // Shown for a purchase request the server marks cancellable (approved or partly fulfilled).
  assert.match(panel, /item\.type === 'purchase_request' && item\.can_cancel && onCancel &&/);
  assert.match(panel, />Cancel request<\/button>/);
  // The same cancel endpoint, through a dialog that requires a reason.
  const actions = source('components/leader/useReviewActions.jsx');
  assert.match(actions, /ENDPOINTS\.purchaseRequestStep\(item\.id, 'cancel'\), \{ reason \}/);
  assert.match(actions, /<ConfirmDialog[\s\S]*reasonLabel="Reason"/);
  assert.match(source('components/assistant/ConfirmDialog.jsx'), /if \(reasonLabel && !reason\.trim\(\)\)/);
  assert.match(source('pages/leader/Submissions.jsx'), /onCancel=\{actions\.openCancel\}/);
  // Never on list rows.
  assert.doesNotMatch(source('components/leader/ReviewRow.jsx'), /cancel/i);
});
