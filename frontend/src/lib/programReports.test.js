import assert from 'node:assert/strict';
import test from 'node:test';

import { auditReportHtml, executiveReportHtml } from './programReports.js';

test('the Executive report: all projects, funder filter, TOTAL row, Prepared by / Approved by', () => {
  const html = executiveReportHtml({
    payload: {
      kpis: { portfolio_budget: 3000000, total_spent: 1000000, remaining: 2000000, utilization_pct: 33.3, pending_approvals: 2 },
      projects: [
        { id: 1, title: 'Small', code: 'S-1', funder: 'CHED', project_leader: 'Dr. A', budget: 1000000, spent: 250000, remaining: 750000, spent_pct: 25, health: 'Healthy' },
        { id: 2, title: 'Big', code: 'B-1', funder: 'DOST', project_leader: 'Dr. B', budget: 2000000, spent: 750000, remaining: 1250000, spent_pct: 37.5, health: 'Watch' },
      ],
    },
    funderLabel: 'All funders',
    preparedBy: { name: 'Dr. Prog', role: 'Program Leader' },
    programLeader: 'Dr. Prog',
    assetBase: '',
  });
  assert.match(html, /<h1 class="rt-title">Executive Report<\/h1>/);
  assert.match(html, /Coverage<\/span><span>:<\/span><span class="rt-info-value">All projects/);
  assert.match(html, /Funding source: All funders/);
  assert.ok(html.indexOf('Big') < html.indexOf('Small'), 'sorted by budget');
  assert.match(html, /Approved Budget \(₱\)/);
  assert.match(html, /<tr class="rt-total"><td colspan="4">TOTAL<\/td><td class="r">3,000,000\.00<\/td>/);
  assert.match(html, /Approved by:[\s\S]*Dr\. Prog[\s\S]*Program Leader/);
  assert.doesNotMatch(html, /Certified correct:/);
});

test('the Audit log print: the filtered entries, filters line, Approved by the Program Leader', () => {
  const html = auditReportHtml({
    entries: [{ ts: 'Nov 1, 2026 9:00 AM', user: 'Fe <Admin>', action: 'verified', record: 'Paper', recordType: 'Expense', change: '—', ip: '1.2.3.4' }],
    actionLabel: (value) => ({ verified: 'Verified' }[value] ?? value),
    filters: 'Action: All actions · Record type: All record types · Date: All dates',
    preparedBy: { name: 'Fe Admin', role: 'Finance/Admin Staff' },
    programLeader: null,
    assetBase: '',
  });
  // Printed upper-case ("AUDIT LOG") by the .rt-title style.
  assert.match(html, /<h1 class="rt-title">Audit Log<\/h1>/);
  assert.match(html, /\.rt-title \{[^}]*text-transform: uppercase/);
  assert.doesNotMatch(html, /audit trail/i);
  assert.match(html, /Fe &lt;Admin&gt;/);
  assert.match(html, /<td>Verified<\/td>/);
  assert.match(html, /Approved by:<\/div>\s*<div class="rt-sig-name">&nbsp;<\/div>\s*<div class="rt-sig-role">Program Leader/);
  assert.doesNotMatch(html, /immutable system audit log/i);
  assert.doesNotMatch(html, /<script/);
});
