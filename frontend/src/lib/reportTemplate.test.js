import assert from 'node:assert/strict';
import test from 'node:test';

import {
  programSignatories, projectInfo, projectSignatories, reportAmount, reportDocumentHtml, reportTableHtml,
} from './reportTemplate.js';

const generatedAt = new Date('2026-11-26T07:04:00Z'); // 3:04 PM in Manila

function doc(overrides = {}) {
  return reportDocumentHtml({
    title: 'Expense Ledger',
    filters: 'Period: All dates · Category: PS and MOOE',
    info: projectInfo({ title: 'Rice <study>', code: 'P-1', project_leader: 'Dr. Lead', funding_agency: 'DOST', implementing_agency: 'MSU' }, 'November 26, 2026'),
    body: '<table class="rt-table"></table>',
    signatories: projectSignatories({ preparedBy: { name: 'Ana Cruz', role: 'Research Assistant' }, financeAdmin: 'Fe Admin', projectLeader: 'Dr. Lead' }),
    generatedAt,
    assetBase: 'https://lib.example',
    ...overrides,
  });
}

test('letterhead: MSU seal left, OVCRED logo right, the four org lines and a double maroon rule', () => {
  const html = doc();
  const seal = html.indexOf('/images/msu-seal.png');
  const ovcred = html.indexOf('/images/client-logo.png');
  assert.ok(seal > 0 && ovcred > seal, 'seal first, OVCRED logo second');
  assert.match(html, /src="https:\/\/lib\.example\/images\/msu-seal\.png"/);
  for (const line of ['Republic of the Philippines', 'MINDANAO STATE UNIVERSITY', 'Main Campus, Marawi City', 'Office of the Vice Chancellor for Research and Extension']) {
    assert.ok(html.includes(line), line);
  }
  assert.match(html, /border-top: 4px double #6F1D2A/);
  // No old placeholders.
  assert.doesNotMatch(html, /class="seal">MSU</);
  assert.doesNotMatch(html, /This report was generated from/);
  assert.doesNotMatch(html, /Form B · Line-Item Budget Monitoring System/);
});

test('title, filters, info block, serif A4 portrait and the page footer', () => {
  const html = doc();
  assert.match(html, /<h1 class="rt-title">Expense Ledger<\/h1>/);
  assert.match(html, /text-transform: uppercase; letter-spacing/);
  assert.match(html, /Period: All dates · Category: PS and MOOE/);
  for (const label of ['Project Title', 'Project Code', 'Project Leader', 'Funding Agency', 'Implementing Agency', 'Date Generated']) {
    assert.ok(html.includes(`<span class="rt-info-label">${label}</span>`), label);
  }
  assert.match(html, /Rice &lt;study&gt;/);
  assert.match(html, /size: A4 portrait/);
  assert.match(html, /margin: 14mm/);
  assert.match(html, /font-family: 'Times New Roman'/);
  assert.match(html, /@bottom-left \{ content: "LIB Monitoring System · Generated November 26, 2026 at 3:04 PM"|@bottom-left \{ content: "LIB Monitoring System · Generated November 26, 2026, 3:04 PM"/);
  assert.match(html, /"Page " counter\(page\) " of " counter\(pages\)/);
  assert.match(html, /thead \{ display: table-header-group; \}/);
  assert.match(doc({ landscape: true }), /size: A4 landscape/);
});

test('signature blocks: names in bold uppercase over a line, kept on one page; blank line when unknown', () => {
  const html = doc();
  assert.match(html, /Prepared by:[\s\S]*Ana Cruz[\s\S]*Research Assistant/);
  assert.match(html, /Certified correct:[\s\S]*Fe Admin[\s\S]*Finance\/Admin Staff/);
  assert.match(html, /Noted by:[\s\S]*Dr\. Lead[\s\S]*Project Leader/);
  assert.match(html, /\.rt-sigs \{[^}]*break-inside: avoid/);
  assert.match(html, /\.rt-sig-name \{[^}]*text-transform: uppercase; border-bottom: 1px solid #000/);

  const blank = projectSignatories({ preparedBy: { name: 'Ana', role: 'Research Assistant' }, financeAdmin: null, projectLeader: null });
  assert.deepEqual(blank.map((s) => [s.label, s.name ?? null, s.role]), [
    ['Prepared by:', 'Ana', 'Research Assistant'],
    ['Certified correct:', null, 'Finance/Admin Staff'],
    ['Noted by:', null, 'Project Leader'],
  ]);
  assert.match(doc({ signatories: blank }), /<div class="rt-sig-name">&nbsp;<\/div>\s*<div class="rt-sig-role">Finance\/Admin Staff<\/div>/);

  assert.deepEqual(programSignatories({ preparedBy: { name: 'P', role: 'Program Leader' }, programLeader: 'Dr. Prog' }).map((s) => s.label), ['Prepared by:', 'Approved by:']);
});

test('tables: "(₱)" in amount headers, plain right-aligned amounts, spans and a bold TOTAL row', () => {
  const html = reportTableHtml({
    columns: [{ label: 'Item' }, { label: 'Note' }, { label: 'Amount', amount: true }],
    rows: [
      { cells: ['Paper', '<b>', 1234.5] },
      { kind: 'total', cells: [{ span: 2, text: 'TOTAL' }, null, 1234.5] },
    ],
  });
  assert.match(html, /<th class="r">Amount \(₱\)<\/th>/);
  assert.match(html, /<td class="r">1,234\.50<\/td>/);
  assert.doesNotMatch(html, /₱1/);
  assert.match(html, /&lt;b&gt;/);
  assert.match(html, /<tr class="rt-total"><td colspan="2">TOTAL<\/td><td class="r">1,234\.50<\/td><\/tr>/);
  assert.match(reportTableHtml({ columns: [{ label: 'A' }], rows: [], empty: 'Nothing.' }), /colspan="1">Nothing\.</);
  assert.equal(reportAmount(-5), '-5.00');
});
