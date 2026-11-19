import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formBAmount, formBHtml, formBPaper } from './formBPrint.js';

// A Form B document as the backend returns it (FormBReportService), for any funder.
function doc(short, name, signatories = []) {
  return {
    header: { form: `${short} R&D Form B`, agency: name.toUpperCase(), title: 'Project Line-Item Budget', year: 'CY 2026' },
    details: [
      { label: 'Project Title', value: 'Demo <Project>' },
      { label: 'Implementing Agency', value: 'MSU' },
      { label: 'Total Duration', value: 'Twelve (12) months' },
      { label: 'Project Leader', value: 'Demo Project Leader' },
      { label: 'Monitoring Agency', value: name },
    ],
    columns: ['', 'Counterpart Funding: Implementing Agency', short],
    rows: [
      { kind: 'section', label: 'I. PERSONNEL SERVICES (PS)', level: 0, implementing_agency: null, funder: null },
      { kind: 'group', label: 'Honoraria', level: 2, implementing_agency: null, funder: null },
      { kind: 'item', label: 'One (1) Project Leader @ P8,800.00/mo. x 12 mos.', level: 3, implementing_agency: 254754, funder: 105600 },
      { kind: 'item', label: 'Two (2) Support Staff @ P1,500.00/qtr. x 4 qtrs.', level: 3, implementing_agency: 0, funder: 12000 },
      { kind: 'subtotal', label: 'Sub-total for PS', level: 0, implementing_agency: 1058376, funder: 351600 },
      { kind: 'subtotal', label: 'Sub-Total for MOOE', level: 0, implementing_agency: 120000, funder: 1648400 },
      { kind: 'grand', label: 'GRAND TOTAL', level: 0, implementing_agency: 1178376, funder: 2000000 },
    ],
    footer: { title: `(To be filled-up by ${short})`, columns: ['Implementing', short, 'TOTAL'], values: [1178376, 2000000, 3178376], note: '* Chargeable against the CY 2026' },
    signatories: [
      { label: 'Reviewed by:', name: null, position: null },
      { label: 'Certified Funds Available:', name: null, position: null },
      { label: 'Recommending Approval:', name: null, position: null },
      { label: 'Approved:', name: null, position: null },
    ].map((s, i) => ({ ...s, ...(signatories[i] ?? {}) })),
    paper: 'A4',
  };
}

test('amounts: zero prints "-", headings are blank, others have two decimals', () => {
  assert.equal(formBAmount(0), '-');
  assert.equal(formBAmount(null), '');
  assert.equal(formBAmount(1058376), '1,058,376.00');
  assert.equal(formBAmount(3178376), '3,178,376.00');
});

test('the demo totals print in the official layout', () => {
  const html = formBHtml(doc('MOST-BARMM', 'Ministry of Science and Technology - BARMM'));
  for (const total of ['1,058,376.00', '351,600.00', '120,000.00', '1,648,400.00', '1,178,376.00', '2,000,000.00', '3,178,376.00']) {
    assert.ok(html.includes(total), `missing ${total}`);
  }
  assert.ok(html.includes('MOST-BARMM R&amp;D Form B'));
  assert.ok(html.includes('MINISTRY OF SCIENCE AND TECHNOLOGY - BARMM'));
  assert.ok(html.includes('One (1) Project Leader @ P8,800.00/mo. x 12 mos.'));
  assert.ok(html.includes('<td class="r">-</td>'), 'zero cell prints "-"');
  assert.ok(html.includes('<td class="r">3,178,376.00</td>'), 'the filled-up box is pre-filled');
  assert.ok(html.includes('<div class="fill-note">* Chargeable against the CY 2026</div>'));
  assert.ok(html.includes('size: A4 portrait'));
  assert.ok(html.includes('Demo &lt;Project&gt;'), 'values are escaped');
  const colors = html.match(/#[0-9a-f]{3,6}\b/gi) ?? [];
  assert.deepEqual([...new Set(colors.map((c) => c.toLowerCase()))].sort(), ['#000', '#fff'], 'black and white only');
});

test('signatories print only when set (labels and lines always)', () => {
  const blank = formBHtml(doc('DOST', 'Department of Science and Technology'));
  assert.ok(blank.includes('Reviewed by:') && blank.includes('Approved:'));
  assert.ok(!blank.includes('<div class="sig-name">'));
  const set = formBHtml(doc('DOST', 'Department of Science and Technology', [{ name: 'Reviewer Placeholder', position: 'Budget Officer' }]));
  assert.ok(set.includes('<div class="sig-name">Reviewer Placeholder</div>'));
  assert.ok(set.includes('<div class="sig-pos">Budget Officer</div>'));
  assert.equal(set.match(/<div class="sig-name">/g).length, 1);
});

test('a DOST project shows DOST in title, column and footer and never MOST', () => {
  const html = formBHtml(doc('DOST', 'Department of Science and Technology'));
  assert.ok(html.includes('DOST R&amp;D Form B'));
  assert.ok(html.includes('<th scope="col">DOST</th>'));
  assert.ok(html.includes('(To be filled-up by DOST)'));
  assert.ok(!html.includes('MOST'));
});

test('Legal paper when the backend says the form needs it', () => {
  assert.equal(formBPaper({ paper: 'Legal' }), 'legal');
  assert.equal(formBPaper({ paper: 'A4' }), 'A4');
  assert.ok(formBHtml({ ...doc('DOST', 'Department of Science and Technology'), paper: 'Legal' }).includes('size: legal portrait'));
});
