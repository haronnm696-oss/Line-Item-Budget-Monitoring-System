import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formBHtml } from './formBPrint.js';
import { canDownloadPurchaseRequest, PURCHASE_REQUEST_DOWNLOADS, purchaseRequestHtml, purchaseRequestTitle } from './purchaseRequestPrint.js';

// The MSU example as the backend returns it (PurchaseRequestFormService).
function doc(overrides = {}) {
  return {
    id: 12,
    project_code: 'RP-2026-001',
    title: 'PR - RP-2026-001 - 12',
    draft: false,
    header: { title: 'PURCHASE REQUEST', university: 'Mindanao State University', city: 'Marawi City' },
    office_unit: 'MSU-Marawi',
    department: 'Department of Chemistry, College of Natural Sciences and Mathematics',
    numbers: [
      { label: 'PR No.', value: null, date: null },
      { label: 'SAI No.', value: null, date: null },
      { label: 'ALOBS No.', value: null, date: null },
    ],
    columns: ['Item No.', 'Qty', 'Unit', 'Item Description', 'Estimated Unit Cost', 'Estimated Cost'],
    rows: [
      { item_no: 1, quantity: 2, unit: 'unit', description: 'Digital microscope', unit_cost: 40000, estimated_cost: 80000 },
      { item_no: 2, quantity: 2, unit: 'box', description: 'Specimen <containers>', unit_cost: 6000, estimated_cost: 12000 },
      { item_no: 3, quantity: 1, unit: 'lot', description: 'Transport of samples', unit_cost: 13500, estimated_cost: 13500 },
    ],
    total: 105500,
    purpose: 'Laboratory set-up for the field study',
    chargeable: 'CHARGEABLE AGAINST MOST-BARMM FUNDED PROJECT',
    project_title: 'Field study of Lake Lanao',
    signatories: [
      { label: 'Requested by:', name: 'Demo Project Leader', position: null },
      { label: 'Noted by:', name: null, position: null },
      { label: 'Approved by:', name: null, position: null },
    ],
    ...overrides,
  };
}

test('the 2 x 40,000 + 2 x 6,000 + 1 x 13,500 example prints its rows and 105,500.00', () => {
  const html = purchaseRequestHtml(doc());
  for (const amount of ['40,000.00', '80,000.00', '6,000.00', '12,000.00', '13,500.00']) {
    assert.ok(html.includes(`<td class="r">${amount}</td>`), `missing ${amount}`);
  }
  assert.ok(html.includes('<tr class="total"><td colspan="5" class="r">TOTAL</td><td class="r">105,500.00</td></tr>'));
  for (const column of ['Item No.', 'Qty', 'Unit', 'Item Description', 'Estimated Unit Cost', 'Estimated Cost']) {
    assert.ok(html.includes(`<th scope="col">${column}</th>`), `missing column ${column}`);
  }
  assert.ok(html.includes('PURCHASE REQUEST') && html.includes('Mindanao State University') && html.includes('Marawi City'));
  assert.ok(html.includes('CHARGEABLE AGAINST MOST-BARMM FUNDED PROJECT'));
  assert.ok(html.includes('Field study of Lake Lanao'));
  assert.ok(html.includes('Specimen &lt;containers&gt;'), 'values are escaped');
});

test('PR, SAI and ALOBS print blank lines when not set, values when set', () => {
  const blank = purchaseRequestHtml(doc());
  assert.equal(blank.match(/<td class="line">&nbsp;<\/td>/g).length, 6);
  const set = purchaseRequestHtml(doc({ numbers: [{ label: 'PR No.', value: '2026-11-001', date: 'November 20, 2026' }, { label: 'SAI No.', value: null, date: null }, { label: 'ALOBS No.', value: null, date: null }] }));
  assert.ok(set.includes('<td class="line">2026-11-001</td>'));
  assert.ok(set.includes('<td class="line">November 20, 2026</td>'));
});

test('Requested by is the Project Leader; signatories print only when set', () => {
  const html = purchaseRequestHtml(doc());
  assert.ok(html.includes('Requested by:') && html.includes('Noted by:') && html.includes('Approved by:'));
  assert.equal(html.match(/<div class="sig-line"><\/div>/g).length, 3, 'a blank signature line each');
  assert.equal(html.match(/<div class="sig-name">/g).length, 1);
  assert.ok(html.includes('<div class="sig-name">Demo Project Leader</div>'));
  assert.ok(!html.includes('<div class="sig-pos">'));
  const set = purchaseRequestHtml(doc({ signatories: [
    { label: 'Requested by:', name: 'Demo Project Leader', position: 'Project Leader' },
    { label: 'Noted by:', name: 'Noter Placeholder', position: 'Department Chair' },
    { label: 'Approved by:', name: null, position: null },
  ] }));
  assert.ok(set.includes('<div class="sig-name">Noter Placeholder</div>'));
  assert.ok(set.includes('<div class="sig-pos">Department Chair</div>'));
  assert.equal(set.match(/<div class="sig-name">/g).length, 2);
});

test('DRAFT watermark on drafts and waiting requests only', () => {
  assert.ok(purchaseRequestHtml(doc({ draft: true })).includes('<div class="watermark" aria-hidden="true"><span>DRAFT</span></div>'));
  assert.ok(!purchaseRequestHtml(doc({ draft: false })).includes('class="watermark"'));
});

test('same print setup as Form B: margin 0, own margins, black and white', () => {
  const html = purchaseRequestHtml(doc({ draft: true }));
  assert.match(html, /@page \{ size: A4 portrait; margin: 0; \}/);
  assert.match(html, /body \{[^}]*padding: 0 1\.4cm;/);
  assert.match(html, /<thead><tr><td><\/td><\/tr><\/thead>/);
  assert.match(html, /<title>PR - RP-2026-001 - 12<\/title>/);
  const colors = html.match(/#[0-9a-f]{3,6}\b/gi) ?? [];
  assert.deepEqual([...new Set(colors.map((c) => c.toLowerCase()))].sort(), ['#000', '#fff'], 'black and white only');
  // One shared shell: Form B starts with the very same print setup.
  const head = (text) => text.slice(text.indexOf('@page'), text.indexOf('.watermark span'));
  const formB = formBHtml({ header: {}, details: [], columns: [], rows: [], footer: {}, signatories: [], paper: 'A4' });
  assert.equal(head(html), head(formB));
});

test('title: the PR No. or the id, and the download menu formats', () => {
  assert.equal(purchaseRequestTitle({ title: 'PR - RP-2026-001 - 2026-11-001' }), 'PR - RP-2026-001 - 2026-11-001');
  assert.equal(purchaseRequestTitle({ project_code: 'RP-2026-001', id: 7 }), 'PR - RP-2026-001 - 7');
  assert.deepEqual(PURCHASE_REQUEST_DOWNLOADS.map((option) => option.key), ['pdf', 'csv']);
});

test('Download is shown to RA, PL, FIN and the Program Leader, never Researchers', () => {
  for (const role of ['RA', 'PL', 'FIN', 'PRG']) assert.equal(canDownloadPurchaseRequest(role), true, role);
  assert.equal(canDownloadPurchaseRequest('RES'), false);
  assert.equal(canDownloadPurchaseRequest(undefined), false);
});
