/**
 * The two funding sources of a line item (R&D Form B): the project's funder
 * and the implementing agency's counterpart. A charge draws from the funder
 * unless the counterpart is chosen, which is offered only on a line item
 * that has a counterpart amount. Labels come from the server (the project's
 * funder short name), never from a built-in funder.
 */

export const FUNDER = 'funder';
export const COUNTERPART = 'implementing_agency';

/** The line item has a counterpart amount, so "Funded by" is offered. */
export function hasCounterpart(item) {
  if (!item) return false;
  if (typeof item.has_counterpart === 'boolean') return item.has_counterpart;
  const amount = item.sources?.[COUNTERPART]?.allocated ?? item.implementing_agency_amount;
  return Number(amount) > 0;
}

/** The source a charge actually uses: the counterpart only where the item has one. */
export function effectiveSource(item, source) {
  return source === COUNTERPART && hasCounterpart(item) ? COUNTERPART : FUNDER;
}

/** Available balance of one source of a balancing row (falls back to the row total). */
export function sourceAvailable(item, source) {
  const bucket = item?.sources?.[effectiveSource(item, source)];
  if (bucket && bucket.available != null) return Number(bucket.available) || 0;
  if (item?.available != null) return Number(item.available) || 0;
  return 0;
}

/** Labels for the two columns / choices; `labels` comes from the API. */
export function sourceLabels(labels) {
  return {
    [FUNDER]: labels?.funder || 'Funder',
    [COUNTERPART]: labels?.implementing_agency || 'Implementing agency',
  };
}

/** Short wording of a source: "DOST" or "MSU counterpart". */
export function sourceName(source, labels) {
  const names = sourceLabels(labels);
  return source === COUNTERPART ? `${names[COUNTERPART]} counterpart` : names[FUNDER];
}
