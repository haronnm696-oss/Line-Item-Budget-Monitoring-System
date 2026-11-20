import { numberWords } from './lineItemComputation.js';

/*
 * Project set-up from the Memorandum of Agreement (MOA): the project period
 * in words ("Twelve (12) months", the same wording as R&D Form B, built by
 * FormBReportService::duration on the server), the period check, and the
 * MOA lines shown under the project title.
 */

const ISO = /^(\d{4})-(\d{2})-(\d{2})$/;

function parse(text) {
  const match = ISO.exec(String(text ?? ''));
  if (!match) return null;
  return { y: Number(match[1]), m: Number(match[2]) - 1, d: Number(match[3]) };
}

const daysIn = (y, m) => new Date(Date.UTC(y, m + 1, 0)).getUTCDate();
const stamp = ({ y, m, d }) => Date.UTC(y, m, d);

/** The date `months` later, kept inside the target month (31 Jan + 1 = 28/29 Feb). */
function addMonths({ y, m, d }, months) {
  const total = y * 12 + m + months;
  const ty = Math.floor(total / 12);
  const tm = total % 12;
  return { y: ty, m: tm, d: Math.min(d, daysIn(ty, tm)) };
}

/**
 * "Twelve (12) months" for a period (whole months, rounded up; the end date
 * counts as a full day), or '' when a date is missing or the order is wrong.
 */
export function durationInWords(start, end) {
  const from = parse(start);
  const to = parse(end);
  if (!from || !to || stamp(to) < stamp(from)) return '';
  const next = new Date(stamp(to) + 86400000);
  const until = { y: next.getUTCFullYear(), m: next.getUTCMonth(), d: next.getUTCDate() };

  let months = (until.y - from.y) * 12 + (until.m - from.m);
  if (stamp(addMonths(from, months)) > stamp(until)) months -= 1;
  if (stamp(addMonths(from, months)) < stamp(until)) months += 1;
  months = Math.max(1, months);

  const words = numberWords(months);
  return `${words.charAt(0).toUpperCase()}${words.slice(1)} (${months}) ${months === 1 ? 'month' : 'months'}`;
}

/** Field errors for the project period ({} when fine). */
export function periodErrors(start, end) {
  const errors = {};
  if (!start) errors.start_date = 'Enter the start date.';
  if (!end) errors.end_date = 'Enter the end date.';
  else if (start && end <= start) errors.end_date = 'The end date must be after the start date.';
  return errors;
}

/**
 * Lines under the project title, from `project.agreement`; only the parts
 * that are set. `formatDate` formats ISO dates for display.
 */
export function agreementLines(agreement, formatDate) {
  if (!agreement) return [];
  const lines = [];
  const moa = [agreement.moa_number, agreement.moa_date ? `dated ${formatDate(agreement.moa_date)}` : null].filter(Boolean);
  if (moa.length) lines.push(`MOA ${moa.join(', ')}`);
  if (agreement.rd_committee_approved_date) lines.push(`Approved by the funder's R&D committee on ${formatDate(agreement.rd_committee_approved_date)}`);
  if (agreement.fund_source) lines.push(`Fund source: ${agreement.fund_source}`);
  if (agreement.duration) lines.push(`Duration: ${agreement.duration}`);
  return lines;
}

/** Project document types (as on the server, Project::DOCUMENT_TYPES). */
export const PROJECT_DOCUMENT_TYPES = [
  ['moa', 'Memorandum of Agreement (MOA)'],
  ['form_a', 'Form A (proposal)'],
  ['form_b', 'Form B (line-item budget)'],
  ['form_c', 'Form C (work plan)'],
  ['other', 'Other'],
];
