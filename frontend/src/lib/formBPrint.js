/**
 * The printable R&D Form B (Project Line-Item Budget) in the official
 * layout, rendered from the backend document (GET /api/projects/:id/form-b,
 * FormBReportService). Same layout for every funder: only the funder names
 * in the document change. Black and white, portrait, ruled like the form.
 */

/** "1,058,376.00"; zero prints as "-"; null (a heading row) is blank. */
export function formBAmount(value) {
  if (value === null || value === undefined || value === '') return '';
  const number = Number(value);
  if (!Number.isFinite(number) || Math.abs(number) < 0.005) return '-';
  return number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const defaultEscape = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#39;');

/** A4 or Legal (portrait), as chosen by the backend for one page. */
export function formBPaper(doc) {
  return doc?.paper === 'Legal' ? 'legal' : 'A4';
}

/** The whole print document (HTML string) for the browser's "Save as PDF". */
export function formBHtml(doc, escapeHtml = defaultEscape) {
  const e = escapeHtml;
  const legal = formBPaper(doc) === 'legal';
  const details = (doc.details ?? []).map((d) => `
    <tr><th scope="row">${e(d.label)}:</th><td>${e(d.value)}</td></tr>`).join('');
  const rows = (doc.rows ?? []).map((r) => `
    <tr class="k-${e(r.kind)}">
      <td style="padding-left:${6 + (Number(r.level) || 0) * 14}px">${e(r.label)}</td>
      <td class="r">${e(formBAmount(r.implementing_agency))}</td>
      <td class="r">${e(formBAmount(r.funder))}</td>
    </tr>`).join('');
  const [col0, col1, col2] = doc.columns ?? ['', '', ''];
  const footer = doc.footer ?? { title: '', columns: [], values: [] };
  const signatories = (doc.signatories ?? []).map((s) => `
    <div class="sig">
      <div class="sig-label">${e(s.label)}</div>
      <div class="sig-line"></div>
      ${s.name ? `<div class="sig-name">${e(s.name)}</div>` : ''}
      ${s.position ? `<div class="sig-pos">${e(s.position)}</div>` : ''}
    </div>`).join('');

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>${e(doc.header?.form)} - ${e(doc.details?.[0]?.value)}</title>
<style>
  @page { size: ${legal ? 'legal' : 'A4'} portrait; margin: 1.2cm 1.4cm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: ${legal ? '9.5pt' : '9pt'}; color: #000; background: #fff; margin: 0; line-height: 1.25; }
  .form-id { font-size: 8.5pt; font-style: italic; margin-bottom: 4px; }
  .head { text-align: center; margin-bottom: 10px; }
  .head div { font-weight: bold; }
  .head .agency { font-size: 11pt; }
  .head .year { font-weight: normal; }
  table { width: 100%; border-collapse: collapse; }
  .details { margin-bottom: 8px; }
  .details th { text-align: left; font-weight: bold; width: 22%; padding: 1px 6px 1px 0; vertical-align: top; }
  .details td { padding: 1px 0; border-bottom: 1px solid #000; }
  .lib { border: 1px solid #000; }
  .lib th { border: 1px solid #000; padding: 4px 6px; font-weight: bold; text-align: center; vertical-align: middle; }
  .lib td { border-left: 1px solid #000; border-right: 1px solid #000; padding: 1.5px 6px; vertical-align: top; }
  .lib col.amt { width: 20%; }
  .r { text-align: right; white-space: nowrap; }
  .k-section td { font-weight: bold; padding-top: 5px; }
  .k-cost td:first-child { font-weight: bold; }
  .k-group td:first-child, .k-subgroup td:first-child { font-style: italic; }
  .k-subtotal td { font-weight: bold; border-top: 1px solid #000; border-bottom: 1px solid #000; }
  .k-grand td { font-weight: bold; border-top: 3px double #000; border-bottom: 1px solid #000; padding-top: 3px; padding-bottom: 3px; }
  tr { page-break-inside: avoid; }
  .fill-row { margin-top: 12px; display: flex; align-items: flex-end; gap: 16px; }
  .fill { width: 60%; flex: none; }
  .fill-note { font-style: italic; padding-bottom: 4px; }
  .fill caption { text-align: left; font-style: italic; padding-bottom: 3px; }
  .fill th, .fill td { border: 1px solid #000; padding: 3px 6px; }
  .fill th { text-align: center; }
  .sigs { margin-top: 18px; display: grid; grid-template-columns: 1fr 1fr; column-gap: 40px; row-gap: 22px; page-break-inside: avoid; }
  .sig-label { margin-bottom: 26px; }
  .sig-line { border-bottom: 1px solid #000; width: 85%; }
  .sig-name { font-weight: bold; text-transform: uppercase; margin-top: 2px; }
  .sig-pos { margin-top: 1px; }
</style>
</head>
<body>
  <div class="form-id">${e(doc.header?.form)}</div>
  <div class="head">
    <div class="agency">${e(doc.header?.agency)}</div>
    <div>${e(doc.header?.title)}</div>
    <div class="year">${e(doc.header?.year)}</div>
  </div>
  <table class="details"><tbody>${details}</tbody></table>
  <table class="lib">
    <colgroup><col /><col class="amt" /><col class="amt" /></colgroup>
    <thead><tr><th scope="col">${e(col0)}</th><th scope="col">${e(col1)}</th><th scope="col">${e(col2)}</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>
  <div class="fill-row">
    <table class="fill">
      <caption>${e(footer.title)}</caption>
      <thead><tr>${(footer.columns ?? []).map((c) => `<th scope="col">${e(c)}</th>`).join('')}</tr></thead>
      <tbody><tr>${(footer.values ?? []).map((v) => `<td class="r">${e(formBAmount(v))}</td>`).join('')}</tr></tbody>
    </table>
    ${footer.note ? `<div class="fill-note">${e(footer.note)}</div>` : ''}
  </div>
  <div class="sigs">${signatories}</div>
</body>
</html>`;
}
