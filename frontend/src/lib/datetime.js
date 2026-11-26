// Dates and times for the Program Leader pages.
//
// The API stores and returns timestamps in UTC with an explicit offset
// (ISO 8601). They are always shown in Philippine time, whatever time zone
// the viewer's computer is set to. Calendar dates ("2026-11-10") are not
// moments in time and are never shifted.

export const PH_TIME_ZONE = 'Asia/Manila';
export const PH_TIME_LABEL = 'Philippine time (UTC+8)';

const LOCALE = 'en-PH';
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
const MONTH_ONLY = /^\d{4}-\d{2}$/;

function isCalendarDate(value) {
  return typeof value === 'string' && (DATE_ONLY.test(value) || MONTH_ONLY.test(value));
}

function toDate(value) {
  if (value === null || value === undefined || value === '') return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const text = String(value);
  // Calendar dates are read as UTC midnight and formatted in UTC, so the
  // day never moves.
  const date = DATE_ONLY.test(text)
    ? new Date(`${text}T00:00:00Z`)
    : MONTH_ONLY.test(text) ? new Date(`${text}-01T00:00:00Z`) : new Date(text);
  return Number.isNaN(date.getTime()) ? null : date;
}

function format(value, options, fallback) {
  const date = toDate(value);
  if (!date) return fallback;
  const timeZone = isCalendarDate(value) ? 'UTC' : PH_TIME_ZONE;
  return new Intl.DateTimeFormat(LOCALE, { timeZone, ...options }).format(date);
}

/** "Nov 14, 2026, 10:30 AM" in Philippine time. */
export function formatDateTime(value, fallback = '—') {
  return format(value, {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
  }, fallback);
}

/**
 * Short form for compact lists: "Nov 26, 10:55 AM" in Philippine time.
 * The year is added only when it is not the current Philippine year.
 */
export function formatShortDateTime(value, fallback = '—', now = new Date()) {
  const date = toDate(value);
  if (!date) return fallback;
  const year = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: PH_TIME_ZONE, year: 'numeric' }).format(d);
  return format(value, {
    month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
    ...(year(date) === year(now) ? {} : { year: 'numeric' }),
  }, fallback);
}

/** "Nov 14, 2026". Timestamps use the Philippine date; calendar dates stay as they are. */
export function formatDate(value, fallback = '—', options = { month: 'short', day: 'numeric', year: 'numeric' }) {
  return format(value, options, fallback);
}

/** Any Intl.DateTimeFormat options, in Philippine time (calendar dates unshifted). */
export function formatInManila(value, options, fallback = '—') {
  return format(value, options, fallback);
}

/** Today's date in the Philippines as "YYYY-MM-DD". */
export function todayInManila() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: PH_TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date());
  return parts;
}
