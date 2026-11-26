/**
 * The one official layout of every printed report (Expense Ledger,
 * Utilization, Payroll register, Monthly summary, Executive report, Audit
 * trail): MSU seal and OVCRED logo letterhead with a double maroon rule, the
 * title and filters, a label : value info block, bordered tables (header row
 * repeated on each page, amounts without "₱" per cell, bold TOTAL row), a
 * signature block kept on one page, and "LIB Monitoring System · Generated …"
 * / "Page X of Y" on every page. Form B keeps its own funder layout
 * (formBPrint.js). Printed with lib/download.js printHtmlDocument.
 */
import { formatInManila } from './datetime.js';
import { escapeHtml } from './printForm.js';

export const REPORT_ORG = {
  republic: 'Republic of the Philippines',
  university: 'MINDANAO STATE UNIVERSITY',
  campus: 'Main Campus, Marawi City',
  office: 'Office of the Vice Chancellor for Research and Extension',
};

/** The original logo files (frontend/public/images). */
export const REPORT_LOGOS = {
  seal: { src: '/images/msu-seal.png', alt: 'Mindanao State University seal' },
  ovcred: { src: '/images/client-logo.png', alt: 'OVCRED logo' },
};

const BRAND = '#6F1D2A';
const HEAD_TINT = '#F3E4E6';

