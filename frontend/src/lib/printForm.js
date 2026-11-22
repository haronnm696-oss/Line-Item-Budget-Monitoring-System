/**
 * The print setup every official form shares (R&D Form B, the MSU Purchase
 * Request): portrait, black and white, page margin 0 so the browser prints
 * no date/title/URL/page headers and footers, the form's own margins kept by
 * body padding and spacer rows that repeat on every printed page, and the
 * signature blocks (label, blank line, name and position only when set).
 * Printed with lib/download.js printHtmlDocument ("Save as PDF").
 */

/** "1,058,376.00"; zero prints as "-"; null (a heading row) is blank. */
export function formAmount(value) {
  if (value === null || value === undefined || value === '') return '';
  const number = Number(value);
  if (!Number.isFinite(number) || Math.abs(number) < 0.005) return '-';
  return number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Every value interpolated into a print document goes through this (user input). */
export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** The form's own margins; the page margin is 0 so the browser prints no headers/footers. */
export const FORM_MARGIN = { top: '1.2cm', side: '1.4cm' };

/** Signature blocks: label, a blank signature line, then the name and position when set. */
export function signatoriesHtml(signatories, e = escapeHtml) {
  return (signatories ?? []).map((s) => `
    <div class="sig">
      <div class="sig-label">${e(s.label)}</div>
      <div class="sig-line"></div>
      ${s.name ? `<div class="sig-name">${e(s.name)}</div>` : ''}
      ${s.position ? `<div class="sig-pos">${e(s.position)}</div>` : ''}
    </div>`).join('');
}

/**
 * The whole print document (HTML string). `paper` is 'A4' or 'legal';
 * `css` is the form's own styles; `body` its markup; `watermark` (e.g.
 * "DRAFT") is printed across every page, in black at low opacity.
 */
export function printFormHtml({ title, paper = 'A4', fontSize = '9pt', css = '', body, watermark = null }, e = escapeHtml) {
  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>${e(title)}</title>
<style>
  /* Margin 0: Chrome and Edge then have no room for their date/title/URL/page
     headers and footers. The form keeps its own margins: side padding on the
     body, and top/bottom spacer rows that repeat on every printed page. */
  @page { size: ${paper} portrait; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: ${fontSize}; color: #000; background: #fff; padding: 0 ${FORM_MARGIN.side}; line-height: 1.25; }
  .page { width: 100%; border-collapse: collapse; }
  .page > thead > tr > td, .page > tfoot > tr > td { height: ${FORM_MARGIN.top}; padding: 0; border: 0; }
  .page > tbody > tr > td { padding: 0; border: 0; }
  table { width: 100%; border-collapse: collapse; }
  .r { text-align: right; white-space: nowrap; }
  tr { page-break-inside: avoid; }
  .sig-label { margin-bottom: 26px; }
  .sig-line { border-bottom: 1px solid #000; width: 85%; }
  .sig-name { font-weight: bold; text-transform: uppercase; margin-top: 2px; }
  .sig-pos { margin-top: 1px; }
  .watermark { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; z-index: 10; }
  .watermark span { font-size: 120pt; font-weight: bold; letter-spacing: 12px; color: #000; opacity: 0.12; transform: rotate(-35deg); }
${css}
</style>
</head>
<body>
${watermark ? `<div class="watermark" aria-hidden="true"><span>${e(watermark)}</span></div>` : ''}
<table class="page" role="presentation">
<thead><tr><td></td></tr></thead>
<tfoot><tr><td></td></tr></tfoot>
<tbody><tr><td>
${body}
</td></tr></tbody>
</table>
</body>
</html>`;
}
