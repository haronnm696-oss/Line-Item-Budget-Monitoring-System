/**
 * One purchase request printed in MSU's Purchase Request form, rendered from
 * the backend document (GET /api/purchase-requests/:id/form,
 * PurchaseRequestFormService). Same print setup as Form B (lib/printForm.js):
 * portrait A4, black and white, ruled like the form, no browser headers or
 * footers. A request not approved yet prints with a DRAFT watermark.
 */

import { escapeHtml as defaultEscape, formAmount, printFormHtml, signatoriesHtml } from './printForm.js';

/** "PR - RP-2026-001 - 2026-11-001": the print title (the saved PDF's file name). */
export function purchaseRequestTitle(doc) {
  if (doc?.title) return doc.title;
  const code = String(doc?.project_code ?? '').trim();
  return ['PR', code, doc?.id].filter((part) => part !== undefined && part !== null && part !== '').join(' - ');
}

/** The "Download" menu formats of a purchase request: PDF then CSV, like the reports. */
export const PURCHASE_REQUEST_DOWNLOADS = [
  { key: 'pdf', label: 'Purchase Request (PDF)' },
  { key: 'csv', label: 'CSV' },
];

/** Who sees "Download" on a purchase request; Researchers do not (the API answers 403). */
export function canDownloadPurchaseRequest(roleCode) {
  return ['RA', 'PL', 'FIN', 'PRG'].includes(roleCode);
}

/** The whole print document (HTML string) for the browser's "Save as PDF". */
export function purchaseRequestHtml(doc, escapeHtml = defaultEscape) {
  const e = escapeHtml;
  const blank = (value) => (value ? e(value) : '&nbsp;');
  const numbers = (doc.numbers ?? []).map((n) => `
    <tr>
      <th scope="row">${e(n.label)}</th><td class="line">${blank(n.value)}</td>
      <th scope="row" class="date-label">Date:</th><td class="line">${blank(n.date)}</td>
    </tr>`).join('');
  const columns = (doc.columns ?? []).map((c) => `<th scope="col">${e(c)}</th>`).join('');
  const rows = (doc.rows ?? []).map((r) => `
    <tr>
      <td class="c">${e(r.item_no)}</td>
      <td class="c">${e(r.quantity)}</td>
      <td class="c">${e(r.unit)}</td>
      <td>${e(r.description)}</td>
      <td class="r">${e(formAmount(r.unit_cost))}</td>
      <td class="r">${e(formAmount(r.estimated_cost))}</td>
    </tr>`).join('');

  const css = `
  .head { text-align: center; margin-bottom: 12px; }
  .head .title { font-size: 13pt; font-weight: bold; letter-spacing: 1px; }
  .head .uni { font-weight: bold; margin-top: 2px; }
  .info { display: grid; grid-template-columns: 1fr 1fr; column-gap: 24px; margin-bottom: 8px; }
  .info th { text-align: left; font-weight: bold; white-space: nowrap; padding: 2px 6px 2px 0; width: 1%; }
  .info td { padding: 2px 0; }
  .info .line { border-bottom: 1px solid #000; }
  .info .date-label { padding-left: 10px; }
  .items { border: 1px solid #000; }
  .items th { border: 1px solid #000; padding: 4px 6px; font-weight: bold; text-align: center; vertical-align: middle; }
  .items td { border: 1px solid #000; padding: 3px 6px; vertical-align: top; }
  .items .c { text-align: center; }
  .items col.no { width: 7%; } .items col.qty { width: 7%; } .items col.unit { width: 8%; }
  .items col.amt { width: 16%; }
  .items .total td { font-weight: bold; border-top: 3px double #000; }
  .purpose { border: 1px solid #000; border-top: 0; padding: 6px; }
  .purpose .label { font-weight: bold; }
  .charge { margin-top: 8px; text-align: center; font-weight: bold; }
  .project { text-align: center; font-style: italic; margin-top: 2px; }
  .sigs { margin-top: 22px; display: grid; grid-template-columns: 1fr 1fr 1fr; column-gap: 28px; page-break-inside: avoid; }`;

  const body = `
  <div class="head">
    <div class="title">${e(doc.header?.title)}</div>
    <div class="uni">${e(doc.header?.university)}</div>
    <div>${e(doc.header?.city)}</div>
  </div>
  <div class="info">
    <table><tbody>
      <tr><th scope="row">Office/Unit:</th><td class="line">${blank(doc.office_unit)}</td></tr>
      <tr><th scope="row">Department:</th><td class="line">${blank(doc.department)}</td></tr>
    </tbody></table>
    <table><tbody>${numbers}</tbody></table>
  </div>
  <table class="items">
    <colgroup><col class="no" /><col class="qty" /><col class="unit" /><col /><col class="amt" /><col class="amt" /></colgroup>
    <thead><tr>${columns}</tr></thead>
    <tbody>${rows}
      <tr class="total"><td colspan="5" class="r">TOTAL</td><td class="r">${e(formAmount(doc.total))}</td></tr>
    </tbody>
  </table>
  <div class="purpose"><span class="label">Purpose/Remarks:</span> ${e(doc.purpose)}</div>
  <div class="charge">${e(doc.chargeable)}</div>
  <div class="project">${e(doc.project_title)}</div>
  <div class="sigs">${signatoriesHtml(doc.signatories, e)}</div>`;

  return printFormHtml({
    title: purchaseRequestTitle(doc),
    paper: 'A4',
    css,
    body,
    watermark: doc.draft ? 'DRAFT' : null,
  }, e);
}
