// The top-bar greeting: "Good morning, Aminah!" in Philippine time.

import { PH_TIME_ZONE } from './datetime.js';

// Titles and honorifics that come before a name and are not the first name.
const TITLES = new Set([
  'dr', 'engr', 'prof', 'atty', 'arch', 'mr', 'mrs', 'ms', 'miss', 'sir', 'madam', 'hon', 'rev', 'fr', 'sr',
]);

/** Hour (0-23) and minute in Asia/Manila for a moment in time. */
export function manilaClock(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: PH_TIME_ZONE, hour: 'numeric', minute: 'numeric', second: 'numeric', hourCycle: 'h23',
  }).formatToParts(date);
  const read = (type) => Number(parts.find((part) => part.type === type)?.value ?? 0);
  return { hour: read('hour') % 24, minute: read('minute'), second: read('second') };
}

/** "Good morning" before 12:00, "Good afternoon" 12:00-17:59, "Good evening" from 18:00 (Manila). */
export function greetingFor(date = new Date()) {
  const { hour } = manilaClock(date);
  if (hour < 12) return 'Good morning';
  if (hour < 18) return 'Good afternoon';
  return 'Good evening';
}

/** First name without titles: "Dr. Aminah Macarambon" -> "Aminah", "Engr Abdul Ali" -> "Abdul". */
export function firstNameOf(name) {
  const words = String(name ?? '').trim().split(/\s+/).filter(Boolean);
  const first = words.find((word) => !TITLES.has(word.replace(/\.+$/, '').toLowerCase()));
  return (first ?? '').replace(/,+$/, '');
}

/** "Good evening, Abdul!", or "Good evening!" when there is no name yet. */
export function greetingText(name, date = new Date()) {
  const first = firstNameOf(name);
  return first ? `${greetingFor(date)}, ${first}!` : `${greetingFor(date)}!`;
}

/** Milliseconds until the greeting next changes (12:00, 18:00 or 00:00 Manila). */
export function msUntilNextGreeting(date = new Date()) {
  const { hour, minute, second } = manilaClock(date);
  const next = hour < 12 ? 12 : hour < 18 ? 18 : 24;
  const elapsed = ((hour * 60 + minute) * 60 + second) * 1000 + date.getMilliseconds();
  return next * 3600000 - elapsed;
}
