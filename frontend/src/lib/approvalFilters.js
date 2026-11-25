// Program Leader approvals list filters.
//
// The Type options come from the server: GET /api/program/approvals returns
// `types` ([{ value, label }]), the same list it validates `type` against
// (ApprovalRequest::FILTER_TYPES), so the two can never drift apart.

import { failureMessage } from './apiResult.js';

export const ALL_TYPES = 'all';
export const UNSUPPORTED_FILTER = "This filter isn't supported.";
export const LIST_LOAD_ERROR = 'Unable to load approval requests right now.';

/**
 * [value, label] pairs for the Type select: "All types", then the server's
 * types in its order. A selected value the list does not hold (types not
 * loaded yet, or an older link) is kept as an option so the select shows
 * what is being sent.
 */
export function typeOptions(types, selected = ALL_TYPES) {
  const list = Array.isArray(types)
    ? types
      .filter((type) => type && typeof type.value === 'string' && type.value !== '')
      .map((type) => [type.value, typeof type.label === 'string' && type.label ? type.label : type.value])
    : [];
  const options = [[ALL_TYPES, 'All types'], ...list];

  if (selected && !options.some(([value]) => value === selected)) {
    options.push([selected, selected]);
  }

  return options;
}

/**
 * Message for a failed list request. A 422 (a filter the server does not
 * accept) says so and gives the server's field message; other failures use
 * the shared failure rules (no server text for 5xx).
 */
export function approvalListError(error) {
  const status = error?.response?.status ?? null;
  const data = error?.response?.data;

  if (status === 422) {
    const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return first ? `${UNSUPPORTED_FILTER} ${first}` : UNSUPPORTED_FILTER;
  }

  return failureMessage(data, status ?? 0, LIST_LOAD_ERROR);
}