/** "1,234,567.00" (no peso sign: the column header carries "(₱)"). */
export function reportAmount(value) {
  const number = Number(value) || 0;
  return number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** "₱1,234,567.00", for amounts inside sentences (notes). */
export function reportPeso(value) {
  return `₱${reportAmount(value)}`;
}

/** "November 26, 2026, 3:04 PM" in Philippine time. */
export function reportGeneratedText(date = new Date()) {
  return formatInManila(date, {
    month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  });
}

/** Info block rows of a project report. */
export function projectInfo(project, generatedText) {
  const p = project ?? {};
  return [
    ['Project Title', p.title],
    ['Project Code', p.code],
    ['Project Leader', p.project_leader],
    ['Funding Agency', p.funding_agency],
    ['Implementing Agency', p.implementing_agency],
    ['Date Generated', generatedText],
  ];
}

/**
 * Project reports: Prepared by (the signed-in user), Certified correct (the
 * project's Finance/Admin), Noted by (the Project Leader). A missing name
 * prints as a blank line with the role under it.
 */
export function projectSignatories({ preparedBy, financeAdmin, projectLeader } = {}) {
  return [
    { label: 'Prepared by:', name: preparedBy?.name, role: preparedBy?.role },
    { label: 'Certified correct:', name: financeAdmin, role: 'Finance/Admin Staff' },
    { label: 'Noted by:', name: projectLeader, role: 'Project Leader' },
  ];
}

/** Program-wide reports: Prepared by (the signed-in user), Approved by (the Program Leader). */
export function programSignatories({ preparedBy, programLeader } = {}) {
  return [
    { label: 'Prepared by:', name: preparedBy?.name, role: preparedBy?.role },
    { label: 'Approved by:', name: programLeader, role: 'Program Leader' },
  ];
}

/** A CSS string literal (the page footer text in @page margin boxes). */
function cssString(value) {
  return `"${String(value ?? '').replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]+/g, ' ')}"`;
}

function defaultAssetBase() {
  return globalThis.location?.origin ?? '';
}

/**
 * A bordered table. columns: [{ label, amount? }] (amount columns are
 * right-aligned, "(₱)" in the header); rows: [{ cells: [...], kind?:
 * 'subtotal' | 'total' }]. Amount cells are numbers, formatted here; a cell
 * may be { text, span } (spans columns: the next span-1 cells are skipped)
 * or { text, right: true } (right-aligned text).
 */
export function reportTableHtml({ columns, rows, empty = 'No records for these filters.' }, e = escapeHtml) {
  const head = columns.map((c) => `<th${c.amount ? ' class="r"' : ''}>${e(c.amount ? `${c.label} (₱)` : c.label)}</th>`).join('');
  const body = rows.length === 0
    ? `<tr><td class="rt-empty" colspan="${columns.length}">${e(empty)}</td></tr>`
    : rows.map((row) => {
      const cells = [];
      for (let i = 0; i < columns.length; i += 1) {
        const value = row.cells[i];
        if (value && typeof value === 'object') {
          // { text, span?, right? }: a cell over `span` columns, or right-aligned text.
          const span = value.span > 1 ? ` colspan="${value.span}"` : '';
          cells.push(`<td${span}${value.right ? ' class="r"' : ''}>${e(value.text)}</td>`);
          i += (value.span > 1 ? value.span : 1) - 1;
        } else if (columns[i].amount) {
          cells.push(`<td class="r">${value === null || value === undefined ? '' : reportAmount(value)}</td>`);
        } else {
          cells.push(`<td>${e(value)}</td>`);
        }
      }
      return `<tr${row.kind ? ` class="rt-${row.kind}"` : ''}>${cells.join('')}</tr>`;
    }).join('');
  return `<table class="rt-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
}

/** An italic note under a table. */
export function reportNoteHtml(text, e = escapeHtml) {
  return text ? `<p class="rt-note">${e(text)}</p>` : '';
}

function signaturesHtml(signatories, e) {
  if (!signatories?.length) return '';
  const blocks = signatories.map((s) => `
    <div class="rt-sig">
      <div class="rt-sig-label">${e(s.label)}</div>
      <div class="rt-sig-name">${s.name ? e(s.name) : '&nbsp;'}</div>
      <div class="rt-sig-role">${e(s.role ?? '')}</div>
    </div>`).join('');
  return `<section class="rt-sigs" style="grid-template-columns: repeat(${signatories.length}, 1fr)">${blocks}</section>`;
}

function infoHtml(info, e) {
  if (!info?.length) return '';
  const rows = info.map(([label, value]) => `
    <div class="rt-info-row"><span class="rt-info-label">${e(label)}</span><span>:</span><span class="rt-info-value">${e(value ?? '') || '—'}</span></div>`).join('');
  return `<section class="rt-info">${rows}</section>`;
}

/**
 * The whole print document (HTML string).
 *   title       report title ("Expense Ledger"; printed uppercase)
 *   filters     one line under the title ("Period: All dates · Category: …")
 *   info        [[label, value]] for the 2-column info block
 *   body        the report's tables and notes (HTML)
 *   signatories [{ label, name, role }] (projectSignatories / programSignatories)
 *   landscape   A4 landscape (only reports that need it)
 *   docTitle    the browser/PDF file title (defaults to the title)
 * The page footer is "LIB Monitoring System" (left) and "Page X of Y"
 * (right); the generation date is the info block's "Date Generated".
 */
export function reportDocumentHtml({
  title, filters = '', info = [], body = '', signatories = [], landscape = false,
  docTitle, assetBase = defaultAssetBase(),
}, e = escapeHtml) {
  const footer = 'LIB Monitoring System';
  const footerFont = "font-family: 'Times New Roman', Times, Georgia, serif; font-size: 9pt; color: #333;";
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>${e(docTitle ?? title)}</title>
<style>
  @page {
    size: A4 ${landscape ? 'landscape' : 'portrait'};
    margin: 14mm 14mm 16mm;
    @bottom-left { content: ${cssString(footer)}; ${footerFont} }
    @bottom-right { content: "Page " counter(page) " of " counter(pages); ${footerFont} }
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; background: #fff; }
  body { font-family: 'Times New Roman', Times, Georgia, serif; font-size: 11pt; color: #000; line-height: 1.3; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .rt-head { display: grid; grid-template-columns: 70px 1fr 70px; align-items: center; gap: 12px; }
  .rt-head img { width: 70px; height: 70px; object-fit: contain; display: block; }
  .rt-org { text-align: center; line-height: 1.25; }
  .rt-org .rt-uni { font-weight: bold; font-size: 14pt; letter-spacing: 0.02em; }
  .rt-org .rt-office { font-weight: bold; }
  .rt-rule { border: 0; border-top: 4px double ${BRAND}; margin: 8px 0 14px; height: 0; }
  .rt-title { text-align: center; font-weight: bold; text-transform: uppercase; letter-spacing: 0.14em; font-size: 13pt; margin: 0; }
  .rt-filters { text-align: center; margin: 3px 0 12px; }
  .rt-info { display: grid; grid-template-columns: 1fr 1fr; column-gap: 24px; row-gap: 2px; margin-bottom: 14px; }
  .rt-info-row { display: grid; grid-template-columns: 9.5em 0.8em 1fr; }
  .rt-info-label { font-weight: bold; }
  .rt-section-title { font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; margin: 14px 0 6px; font-size: 11pt; }
  .rt-table { width: 100%; border-collapse: collapse; font-size: 10.5pt; }
  .rt-table thead { display: table-header-group; }
  .rt-table th, .rt-table td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; text-align: left; }
  .rt-table th { background: ${HEAD_TINT}; font-weight: bold; text-align: center; vertical-align: middle; }
  .rt-table th.r, .rt-table td.r { text-align: right; white-space: nowrap; }
  .rt-table tr { break-inside: avoid; page-break-inside: avoid; }
  .rt-table tr.rt-subtotal td { font-weight: bold; }
  .rt-table tr.rt-total td { font-weight: bold; border-top: 2px solid #000; }
  .rt-table td.rt-empty { text-align: center; font-style: italic; }
  .rt-note { font-style: italic; margin: 6px 0 0; }
  .rt-sigs { display: grid; column-gap: 28px; margin-top: 36px; break-inside: avoid; page-break-inside: avoid; }
  .rt-sig-label { margin-bottom: 30px; }
  .rt-sig-name { font-weight: bold; text-transform: uppercase; border-bottom: 1px solid #000; min-height: 1.4em; padding: 0 2px 1px; text-align: center; }
  .rt-sig-role { margin-top: 2px; text-align: center; }
</style>
</head>
<body>
<header class="rt-head">
  <img src="${e(assetBase + REPORT_LOGOS.seal.src)}" alt="${e(REPORT_LOGOS.seal.alt)}" />
  <div class="rt-org">
    <div>${e(REPORT_ORG.republic)}</div>
    <div class="rt-uni">${e(REPORT_ORG.university)}</div>
    <div>${e(REPORT_ORG.campus)}</div>
    <div class="rt-office">${e(REPORT_ORG.office)}</div>
  </div>
  <img src="${e(assetBase + REPORT_LOGOS.ovcred.src)}" alt="${e(REPORT_LOGOS.ovcred.alt)}" />
</header>
<hr class="rt-rule" />
<h1 class="rt-title">${e(title)}</h1>
${filters ? `<div class="rt-filters">${e(filters)}</div>` : ''}
${infoHtml(info, e)}
${body}
${signaturesHtml(signatories, e)}
</body>
</html>`;
}
